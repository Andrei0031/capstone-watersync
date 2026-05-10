# EasyOCR service (pairs with Roboflow in PHP)

**PHP / Roboflow:** `detectAndCropMeterWithRoboflow()` finds the meter window.  
**This service:** reads digits from that crop (or register crops) via EasyOCR.

## Run locally / on VPS

```bash
cd easyocr_service
python3 -m venv .venv
source .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements.txt
uvicorn main:app --host 127.0.0.1 --port 8766
```

First run downloads EasyOCR models (~100MB+).

## PHP environment

| Variable | Example |
|----------|---------|
| `EASYOCR_SERVICE_URL` | `http://127.0.0.1:8766` |
| `EASYOCR_SERVICE_ENABLED` | `0` to skip (e.g. Namecheap shared hosting with no Python) |
| `EASYOCR_SERVICE_API_KEY` | Optional; must match `EASYOCR_SERVICE_API_KEY` here |

## Shared hosting

Namecheap **shared** plans usually **cannot** run this. Set **`EASYOCR_SERVICE_ENABLED=0`** and rely on Roboflow digits + OCR.space + Tesseract, or run this on a **VPS** and point `EASYOCR_SERVICE_URL` at it.

## Health

`GET /health`
