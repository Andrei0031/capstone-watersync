# Meter OCR service (PaddleOCR + EasyOCR)

PHP (`api/ocr_functions.php`) sends cropped meter images here after Roboflow detects the region.

## Requirements

- **Linux VPS** (recommended) or Windows Server with Python 3.9+
- ~2 GB disk for models (first run downloads weights)
- CPU is fine; GPU optional

## Install (Ubuntu-style VPS)

```bash
cd /var/www/capstone/paddle_ocr_service   # or your deploy path
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

If `paddlepaddle` fails, follow the official install for your OS:  
https://www.paddlepaddle.org.cn/install/quick

## Run (same machine as PHP)

```bash
source .venv/bin/activate
uvicorn main:app --host 127.0.0.1 --port 8765
```

Keep it running with **systemd** or **supervisor** (example unit below).

## PHP configuration

Set environment variables where Apache/nginx passes them to PHP (or `putenv` in a bootstrap you control):

| Variable | Example | Meaning |
|----------|---------|---------|
| `PADDLE_OCR_SERVICE_URL` | `http://127.0.0.1:8765` | Base URL of this API (no trailing slash) |
| `PADDLE_OCR_SERVICE_API_KEY` | random long string | Optional; if set, PHP sends `X-OCR-Key` and you must set the same here |
| `PADDLE_OCR_SERVICE_ENABLED` | `0` | Set on **PHP-only** hosting to skip Paddle and use Roboflow/OCR.space only |

Optional tuning on the Python side:

- `PADDLE_OCR_HIGH_CONF` (default `0.82`) — below this, result may be flagged `needs_review`

## nginx (optional, if exposing on a subdomain)

Proxy `/` to `127.0.0.1:8765` and use HTTPS + firewall so only your PHP server can reach it.

## systemd example

`/etc/systemd/system/watersync-ocr.service`:

```ini
[Unit]
Description=WaterSync meter OCR
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/capstone/paddle_ocr_service
Environment=PADDLE_OCR_SERVICE_API_KEY=your-secret-if-used
ExecStart=/var/www/capstone/paddle_ocr_service/.venv/bin/uvicorn main:app --host 127.0.0.1 --port 8765
Restart=always

[Install]
WantedBy=multi-user.target
```

Then: `sudo systemctl enable --now watersync-ocr`

## Shared hosting without Python

You cannot run this on typical **PHP-only** shared hosting. Either:

1. Use a **VPS** for the whole app and run this service there, or  
2. Set `PADDLE_OCR_SERVICE_ENABLED=0` in PHP and rely on Roboflow digit model + OCR.space + Tesseract.

## Health check

`GET http://127.0.0.1:8765/health` → `{"ok":true,...}`
