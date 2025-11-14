# Step-by-Step Guide: Training Tesseract for Water Meter OCR

## 🎯 Goal
Train Tesseract OCR to accurately detect 5-digit water meter readings (e.g., "00442 m³") from mobile app photos.

---

## Step 1: Install Tesseract OCR ✅

### Windows (XAMPP):
1. **Download Tesseract:**
   - Go to: https://github.com/UB-Mannheim/tesseract/wiki
   - Download the latest Windows installer (e.g., `tesseract-ocr-w64-setup-5.x.x.exe`)

2. **Install:**
   - Run the installer
   - Install to: `C:\Program Files\Tesseract-OCR\`
   - ✅ Check "Add to PATH" during installation (or add manually later)

3. **Verify Installation:**
   ```cmd
   tesseract --version
   ```
   Should show: `tesseract 5.x.x`

4. **Update PHP Script:**
   - Open `C:\xampp\htdocs\CAPSTONE\api\upload_reading.php`
   - Line 161: Update `$tesseractPath` if needed:
   ```php
   $tesseractPath = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';
   ```

---

## Step 2: Use Training Helper Tool 🛠️

1. **Open Training Helper:**
   - Navigate to: `http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php`
   - This tool helps you collect and manage training data

2. **Test Current OCR:**
   - Go to "Test OCR" tab
   - Upload a sample meter image
   - See what Tesseract currently detects
   - This shows you why training is needed

---

## Step 3: Collect Training Images 📸

### Using the Training Helper:
1. Go to "Upload Training Images" tab
2. Upload 50-100+ water meter images with:
   - **Correct reading** (5 digits, e.g., "00442")
   - **Context** (e.g., "00442 m³")
3. Include variety:
   - Different lighting (bright, dim, shadows)
   - Different meter models
   - Clean and dirty meters
   - Different angles

### Manual Collection:
- Save images to: `C:\xampp\htdocs\CAPSTONE\tesseract_training\images\`
- Create ground truth files: `C:\xampp\htdocs\CAPSTONE\tesseract_training\ground_truth\`
- Format: `meter_001.png` → `meter_001.gt.txt` (contains: "00442 m³")

---

## Step 4: Install Training Tools 🔧

### Option A: Using Git (Recommended)
```cmd
cd C:\
git clone https://github.com/tesseract-ocr/tesstrain.git
cd tesstrain
```

### Option B: Download ZIP
1. Go to: https://github.com/tesseract-ocr/tesstrain
2. Click "Code" → "Download ZIP"
3. Extract to: `C:\tesstrain\`

### Install Dependencies:
- **Python 3.x** (if not installed)
- **Make for Windows** (or use WSL)

---

## Step 5: Prepare Training Data 📁

1. **Copy your training images:**
   ```cmd
   mkdir C:\tesstrain\data\watermeter-ground-truth
   ```

2. **Copy images and ground truth files:**
   - From: `C:\xampp\htdocs\CAPSTONE\tesseract_training\images\`
   - To: `C:\tesstrain\data\watermeter-ground-truth\`
   - Copy matching `.gt.txt` files too

3. **Verify structure:**
   ```
   C:\tesstrain\data\watermeter-ground-truth\
     meter_001.png
     meter_001.gt.txt
     meter_002.png
     meter_002.gt.txt
     ...
   ```

---

## Step 6: Run Training 🚀

### Using tesstrain (Easiest Method):

**Windows (PowerShell):**
```powershell
cd C:\tesstrain
make training MODEL_NAME=watermeter START_MODEL=eng
```

**If Make doesn't work, use WSL (Windows Subsystem for Linux):**
```bash
# In WSL terminal
cd /mnt/c/tesstrain
make training MODEL_NAME=watermeter START_MODEL=eng
```

**Training will:**
- Take 30 minutes to several hours (depending on data)
- Create `watermeter.traineddata` in the `data` folder
- Show progress and accuracy metrics

---

## Step 7: Install Trained Model 📦

1. **Find the trained model:**
   - Location: `C:\tesstrain\data\watermeter.traineddata`

2. **Copy to Tesseract data directory:**
   ```cmd
   copy C:\tesstrain\data\watermeter.traineddata "C:\Program Files\Tesseract-OCR\tessdata\"
   ```

3. **Verify installation:**
   ```cmd
   tesseract --list-langs
   ```
   Should show `watermeter` in the list

---

## Step 8: Update PHP Code 🔄

1. **Open:** `C:\xampp\htdocs\CAPSTONE\api\upload_reading.php`

2. **Update line 204:**
   ```php
   // Before:
   $language = 'eng';
   
   // After (use your trained model):
   $language = 'watermeter';
   
   // Or combine with English (recommended):
   $language = 'eng+watermeter';
   ```

3. **Save the file**

---

## Step 9: Test Trained Model ✅

1. **Use Training Helper:**
   - Go to: `http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php`
   - Click "Test OCR" tab
   - Upload a new meter image (not in training set)
   - Compare detected reading with expected reading

2. **Test via Mobile App:**
   - Capture a meter reading
   - Upload from mobile app
   - Check if OCR reading is accurate

3. **Check Admin Panel:**
   - Go to "Meter Reading Management"
   - Check "Pending OCR Processing" table
   - Verify OCR readings are correct

---

## Step 10: Improve Accuracy (If Needed) 📈

### If accuracy is still low:

1. **Add more training images:**
   - Focus on images that failed
   - Add 20-30 more images
   - Retrain

2. **Improve image quality:**
   - Preprocess images (enhance contrast, remove noise)
   - Use better lighting when capturing

3. **Try different PSM modes:**
   - In `upload_reading.php`, try:
   ```php
   --psm 7  // Single text line
   --psm 8  // Single word
   --psm 11 // Sparse text
   ```

4. **Create multiple models:**
   - Train separate models for different meter types
   - Use appropriate model based on meter model

---

## Quick Reference Commands 📝

```cmd
# Test Tesseract installation
tesseract --version

# List available languages
tesseract --list-langs

# Test OCR on an image
tesseract "path\to\image.png" output -l eng --psm 6

# Test with your trained model
tesseract "path\to\image.png" output -l watermeter --psm 6
```

---

## Troubleshooting 🔧

### Problem: "tesseract: command not found"
**Solution:** Add Tesseract to PATH or use full path in PHP

### Problem: Training fails with "Make not found"
**Solution:** 
- Install Make for Windows, or
- Use WSL (Windows Subsystem for Linux), or
- Follow manual training process

### Problem: Low accuracy after training
**Solution:**
- Add more training images (aim for 100+)
- Ensure ground truth files are accurate
- Check image quality
- Try different PSM modes

### Problem: PHP can't find Tesseract
**Solution:**
- Update `$tesseractPath` in `upload_reading.php`
- Use full path: `C:\\Program Files\\Tesseract-OCR\\tesseract.exe`
- Check file permissions

---

## Expected Results 🎯

After successful training:
- ✅ OCR accuracy: 85-95%+ for water meter readings
- ✅ Correctly detects 5-digit readings (e.g., "00442")
- ✅ Handles common OCR errors (C/O → 0)
- ✅ Works with various lighting conditions

---

## Next Steps 🚀

1. **Monitor accuracy** in production
2. **Collect failed images** for retraining
3. **Periodically retrain** with new data
4. **Consider image preprocessing** for better results

---

## Resources 📚

- **Tesseract Training:** https://github.com/tesseract-ocr/tesstrain
- **Official Guide:** https://tesseract-ocr.github.io/tessdoc/Training-Tesseract.html
- **Video Tutorial:** Search "How to Train Tesseract OCR Engine 5"

---

## Support 💬

If you encounter issues:
1. Check Tesseract installation
2. Verify training data format
3. Review error messages
4. Test with sample images first

Good luck with training! 🎉

