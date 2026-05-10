"""
EasyOCR microservice for meter crops (Roboflow detects region in PHP; send crop here).

Run: uvicorn main:app --host 127.0.0.1 --port 8766
Env: EASYOCR_SERVICE_API_KEY (optional), EASYOCR_HIGH_CONF (default 0.78)
"""
from __future__ import annotations

import os
from typing import Any, Dict, List, Optional, Tuple

import cv2
import numpy as np
from fastapi import FastAPI, File, Header, HTTPException, UploadFile

app = FastAPI(title="WaterSync EasyOCR", version="1.0.0")

_reader = None
API_KEY = os.environ.get("EASYOCR_SERVICE_API_KEY", "").strip()
HIGH_CONF = float(os.environ.get("EASYOCR_HIGH_CONF", "0.78"))


def digits_only(text: str) -> str:
    return "".join(c for c in text if c.isdigit())


def extract_five(digits: str) -> Optional[str]:
    if not digits or len(digits) < 5:
        return None
    return digits[-5:]


def _get_reader():
    global _reader
    if _reader is None:
        import easyocr

        _reader = easyocr.Reader(["en"], gpu=False, verbose=False)
    return _reader


def _decode(data: bytes) -> np.ndarray:
    arr = np.asarray(bytearray(data), dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError("Could not decode image")
    return img


def preprocess(img_bgr: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
    h, w = gray.shape[:2]
    if min(h, w) < 480:
        gray = cv2.resize(
            gray, (int(w * 2), int(h * 2)), interpolation=cv2.INTER_CUBIC
        )
    clahe = cv2.createCLAHE(clipLimit=2.5, tileGridSize=(8, 8))
    gray = clahe.apply(gray)
    k = np.array([[-1, -1, -1], [-1, 9, -1], [-1, -1, -1]], dtype=np.float32)
    sharp = cv2.filter2D(gray, -1, k)
    denoised = cv2.fastNlMeansDenoising(sharp, h=10)
    return cv2.cvtColor(denoised, cv2.COLOR_GRAY2BGR)


def _sort_lines(items: List[Tuple[Any, str, float]]) -> List[Tuple[Any, str, float]]:
    if not items:
        return []

    def cy(box):
        if box is None:
            return 0.0
        a = np.array(box, dtype=np.float32)
        return float(np.mean(a[:, 1]))

    def mx(box):
        if box is None:
            return 0.0
        a = np.array(box, dtype=np.float32)
        return float(np.min(a[:, 0]))

    return sorted(items, key=lambda t: (cy(t[0]), mx(t[0])))


def run_easy(img_bgr: np.ndarray, label: str) -> Dict[str, Any]:
    r = _get_reader()
    rgb = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2RGB)
    det = r.readtext(rgb)
    lines: List[Tuple[Any, str, float]] = []
    for row in det:
        if len(row) < 3:
            continue
        lines.append((row[0], str(row[1]), float(row[2])))
    lines = _sort_lines(lines)
    raw = " ".join(t[1].strip() for t in lines if t[1].strip())
    d = digits_only(raw)
    reading = extract_five(d)
    confs = [t[2] for t in lines]
    mn = float(min(confs)) if confs else 0.0
    av = float(sum(confs) / len(confs)) if confs else 0.0
    return {
        "label": label,
        "raw_text": raw,
        "digits_only": d,
        "reading": reading,
        "min_confidence": mn,
        "avg_confidence": av,
        "lines": [{"text": t[1], "confidence": round(t[2], 4)} for t in lines],
    }


def _check_key(x: Optional[str]) -> None:
    if not API_KEY:
        return
    if not x or x != API_KEY:
        raise HTTPException(status_code=401, detail="Invalid or missing X-OCR-Key")


@app.get("/health")
def health():
    return {"ok": True, "service": "easyocr_meter"}


@app.post("/meter-ocr")
async def meter_ocr(
    file: UploadFile = File(...),
    x_ocr_key: Optional[str] = Header(default=None, alias="X-OCR-Key"),
):
    try:
        _check_key(x_ocr_key)
    except HTTPException as e:
        d = e.detail
        return {"success": False, "meter_reading": None, "error": d if isinstance(d, str) else str(d)}

    body = await file.read()
    if not body:
        return {"success": False, "meter_reading": None, "error": "Empty file"}

    try:
        img = _decode(body)
    except ValueError as e:
        return {"success": False, "meter_reading": None, "error": str(e)}

    passes: List[Dict[str, Any]] = []
    errors: List[str] = []
    for name, im in [("original", img), ("preprocessed", preprocess(img))]:
        try:
            passes.append(run_easy(im, name))
        except Exception as ex:  # noqa: BLE001
            errors.append(f"{name}:{ex!s}")

    with_r = [p for p in passes if p.get("reading")]
    best = None
    if with_r:
        with_r.sort(
            key=lambda p: (p["avg_confidence"], p["min_confidence"]),
            reverse=True,
        )
        best = with_r[0]

    if not best or not best.get("reading"):
        msg = "Could not extract 5 digits"
        if errors:
            msg += ". " + "; ".join(errors[:2])
        return {
            "success": False,
            "meter_reading": None,
            "error": msg,
            "passes": passes,
        }

    mn = float(best.get("min_confidence") or 0.0)
    needs_review = bool(mn < HIGH_CONF or best.get("reading") in (None, "00000"))
    return {
        "success": True,
        "meter_reading": best["reading"],
        "raw_text": best.get("raw_text", ""),
        "digits_only": best.get("digits_only", ""),
        "engine": "easyocr",
        "preprocess_pass": best.get("label"),
        "min_confidence": round(mn, 4),
        "avg_confidence": round(float(best.get("avg_confidence") or 0.0), 4),
        "passes": passes,
        "needs_review": needs_review,
    }
