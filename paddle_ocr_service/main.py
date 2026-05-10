"""
Local OCR microservice: PaddleOCR (primary) + EasyOCR (fallback).
Run: uvicorn main:app --host 127.0.0.1 --port 8765
"""
from __future__ import annotations

import io
import os
import re
from typing import Any, Dict, List, Optional, Tuple

import cv2
import numpy as np
from fastapi import FastAPI, File, Header, HTTPException, UploadFile

app = FastAPI(title="WaterSync Paddle OCR", version="1.0.0")

_paddle_ocr = None
_easy_reader = None

SERVICE_API_KEY = os.environ.get("PADDLE_OCR_SERVICE_API_KEY", "").strip()
HIGH_CONF = float(os.environ.get("PADDLE_OCR_HIGH_CONF", "0.82"))
LOW_CONF = float(os.environ.get("PADDLE_OCR_LOW_CONF", "0.55"))


def _get_paddle():
    global _paddle_ocr
    if _paddle_ocr is None:
        from paddleocr import PaddleOCR

        _paddle_ocr = PaddleOCR(use_angle_cls=True, lang="en", show_log=False)
    return _paddle_ocr


def _get_easy():
    global _easy_reader
    if _easy_reader is None:
        import easyocr

        _easy_reader = easyocr.Reader(["en"], gpu=False, verbose=False)
    return _easy_reader


def _decode_upload(data: bytes) -> np.ndarray:
    arr = np.frombuffer(data, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError("Could not decode image")
    return img


def _sort_boxes_lines(items: List[Tuple[Any, str, float]]) -> List[Tuple[Any, str, float]]:
    if not items:
        return []

    def center_y(box):
        if box is None:
            return 0.0
        arr = np.array(box, dtype=np.float32)
        return float(np.mean(arr[:, 1]))

    def min_x(box):
        if box is None:
            return 0.0
        arr = np.array(box, dtype=np.float32)
        return float(np.min(arr[:, 0]))

    # Group into lines by y, then sort x
    items_sorted = sorted(items, key=lambda t: (center_y(t[0]), min_x(t[0])))
    return items_sorted


def _merge_text(lines: List[Tuple[Any, str, float]]) -> str:
    parts = [t[1].strip() for t in lines if t[1] and t[1].strip()]
    return " ".join(parts)


def _extract_five_digit(text: str) -> Optional[str]:
    if not text:
        return None
    normalized = re.sub(r"[^\d\s]", "", text)
    normalized = re.sub(r"\s+", "", normalized)
    if not normalized:
        digits = re.sub(r"\D", "", text)
    else:
        digits = normalized
    if len(digits) < 5:
        return None
    # Prefer last 5 if longer (odometer often right-aligned in crops)
    return digits[-5:]


def _stats_from_lines(lines: List[Tuple[Any, str, float]]) -> Tuple[float, float]:
    confs = [t[2] for t in lines if t[2] is not None]
    if not confs:
        return 0.0, 0.0
    return float(min(confs)), float(sum(confs) / len(confs))


def preprocess_meter_bgr(img_bgr: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
    h, w = gray.shape[:2]
    scale = 2.0 if min(h, w) < 480 else 1.5
    if scale > 1.0:
        gray = cv2.resize(
            gray, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_CUBIC
        )
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    gray = clahe.apply(gray)
    kernel = np.array([[-1, -1, -1], [-1, 9, -1], [-1, -1, -1]], dtype=np.float32)
    sharp = cv2.filter2D(gray, -1, kernel)
    denoised = cv2.fastNlMeansDenoising(sharp, h=10)
    # Edge emphasis (unsharp)
    blur = cv2.GaussianBlur(denoised, (0, 0), sigmaX=1.0)
    edges = cv2.addWeighted(denoised, 1.4, blur, -0.4, 0)
    return cv2.cvtColor(edges, cv2.COLOR_GRAY2BGR)


def preprocess_adaptive_bgr(img_bgr: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
    h, w = gray.shape[:2]
    if min(h, w) < 480:
        gray = cv2.resize(
            gray, (int(w * 2), int(h * 2)), interpolation=cv2.INTER_CUBIC
        )
    ath = cv2.adaptiveThreshold(
        gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 31, 12
    )
    return cv2.cvtColor(ath, cv2.COLOR_GRAY2BGR)


def _paddle_on_image(img_bgr: np.ndarray, label: str) -> Dict[str, Any]:
    ocr = _get_paddle()
    result = ocr.ocr(img_bgr, cls=True)
    lines: List[Tuple[Any, str, float]] = []
    if not result or result[0] is None:
        return {
            "label": label,
            "lines": [],
            "raw_text": "",
            "reading": None,
            "min_confidence": 0.0,
            "avg_confidence": 0.0,
        }

    for item in result[0]:
        if not item or len(item) < 2:
            continue
        box, rec = item[0], item[1]
        if isinstance(rec, (list, tuple)) and len(rec) >= 2:
            text, conf = rec[0], float(rec[1])
        else:
            continue
        lines.append((box, str(text), conf))

    lines = _sort_boxes_lines(lines)
    raw_text = _merge_text(lines)
    reading = _extract_five_digit(raw_text)
    mn, av = _stats_from_lines(lines)
    return {
        "label": label,
        "lines": [
            {"text": t[1], "confidence": round(t[2], 4)} for t in lines
        ],
        "raw_text": raw_text,
        "reading": reading,
        "min_confidence": mn,
        "avg_confidence": av,
    }


def _easy_on_image(img_bgr: np.ndarray, label: str) -> Dict[str, Any]:
    reader = _get_easy()
    # easyocr expects RGB or path
    rgb = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2RGB)
    detections = reader.readtext(rgb)
    lines: List[Tuple[Any, str, float]] = []
    for det in detections:
        if len(det) < 3:
            continue
        box, text, conf = det[0], det[1], float(det[2])
        lines.append((box, str(text), conf))
    lines = _sort_boxes_lines(lines)
    raw_text = _merge_text(lines)
    reading = _extract_five_digit(raw_text)
    mn, av = _stats_from_lines(lines)
    return {
        "label": label,
        "lines": [{"text": t[1], "confidence": round(t[2], 4)} for t in lines],
        "raw_text": raw_text,
        "reading": reading,
        "min_confidence": mn,
        "avg_confidence": av,
    }


def _choose_candidate(pass_results: List[Dict[str, Any]]) -> Tuple[Optional[Dict[str, Any]], bool]:
    """Pick best pass; set disagreement if two strong candidates differ."""
    candidates = [p for p in pass_results if p.get("reading")]
    if not candidates:
        return None, False
    # Prefer highest avg confidence
    candidates.sort(key=lambda p: (p["avg_confidence"], p["min_confidence"]), reverse=True)
    best = candidates[0]
    if len(candidates) > 1:
        second = candidates[1]
        if (
            second["reading"] != best["reading"]
            and second["avg_confidence"] >= LOW_CONF
            and best["avg_confidence"] >= LOW_CONF
        ):
            return best, True
    return best, False


def _check_api_key(x_ocr_key: Optional[str]) -> None:
    if not SERVICE_API_KEY:
        return
    if not x_ocr_key or x_ocr_key != SERVICE_API_KEY:
        raise HTTPException(status_code=401, detail="Invalid or missing X-OCR-Key")


@app.get("/health")
def health():
    return {"status": "ok", "service": "paddle_ocr_service"}


@app.post("/meter-ocr")
async def meter_ocr(
    file: UploadFile = File(...),
    x_ocr_key: Optional[str] = Header(default=None, alias="X-OCR-Key"),
):
    try:
        _check_api_key(x_ocr_key)
    except HTTPException as e:
        return {
            "success": False,
            "meter_reading": None,
            "error": e.detail if isinstance(e.detail, str) else str(e.detail),
        }

    data = await file.read()
    if not data:
        return {
            "success": False,
            "meter_reading": None,
            "error": "Empty file",
        }

    try:
        img = _decode_upload(data)
    except ValueError as e:
        return {
            "success": False,
            "meter_reading": None,
            "error": str(e),
        }

    variants: List[Tuple[str, np.ndarray]] = [
        ("original", img),
        ("preprocessed", preprocess_meter_bgr(img)),
        ("adaptive", preprocess_adaptive_bgr(img)),
    ]

    pass_results: List[Dict[str, Any]] = []
    paddle_errors: List[str] = []
    for label, vimg in variants:
        try:
            pass_results.append(_paddle_on_image(vimg, label))
        except Exception as ex:  # noqa: BLE001
            paddle_errors.append(f"{label}:{ex!s}")

    best, disagree = _choose_candidate(pass_results)
    easy_used = False
    easy_result: Optional[Dict[str, Any]] = None

    def build_response(
        chosen: Dict[str, Any],
        *,
        engine: str,
        disagreement: bool,
    ) -> Dict[str, Any]:
        reading = chosen.get("reading")
        min_c = float(chosen.get("min_confidence") or 0.0)
        avg_c = float(chosen.get("avg_confidence") or 0.0)
        low_conf = min_c < HIGH_CONF
        needs_review = bool(
            disagreement or low_conf or reading in (None, "", "00000")
        )
        return {
            "success": reading is not None,
            "meter_reading": reading,
            "raw_text": chosen.get("raw_text", ""),
            "engine": engine,
            "preprocess_pass": chosen.get("label"),
            "min_confidence": round(min_c, 4),
            "avg_confidence": round(avg_c, 4),
            "passes": pass_results,
            "easyocr_used": easy_used,
            "easyocr": easy_result,
            "disagreement": disagreement,
            "needs_review": needs_review,
            "paddle_errors": paddle_errors,
        }

    if best and not disagree:
        br = build_response(best, engine="paddle", disagreement=False)
        if br["success"] and not br["needs_review"]:
            return br
        # Low confidence: try EasyOCR on preprocessed image
        try:
            easy_result = _easy_on_image(variants[1][1], "easyocr_preprocessed")
            easy_used = True
            if easy_result.get("reading"):
                er = easy_result
                er_min = float(er.get("min_confidence") or 0.0)
                if er["reading"] == best["reading"] or er_min >= min(
                    float(best.get("min_confidence") or 0.0), er_min
                ):
                    return build_response(er, engine="easyocr", disagreement=False)
        except Exception:  # noqa: BLE001
            pass
        return br

    if best and disagree:
        # Validation: EasyOCR on preprocessed
        try:
            easy_result = _easy_on_image(variants[1][1], "easyocr_preprocessed")
            easy_used = True
            er = easy_result
            if er.get("reading"):
                if er["reading"] == best["reading"]:
                    return build_response(er, engine="easyocr", disagreement=False)
                # Tie-break: higher avg confidence
                if float(er["avg_confidence"]) > float(best["avg_confidence"]):
                    return build_response(er, engine="easyocr", disagreement=False)
        except Exception:  # noqa: BLE001
            pass
        br = build_response(best, engine="paddle", disagreement=True)
        return br

    # Paddle failed all passes — EasyOCR only
    try:
        easy_result = _easy_on_image(variants[1][1], "easyocr_preprocessed")
        easy_used = True
        if easy_result.get("reading"):
            return build_response(easy_result, engine="easyocr", disagreement=False)
    except Exception as ex:  # noqa: BLE001
        return {
            "success": False,
            "meter_reading": None,
            "error": f"EasyOCR failed: {ex!s}",
            "paddle_errors": paddle_errors,
        }

    msg = "Could not extract 5-digit reading"
    if paddle_errors:
        msg += ". Paddle errors: " + "; ".join(paddle_errors[:3])
    return {
        "success": False,
        "meter_reading": None,
        "error": msg,
        "passes": pass_results,
        "paddle_errors": paddle_errors,
    }
