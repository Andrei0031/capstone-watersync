# Configure Roboflow API - Quick Setup

## 🔍 Current Status:
- ✅ Roboflow is **ENABLED** in the app (`_useRoboflowDetection = true`)
- ❌ API Key is **NOT CONFIGURED** (still shows `'YOUR_ROBOFLOW_API_KEY'`)
- ⚠️ Roboflow calls are **FAILING SILENTLY** and falling back to original image

---

## 🚀 Quick Setup Steps:

### Step 1: Get Your Roboflow API Key

1. **Go to Roboflow Dashboard:**
   - Visit: https://app.roboflow.com
   - Login to your account

2. **Get API Key:**
   - Click your **profile icon** (top right)
   - Go to **"Account Settings"** → **"API"** tab
   - Copy your **API Key** (starts with `rf_...`)

   **OR**

   - Go to: https://app.roboflow.com/watersync/watersync-oekrf/settings
   - Scroll to **"API"** section
   - Copy your **API Key**

---

### Step 2: Update the Code

**File:** `lib/services/roboflow_service.dart`

**Line 11:** Replace this:
```dart
static const String apiKey = 'YOUR_ROBOFLOW_API_KEY';
```

**With your actual API key:**
```dart
static const String apiKey = 'rf_your_actual_api_key_here';
```

---

### Step 3: Check Model Version (Optional)

**Line 19:** Your model shows `watersync-oekrf-instant-2`

If your model version is different, update:
```dart
static const String modelVersion = '2'; // Or 'instant' for latest
```

**To find your exact version:**
1. Go to: https://app.roboflow.com/watersync/watersync-oekrf
2. Click **"Deploy"** tab
3. Check the version number in the URL or deployment settings

---

### Step 4: Test It

1. **Save the file**
2. **Hot restart** your app (or rebuild)
3. **Capture a meter image**
4. **Check logs** for:
   ```
   DEBUG ROBOFLOW: Starting detection for image: ...
   DEBUG ROBOFLOW: Found meter region: ...
   DEBUG ROBOFLOW: Cropped image saved to: ...
   ```

---

## 🔍 How to Verify It's Working:

### ✅ **If Roboflow is Working:**
- You'll see: **"Detecting meter region with AI..."** message
- Then: **"✓ Meter region detected and cropped"** message
- The image sent to server will be **cropped** (just the meter reading area)

### ❌ **If Roboflow is NOT Working:**
- You'll see: **"Detecting meter region with AI..."** message
- But NO success message
- The **full original image** is sent to server (not cropped)
- Check logs for: `DEBUG ROBOFLOW: API error: 401` (invalid API key)

---

## 🛠️ Troubleshooting:

### Error: "Roboflow API error: 401"
- **Problem:** Invalid API key
- **Fix:** Double-check your API key is correct

### Error: "Roboflow API error: 404"
- **Problem:** Wrong project name or version
- **Fix:** Check `workspace`, `project`, and `modelVersion` match your dashboard

### No Error, But No Detection
- **Problem:** API key might be correct but model not detecting
- **Fix:** Check if meter is clearly visible in image, or try different image

---

## 📝 Current Configuration:

**File:** `lib/services/roboflow_service.dart`

```dart
static const String apiKey = 'YOUR_ROBOFLOW_API_KEY'; // ⚠️ NEEDS UPDATE
static const String workspace = 'watersync';          // ✅ Correct
static const String project = 'watersync-oekrf';      // ✅ Correct  
static const String modelVersion = '1';               // ⚠️ Check if correct
```

---

## 🎯 What Happens Now:

**Without API Key (Current State):**
1. App tries to call Roboflow
2. Roboflow API returns 401 (unauthorized)
3. App silently falls back to original image
4. Full image sent to server for OCR

**With API Key (After Setup):**
1. App calls Roboflow with valid API key
2. Roboflow detects meter region (97% confidence)
3. Image is cropped to just reading area
4. Cropped image sent to server
5. **Better OCR accuracy!** 🎉

---

## ⚡ Quick Fix:

**Just update line 11 in `lib/services/roboflow_service.dart`:**

```dart
static const String apiKey = 'rf_paste_your_key_here';
```

**Then hot restart the app!**

---

**Need help finding your API key?** Let me know and I can guide you through it! 🚀

