# Roboflow Server-Side Setup Guide

## ✅ Changes Applied:

1. **Mobile App**: Now only uploads images (no OCR processing)
2. **Server**: Saves images as "pending" when uploaded from mobile
3. **Roboflow**: Moved to server-side (runs when admin clicks "Process Selected")
4. **Process Selected**: Now uses Roboflow + Tesseract for OCR

---

## 🔧 Configuration Required:

### Step 1: Configure Roboflow API Key

**File:** `C:\xampp\htdocs\CAPSTONE\api\roboflow_service.php`

**Line 7:** Update with your Roboflow API key:
```php
define('ROBOFLOW_API_KEY', 'rf_your_actual_api_key_here');
```

**To get your API key:**
1. Go to: https://app.roboflow.com
2. Click your profile icon → Account Settings → API
3. Copy your API key (starts with `rf_...`)

---

### Step 2: Verify Model Version

**File:** `C:\xampp\htdocs\CAPSTONE\api\roboflow_service.php`

**Line 10:** Check if your model version is correct:
```php
define('ROBOFLOW_MODEL_VERSION', '2'); // Or 'instant' for latest
```

**To find your version:**
- Go to: https://app.roboflow.com/watersync/watersync-oekrf
- Check "Deploy" tab for version number

---

## 📋 How It Works Now:

### Mobile App Workflow:
1. User captures meter photo
2. App uploads image to server
3. Server saves image with status = "pending"
4. **NO OCR processing** on upload

### Web Interface Workflow:
1. Admin sees pending images in "Pending OCR Processing" table
2. Admin selects images to process
3. Admin clicks **"Process Selected"** button
4. Server processes each image:
   - **Step 1:** Uses Roboflow to detect meter region → crops image
   - **Step 2:** Uses Tesseract OCR on cropped image
   - **Step 3:** Extracts meter reading
   - **Step 4:** Updates status to "processed" or "failed"

---

## 🎯 Benefits:

✅ **Better OCR Accuracy:**
- Roboflow crops image to meter reading area
- Tesseract processes cleaner, focused image
- Higher success rate

✅ **Server-Side Processing:**
- All processing happens on server (with internet)
- Mobile app doesn't need Roboflow API key
- Easier to manage and update

✅ **Batch Processing:**
- Admin can process multiple images at once
- Better control over OCR processing
- Can review results before creating bills

---

## 🔍 Testing:

### Test Mobile Upload:
1. Capture meter image from mobile app
2. Check "Pending OCR Processing" table
3. Image should appear with status = "pending"

### Test Process Selected:
1. Select pending images
2. Click "Process Selected"
3. Check if status changes to "processed"
4. Verify OCR reading is extracted correctly

---

## 🛠️ Troubleshooting:

### Error: "Roboflow API key not configured"
- **Fix:** Update `ROBOFLOW_API_KEY` in `api/roboflow_service.php`

### Error: "Roboflow API error: HTTP 401"
- **Fix:** Check if API key is correct and has proper permissions

### Error: "Roboflow API error: HTTP 404"
- **Fix:** Check `ROBOFLOW_WORKSPACE`, `ROBOFLOW_PROJECT`, and `ROBOFLOW_MODEL_VERSION`

### Roboflow fails but OCR still works:
- **OK:** System falls back to original image
- Tesseract processes full image instead of cropped

---

## 📝 Files Modified:

1. ✅ `lib/services/api_service.dart` - Updated upload method
2. ✅ `lib/screens/meter_scanner_screen.dart` - Removed Roboflow, simplified upload
3. ✅ `api/upload_reading.php` - Added `process_ocr` flag check
4. ✅ `api/roboflow_service.php` - **NEW** - Server-side Roboflow service
5. ✅ `pending_readings.php` - Updated "Process Selected" to use Roboflow + Tesseract

---

## ✅ Next Steps:

1. **Configure Roboflow API key** in `api/roboflow_service.php`
2. **Test mobile upload** - capture and upload image
3. **Test Process Selected** - process pending images
4. **Verify OCR accuracy** - check if readings are extracted correctly

**Everything is ready! Just configure the API key and test!** 🚀

