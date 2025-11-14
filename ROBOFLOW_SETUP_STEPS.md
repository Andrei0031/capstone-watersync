# Roboflow Setup - Complete Guide

## ✅ Current Status:
- ✅ Roboflow model trained: **"Watersync Object Detection"**
- ✅ Model detects: `WaterMeter` (97%) and `metercube` (95-96%)
- ✅ Flutter code already integrated
- ⚠️ **Need to configure:** API Key and Model Version

---

## 🔑 Step 1: Get Your Roboflow API Key

### Option A: From Project Settings
1. Go to: https://app.roboflow.com/watersync/watersync-oekrf
2. Click **"Settings"** (gear icon) in the left sidebar
3. Scroll to **"API"** section
4. Copy your **API Key** (starts with `rf_...`)

### Option B: From Account Settings
1. Go to: https://app.roboflow.com
2. Click your **profile icon** (top right)
3. Select **"Account Settings"**
4. Go to **"API"** tab
5. Copy your **API Key**

---

## 📋 Step 2: Get Your Model Version

### Check Model Version:
1. Go to: https://app.roboflow.com/watersync/watersync-oekrf
2. Click **"Deploy"** tab (left sidebar)
3. Look for **"Roboflow Instant"** section
4. Your model URL shows: `watersync/watersync-oekrf-instant-2`
5. The version might be:
   - **Version number:** `1`, `2`, `3`, etc.
   - **Or use:** `instant` (for latest instant model)

### How to Find Exact Version:
- Look at the **"Model URL"** in your Roboflow dashboard
- If it says `instant-2`, try version `2` or `instant`
- Check the **"Versions"** tab to see all trained versions

---

## 🔧 Step 3: Update Flutter Code

### Edit: `lib/services/roboflow_service.dart`

**Line 10:** Replace with your API key
```dart
static const String apiKey = 'rf_your_actual_api_key_here';
```

**Line 13:** Update model version (if needed)
```dart
static const String modelVersion = '2'; // Or 'instant' for latest
```

**Save the file!**

---

## 🧪 Step 4: Test the Integration

### Test in Mobile App:
1. **Open your Flutter app**
2. **Navigate to:** Scan Meter screen
3. **Capture a meter image**
4. **Check logs** for:
   ```
   DEBUG ROBOFLOW: Starting detection for image: ...
   DEBUG ROBOFLOW: Found meter region: ...
   DEBUG ROBOFLOW: Cropped image saved to: ...
   ```

### Expected Behavior:
1. ✅ **"Detecting meter region with AI..."** message appears
2. ✅ **"✓ Meter region detected and cropped"** success message
3. ✅ Cropped image is sent to server for OCR
4. ✅ OCR accuracy should improve!

---

## 🔍 Step 5: Verify It's Working

### Check Logs:
Look for these debug messages:
- ✅ `DEBUG ROBOFLOW: Sending request to: https://detect.roboflow.com/...`
- ✅ `DEBUG ROBOFLOW: Response status: 200`
- ✅ `DEBUG ROBOFLOW: Found meter region: {...}`
- ✅ `DEBUG ROBOFLOW: Cropped image saved to: ...`

### If You See Errors:

**Error: "Roboflow API error: 401"**
- ❌ API key is incorrect
- **Fix:** Double-check your API key

**Error: "Roboflow API error: 404"**
- ❌ Model version or project name is wrong
- **Fix:** Check project name and version number

**Error: "No water meter region detected"**
- ⚠️ Model didn't detect meter (might be image quality)
- **OK:** App will use original image (fallback works)

---

## 📱 How It Works Now:

```
1. User captures meter photo
   ↓
2. Roboflow detects "WaterMeter" region (97% confidence)
   ↓
3. Image is cropped to just the reading area
   ↓
4. Cropped image is preprocessed (contrast, brightness)
   ↓
5. Preprocessed image sent to server
   ↓
6. Server runs Tesseract OCR on cropped image
   ↓
7. Reading extracted: "00792"
   ↓
8. Display result to user
```

---

## ✅ Benefits:

🎯 **Better OCR Accuracy:**
- Focuses on meter reading area only
- Less noise from surrounding parts
- Tesseract processes cleaner image

🤖 **Automatic Detection:**
- No manual cropping needed
- Works with different meter types
- Handles various angles/lighting

🛡️ **Safe Fallback:**
- If Roboflow fails, uses original image
- App continues working normally
- No breaking changes

---

## 🚀 Next Steps:

1. ✅ **Get your API key** from Roboflow
2. ✅ **Update** `roboflow_service.dart` with API key
3. ✅ **Test** with a meter image
4. ✅ **Monitor** OCR accuracy improvement

---

## 📞 Need Help?

If you're stuck:
1. Check Roboflow dashboard for correct credentials
2. Verify internet connection (Roboflow needs API access)
3. Check Flutter logs for specific error messages
4. Make sure API key has proper permissions

**Ready to configure?** Update the API key in `roboflow_service.dart` and test! 🎉

