# Verpex Deployment - Summary

## 🎯 Quick Steps to Connect Mobile App

### Step 1: Upload Files to Verpex
1. Upload all files from `C:\xampp\htdocs\CAPSTONE\` to Verpex
2. Maintain folder structure (especially `api/` folder)
3. Update `db.php` with Verpex database credentials

### Step 2: Update Mobile App Base URL
Change from:
```dart
static const String baseUrl = 'http://localhost/CAPSTONE/api/';
```

To:
```dart
static const String baseUrl = 'https://yourdomain.com/api/';
```

### Step 3: Test Connection
Test these endpoints:
- `https://yourdomain.com/api/auth.php` (POST)
- `https://yourdomain.com/api/mobile_client_list.php` (GET)
- `https://yourdomain.com/api/mobile_meter_reading.php` (POST)

---

## 📋 Main API Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/auth.php` | POST | Login |
| `/api/mobile_client_list.php` | GET | Get clients |
| `/api/mobile_meter_reading.php` | POST | Submit reading |
| `/api/ocr_meter_reading.php` | POST | OCR processing |

---

## 🔑 Authentication

**Header Required:**
```
Authorization: Bearer watersync_mobile_2024_new_malitbog
```

---

## ✅ Checklist

### Server (Verpex)
- [ ] Files uploaded
- [ ] Database configured
- [ ] SSL enabled (HTTPS)
- [ ] File permissions set
- [ ] API tested

### Mobile App
- [ ] Base URL updated
- [ ] HTTPS enabled
- [ ] Authentication configured
- [ ] Tested connection

---

## 📚 Full Guides

- **Detailed Guide**: See `VERPEX_MOBILE_CONNECTION_GUIDE.md`
- **Quick Reference**: See `MOBILE_APP_CONFIG_QUICK_REFERENCE.md`
- **API Documentation**: See `api/README.md`

---

**Replace `yourdomain.com` with your actual Verpex domain!**

