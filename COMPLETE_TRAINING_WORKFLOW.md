# Complete Training Workflow - Step by Step

## ✅ Step 1: Set All Paths (Trainer Tab)

You've set Training Data path. Now set the rest:

### 1.1 Set Tesseract Executables:
1. Click "..." next to "Tesseract Executables"
2. Navigate to: `C:\xampp\htdocs\CAPSTONE\jTessBoxEditor\tesseract-ocr`
3. Select the folder (or select `tesseract.exe`)
4. Click "Set" or "OK"

### 1.2 Set Language:
- In "Language" field: Type `watermeter`

### 1.3 Set Bootstrap Language:
- In "Bootstrap Language" field: Type `eng`

---

## ✅ Step 2: Collect Training Images

### Use Your Training Helper:

1. **Open browser:**
   ```
   http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php?action=upload
   ```

2. **Upload 50-100+ meter images:**
   - Click "Choose File"
   - Select a meter image
   - Enter correct reading: `00792` (5 digits)
   - Context: `00792 m³` (optional, auto-filled)
   - Click "Save Training Data"
   - Repeat for all images

3. **Images are saved to:**
   - `C:\xampp\htdocs\CAPSTONE\tesseract_training\images\`

4. **Copy images to training folder:**
   - Copy from: `C:\xampp\htdocs\CAPSTONE\tesseract_training\images\`
   - Copy to: `C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth\`
   - Make sure each image has a matching `.gt.txt` file

---

## ✅ Step 3: Use Box Editor (Draw Boxes)

### 3.1 Switch to Box Editor Tab:
1. Click **"Box Editor"** tab in jTessBoxEditor

### 3.2 Load Images:
1. Click **"Open"** button (or File → Open)
2. Navigate to: `C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth\`
3. Select all your training images:
   - Press `Ctrl + A` to select all
   - Or select multiple with `Ctrl + Click`
4. Click "Open"

### 3.3 Draw Boxes Around Meter Readings:
1. **For each image:**
   - Click and drag to draw a rectangle around "00792"
   - A text input dialog will appear
   - Type: `00792 m³` (or just `00792`)
   - Press Enter or click OK

2. **Important Tips:**
   - Draw box ONLY around the 5-digit reading
   - Don't include the entire meter
   - Make box tight around the numbers

3. **Navigate between images:**
   - Use arrow keys or Next/Previous buttons
   - Process all images

### 3.4 Save Box Files:
1. **File → Save** (or Ctrl+S)
2. This creates `.box` files for each image
3. Files are saved automatically

---

## ✅ Step 4: Train the Model

### 4.1 Switch to Trainer Tab:
1. Click **"Trainer"** tab

### 4.2 Verify All Settings:
- ✅ Tesseract Executables: `C:\xampp\htdocs\CAPSTONE\jTessBoxEditor\tesseract-ocr`
- ✅ Training Data: `C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth`
- ✅ Language: `watermeter`
- ✅ Bootstrap Language: `eng`

### 4.3 Select Training Mode:
1. Click **"Training Mode"** dropdown
2. Select: **"Fine-tune from existing model"** (recommended for first time)
   - This trains from English base model
   - Faster and easier than training from scratch

### 4.4 Start Training:
1. Click **"Run"** button
2. **Wait for training** (30 minutes to several hours depending on number of images)
3. Progress will be shown in the output area
4. **Don't close jTessBoxEditor** during training!

### 4.5 Training Complete:
- You'll see "Training completed successfully" message
- Output file: `watermeter.traineddata`
- Location: In your training data folder

---

## ✅ Step 5: Install Trained Model

### 5.1 Find Trained Model:
- Location: `C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth\`
- File: `watermeter.traineddata`

### 5.2 Copy to Tesseract:
1. **Copy** `watermeter.traineddata`
2. **Paste** to: `C:\Program Files\Tesseract-OCR\tessdata\`

### 5.3 Verify Installation:
```cmd
tesseract --list-langs
```
Should show `watermeter` in the list

### 5.4 Update PHP Code:
1. Open: `C:\xampp\htdocs\CAPSTONE\api\upload_reading.php`
2. Find line ~208: `$language = 'eng';`
3. Change to: `$language = 'watermeter';`
4. Save file

---

## ✅ Step 6: Test Your Trained Model

### 6.1 Test with Training Helper:
1. Go to: `http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php?action=test`
2. Upload a NEW meter image (not in training set)
3. Enter expected reading: `00792`
4. Check if detected reading matches!

### 6.2 Test with Mobile App:
1. Capture a meter reading
2. Upload from mobile app
3. Check if OCR reading is accurate

---

## Current Status Checklist

- [x] jTessBoxEditor is open
- [x] Training Data path is set
- [ ] Tesseract Executables path is set
- [ ] Language is set to `watermeter`
- [ ] Bootstrap Language is set to `eng`
- [ ] Training images collected (50-100+)
- [ ] Boxes drawn around readings
- [ ] Model trained
- [ ] Trained model installed
- [ ] PHP code updated
- [ ] Model tested

---

## Next Immediate Steps:

1. **Set Tesseract Executables path** (if not done)
2. **Set Language: `watermeter`**
3. **Set Bootstrap Language: `eng`**
4. **Collect training images** using training helper
5. **Use Box Editor** to draw boxes
6. **Train the model**

You're making great progress! 🎯

