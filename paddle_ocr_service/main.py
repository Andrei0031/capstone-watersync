"""
Roboflow (PHP) crops the meter → this service runs PaddleOCR + EasyOCR on the crop.

Run on your VPS: uvicorn main:app --host 127.0.0.1 --port 8765
Put behind nginx or set PADDLE_OCR_SERVICE_URL in PHP.
"""
from __future__ import annotations

import os
from typing import Any, Dict, List, Optional, Tuple

import cv2
import numpy as np
from fastapi import FastAPI, File, Header, HTTPException, UploadFile

app = FastAPI(title="WaterSync Meter OCR", version="2.0.0")

_paddle = None
_easy: Any = None

API_KEY = os.environ.get("PADDLE_OCR_SERVICE_API_KEY", "").strip()
HIGH_CONF = float(os.environ.get("PADDLE_OCR_HIGH_CONF", "0.82"))


def digits_only(text: str) -> str:
    """Keep 0-9 only (best practice for numeric meter windows)."""
    return "".join(c for c in text if c.isdigit())


def extract_five_digit_from_digits(digits: str) -> Optional[str]:
    if not digits or len(digits) < 5:
        return None
    return digits[-5:]


def _get_paddle():
    global _paddle
    if _paddle is None:
        from paddleocr import PaddleOCR

        _paddle = PaddleOCR(use_angle_cls=True, lang="en", show_log=False)
    return _paddle


def _get_easy():
    global _easy
    if _easy is None:
        import easyocr

        _easy = easyocr.Reader(["en"], gpu=False, verbose=False)
    return _easy


def _decode_image(data: bytes) -> np.ndarray:
    arr = np.frombuffer(data, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError("Could not decode image")
    return img


def preprocess_meter(img_bgr: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
    h, w = gray.shape[:2]
    scale = 2.0 if min(h, w) < 500 else 1.5
    if scale > 1.0:
        gray = cv2.resize(
            gray, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_CUBIC
        )
    clahe = cv2.createCLAHE(clipLimit=2.5, tileGridSize=(8, 8))
    gray = clahe.apply(gray)
    kernel = np.array([[-1, -1, -1], [-1, 9, -1], [-1, -1, -1]], dtype=np.float32)
    sharp = cv2.filter2D(gray, -1, kernel)
    denoised = cv2.fastNlMeansDenoising(sharp, h=10)
    blur = cv2.GaussianBlur(denoised, (0, 0), sigmaX=1.0)
    enhanced = cv2.addWeighted(denoised, 1.35, blur, -0.35, 0)
    return cv2.cvtColor(enhanced, cv2.COLOR_GRAY2BGR)


def preprocess_threshold(img_bgr: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
    h, w = gray.shape[:2]
    if min(h, w) < 500:
        gray = cv2.resize(
            gray, (int(w * 2), int(h * 2)), interpolation=cv2.INTER_CUBIC
        )
    bw = cv2.adaptiveThreshold(
        gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 31, 10
    )
    return cv2.cvtColor(bw, cv2.COLOR_GRAY2BGR)


def _sort_lines(
    items: List[Tuple[Any, str, float]]
) -> List[Tuple[Any, str, float]]:
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


def _paddle_run(img_bgr: np.ndarray, label: str) -> Dict[str, Any]:
    ocr = _get_paddle()
    out = ocr.ocr(img_bgr, cls=True)
    lines: List[Tuple[Any, str, float]] = []
    if out and out[0]:
        for item in out[0]:
            if not item or len(item) < 2:
                continue
            box, rec = item[0], item[1]
            if isinstance(rec, (list, tuple)) and len(rec) >= 2:
                txt, conf = str(rec[0]), float(rec[1])
                lines.append((box, txt, conf))
    lines = _sort_lines(lines)
    raw = " ".join(t[1].strip() for t in lines if t[1].strip())
    d = digits_only(raw)
    reading = extract_five_digit_from_digits(d)
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


def _easy_run(img_bgr: np.ndarray, label: str) -> Dict[str, Any]:
    r = _get_easy()
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
    reading = extract_five_digit_from_digits(d)
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


def _check_key(x_ocr_key: Optional[str]) -> None:
    if not API_KEY:
        return
    if not x_ocr_key or x_ocr_key != API_KEY:
        raise HTTPException(status_code=401, detail="Invalid or missing X-OCR-Key")


def _response(
    chosen: Dict[str, Any],
    engine: str,
    *,
    disagreement: bool,
    passes: List[Dict[str, Any]],
    paddle_errors: List[str],
    easy_used: bool,
) -> Dict[str, Any]:
    reading = chosen.get("reading")
    mn = float(chosen.get("min_confidence") or 0.0)
    av = float(chosen.get("avg_confidence") or 0.0)
    low = mn < HIGH_CONF
    needs_review = bool(
        disagreement or low or reading in (None, "", "00000")
    )
    return {
        "success": reading is not None,
        "meter_reading": reading,
        "raw_text": chosen.get("raw_text", ""),
        "digits_only": chosen.get("digits_only", ""),
        "engine": engine,
        "preprocess_pass": chosen.get("label"),
        "min_confidence": round(mn, 4),
        "avg_confidence": round(av, 4),
        "passes": passes,
        "easyocr_used": easy_used,
        "disagreement": disagreement,
        "needs_review": needs_review,
        "paddle_errors": paddle_errors,
    }


@app.get("/health")
def health():
    return {"ok": True, "service": "meter_ocr"}


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
        img = _decode_image(body)
    except ValueError as e:
        return {"success": False, "meter_reading": None, "error": str(e)}

    variants: List[Tuple[str, np.ndarray]] = [
        ("original", img),
        ("preprocessed", preprocess_meter(img)),
        ("adaptive_threshold", preprocess_threshold(img)),
    ]

    passes: List[Dict[str, Any]] = []
    paddle_errors: List[str] = []
    for name, im in variants:
        try:
            passes.append(_paddle_run(im, name))
        except Exception as ex:  # noqa: BLE001
            paddle_errors.append(f"{name}:{ex!s}")

    with_reading = [p for p in passes if p.get("reading")]
    disagree = False
    best: Optional[Dict[str, Any]] = None
    if len(with_reading) >= 2:
        readings = {p["reading"] for p in with_reading}
        if len(readings) > 1:
            disagree = True
            with_reading.sort(
                key=lambda p: (p["avg_confidence"], p["min_confidence"]),
                reverse=True,
            )
            best = with_reading[0]
        else:
            with_reading.sort(
                key=lambda p: (p["avg_confidence"], p["min_confidence"]),
                reverse=True,
            )
            best = with_reading[0]
    elif len(with_reading) == 1:
        best = with_reading[0]
    else:
        best = None

    easy_used = False

    def try_easy(on: np.ndarray) -> Optional[Dict[str, Any]]:
        nonlocal easy_used
        try:
            easy_used = True
            return _easy_run(on, "easyocr_preprocessed")
        except Exception:  # noqa: BLE001
            return None

    if best and not disagree:
        mn = float(best.get("min_confidence") or 0)
        if mn >= HIGH_CONF and best.get("reading"):
            return _response(
                best, "paddle", disagreement=False, passes=passes, paddle_errors=paddle_errors, easy_used=False
            )
        er = try_easy(variants[1][1])
        if er and er.get("reading"):
            if er["reading"] == best["reading"]:
                return _response(
                    er, "easyocr", disagreement=False, passes=passes, paddle_errors=paddle_errors, easy_used=True
                )
            if float(er["avg_confidence"]) > float(best["avg_confidence"]):
                return _response(
                    er, "easyocr", disagreement=False, passes=passes, paddle_errors=paddle_errors, easy_used=True
                )
        return _response(
            best, "paddle", disagreement=False, passes=passes, paddle_errors=paddle_errors, easy_used=easy_used
        )

    if best and disagree:
        er = try_easy(variants[1][1])
        if er and er.get("reading"):
            if er["reading"] in {p["reading"] for p in with_reading if p.get("reading")}:
                return _response(
                    er, "easyocr", disagreement=False, passes=passes, paddle_errors=paddle_errors, easy_used=True
                )
            if float(er["avg_confidence"]) > float(best["avg_confidence"]):
                return _response(
                    er, "easyocr", disagreement=False, passes=passes, paddle_errors=paddle_errors, easy_used=True
                )
        return _response(
            best, "paddle", disagreement=True, passes=passes, paddle_errors=paddle_errors, easy_used=easy_used
        )

    er = try_easy(variants[1][1])
    if er and er.get("reading"):
        return _response(
            er, "easyocr", disagreement=False, passes=passes, paddle_errors=paddle_errors, easy_used=True
        )

    msg = "Could not extract 5 digits after OCR"
    if paddle_errors:
        msg += ". " + "; ".join(paddle_errors[:2])
    return {
        "success": False,
        "meter_reading": None,
        "error": msg,
        "passes": passes,
        "paddle_errors": paddle_errors,
    }
