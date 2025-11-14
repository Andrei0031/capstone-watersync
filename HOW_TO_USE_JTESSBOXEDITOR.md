# How to Use jTessBoxEditor - Step by Step Guide

## ✅ jTessBoxEditor is Open! Now Let's Train Your Model

## Step 1: Set Up Paths (Trainer Tab)

### 1.1 Set Tesseract Executables Path:
1. Click the "..." button next to "Tesseract Executables"
2. Navigate to: `C:\xampp\htdocs\CAPSTONE\jTessBoxEditor\tesseract-ocr`
3. Select the folder (or select `tesseract.exe`)

### 1.2 Set Training Data Path:
1. Click the "..." button next to "Training Data"
2. Navigate to: `C:\xampp\htdocs\CAPSTONE\tesseract_training`
3. If folder doesn't exist, create it:
   - Create: `C:\xampp\htdocs\CAPSTONE\tesseract_training\`
   - Create subfolder: `C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth\`

### 1.3 Set Language:
- Type: `watermeter` (this will be your trained model name)

### 1.4 Set Bootstrap Language:
- Type: `eng` (we're training from English base)

---

## Step 2: Prepare Training Images

### Option A: Use Your Training Helper (Easier)
1. Go to: `http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php?action=upload`
2. Upload 50-100+ meter images with correct readings
3. Images are saved to: `C:\xampp\htdocs\CAPSTONE\tesseract_training\images\`

### Option B: Copy Images Manually
1. Copy your meter images to: `C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth\`
2. Make sure images are named: `meter_001.png`, `meter_002.png`, etc.

---

## Step 3: Create Ground Truth Files

For each image, create a `.gt.txt` file with the correct reading:

**Example:**
- Image: `meter_001.png`
- Ground truth: `meter_001.gt.txt` containing: `00792 m³`

**Quick way to create ground truth files:**
1. Use your training helper to upload images (it creates .gt.txt automatically)
2. Or create manually using Notepad

---

## Step 4: Use Box Editor (Draw Boxes)

### 4.1 Switch to Box Editor Tab:
1. Click **"Box Editor"** tab in jTessBoxEditor

### 4.2 Load Images:
1. Click **"Open"** or **File → Open**
2. Navigate to: `C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth\`
3. Select all your training images (Ctrl+A)
4. Click "Open"

### 4.3 Draw Boxes Around Meter Readings:
1. **For each image:**
   - Click and drag to draw a box around "00792" (the 5-digit reading)
   - A text input dialog will appear
   - Type: `00792 m³` (or just `00792`)
   - Press Enter or click OK

2. **Important:** Only draw boxes around the meter reading, not the entire meter!

3. **Repeat for all images**

### 4.4 Save Box Files:
1. File → Save (or Ctrl+S)
2. This creates `.box` files for each image

---

## Step 5: Train the Model

### 5.1 Switch Back to Trainer Tab:
1. Click **"Trainer"** tab

### 5.2 Verify Settings:
- ✅ Tesseract Executables: `C:\xampp\htdocs\CAPSTONE\jTessBoxEditor\tesseract-ocr`
- ✅ Training Data: `C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth`
- ✅ Language: `watermeter`
- ✅ Bootstrap Language: `eng`

### 5.3 Select Training Mode:
1. Click the **"Training Mode"** dropdown
2. Select: **"Fine-tune from existing model"** or **"Train from scratch"**
   - For first time: Use **"Fine-tune from existing model"**

### 5.4 Start Training:
1. Click **"Run"** button
2. **Wait for training to complete** (30 minutes to several hours)
3. Progress will be shown in the output area

### 5.5 Training Output:
- You'll get: `watermeter.traineddata` file
- Location: In your training data folder

---

## Step 6: Install Trained Model

### 6.1 Copy Trained Model:
1. Find: `watermeter.traineddata` file
2. Copy to: `C:\Program Files\Tesseract-OCR\tessdata\`

### 6.2 Verify Installation:
```cmd
tesseract --list-langs
```
Should show `watermeter` in the list

### 6.3 Update PHP Code:
1. Open: `C:\xampp\htdocs\CAPSTONE\api\upload_reading.php`
2. Find line 208 (or around there)
3. Change:
   ```php
   $language = 'eng';
   ```
   To:
   ```php
   $language = 'watermeter'; // Your trained model
   ```
   Or combine:
   ```php
   $language = 'eng+watermeter'; // Use both English and your trained model
   ```

---

## Step 7: Test Your Trained Model

1. **Test with training helper:**
   - Go to: `http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php?action=test`
   - Upload a new meter image
   - Check if it detects "00792" correctly

2. **Test with mobile app:**
   - Capture a meter reading
   - Upload from mobile app
   - Check if OCR reading is accurate

---

## Quick Reference

### Box Editor Tab:
- **Open:** Load training images
- **Draw boxes:** Around meter readings
- **Label:** "00792 m³"
- **Save:** Creates .box files

### Trainer Tab:
- **Set paths:** Tesseract and training data
- **Set language:** `watermeter`
- **Set bootstrap:** `eng`
- **Select mode:** Fine-tune or train from scratch
- **Run:** Start training

---

## Tips for Better Training

1. **Focus on the reading:** Draw boxes ONLY around "00792", not entire meter
2. **Consistent format:** Always use "00792 m³" format
3. **More images:** 50-100+ images for good accuracy
4. **Quality matters:** Use clear, well-lit images
5. **Variety:** Include different meters, lighting conditions

---

## Troubleshooting

### Problem: "Tesseract executable not found"
**Solution:** Set correct path to `tesseract-ocr` folder

### Problem: "Training data not found"
**Solution:** Create the folder and add images with .gt.txt files

### Problem: Training fails
**Solution:** 
- Check that all images have corresponding .box files
- Verify Tesseract is installed correctly
- Check training data folder structure

### Problem: Model not working after training
**Solution:**
- Verify .traineddata file is in tessdata folder
- Restart XAMPP/Apache
- Check PHP code uses correct language name

---

Good luck with training! 🎯

