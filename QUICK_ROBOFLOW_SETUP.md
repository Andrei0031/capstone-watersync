# Quick Roboflow Setup - 3 Steps

## Step 1: Get Your API Key (2 minutes)

1. Go to: https://app.roboflow.com
2. Click on your project: **"watersync-oekrf"**
3. Click **"Deploy"** → **"Roboflow API"**
4. Copy your **API Key** (long string of characters)

## Step 2: Update Code (1 minute)

1. Open: `lib/services/roboflow_service.dart`
2. Find line 8-11:
   ```dart
   static const String apiKey = 'YOUR_ROBOFLOW_API_KEY';
   static const String workspace = 'watersync';
   static const String project = 'watersync-oekrf';
   static const String modelVersion = '1';
   ```
3. Replace `'YOUR_ROBOFLOW_API_KEY'` with your actual API key
4. Update `modelVersion` if your model is not version 1

## Step 3: Test (2 minutes)

1. Run: `flutter run`
2. Go to "Scan Meter"
3. Capture a meter image
4. You should see: "Detecting meter region with AI..."

---

## ✅ Done!

Your app now:
1. **Detects** meter region with Roboflow AI
2. **Crops** image to reading area
3. **Sends** cropped image to server for OCR
4. **Extracts** meter reading more accurately

---

## 🎯 What Happens Now:

```
📸 Capture Image
   ↓
🤖 Roboflow detects "WaterMeter" region (97% confidence)
   ↓
✂️ Crop image to detected region
   ↓
🔍 Server OCR extracts "00792"
   ↓
✅ Display reading
```

---

## ⚠️ If Detection Fails:

The app automatically falls back to using the original image, so it will still work!

---

**Need help?** Check `ROBOFLOW_INTEGRATION_GUIDE.md` for detailed troubleshooting.

