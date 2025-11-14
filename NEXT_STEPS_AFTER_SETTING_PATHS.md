# Next Steps After Setting Training Data Path

## ✅ You're Setting the Training Data Location

### In the File Dialog:

1. **Navigate to this folder:**
   ```
   C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth
   ```

2. **If folder doesn't exist:**
   - Click the "Create new folder" icon (folder with green arrow)
   - Name it: `watermeter-ground-truth`
   - Or navigate to `C:\xampp\htdocs\CAPSTONE\tesseract_training\` first
   - Then create `watermeter-ground-truth` subfolder

3. **Select the folder:**
   - Click on `watermeter-ground-truth` folder
   - Click "Set" button

---

## After Setting the Path

### Step 1: Collect Training Images

**Option A - Use Your Training Helper (Easiest):**
1. Open browser: `http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php?action=upload`
2. Upload 50-100+ meter images
3. Enter correct reading for each (e.g., "00792")
4. Images will be saved to: `C:\xampp\htdocs\CAPSTONE\tesseract_training\images\`

**Then copy images to training folder:**
- Copy from: `C:\xampp\htdocs\CAPSTONE\tesseract_training\images\`
- Copy to: `C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth\`
- Make sure each image has a `.gt.txt` file with the reading

**Option B - Copy Images Directly:**
- Copy your meter images to: `C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth\`
- Create `.gt.txt` files for each image:
  - `meter_001.png` → `meter_001.gt.txt` (contains: "00792 m³")
  - `meter_002.png` → `meter_002.gt.txt` (contains: "00845 m³")
  - etc.

---

### Step 2: Use Box Editor

1. **Click "Box Editor" tab** in jTessBoxEditor

2. **Click "Open"** button

3. **Navigate to:** `C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth\`

4. **Select all your training images** (Ctrl+A)

5. **Click "Open"**

6. **For each image:**
   - Draw a box around "00792" (the 5-digit reading)
   - Type: `00792 m³` (or just `00792`)
   - Press Enter

7. **Save:** File → Save (or Ctrl+S)

---

### Step 3: Train the Model

1. **Click "Trainer" tab**

2. **Verify settings:**
   - ✅ Tesseract Executables: `C:\xampp\htdocs\CAPSTONE\jTessBoxEditor\tesseract-ocr`
   - ✅ Training Data: `C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth`
   - ✅ Language: `watermeter`
   - ✅ Bootstrap Language: `eng`

3. **Select Training Mode:**
   - Dropdown: Select "Fine-tune from existing model"

4. **Click "Run"** button

5. **Wait for training** (30 minutes to several hours)

---

### Step 4: Install Trained Model

After training completes:

1. **Find:** `watermeter.traineddata` file
   - Location: `C:\xampp\htdocs\CAPSTONE\tesseract_training\watermeter-ground-truth\`

2. **Copy to:** `C:\Program Files\Tesseract-OCR\tessdata\`

3. **Update PHP:**
   - Open: `C:\xampp\htdocs\CAPSTONE\api\upload_reading.php`
   - Find: `$language = 'eng';`
   - Change to: `$language = 'watermeter';`

---

## Quick Checklist

- [ ] Set Training Data path to: `watermeter-ground-truth` folder
- [ ] Collect 50-100+ training images
- [ ] Create .gt.txt files for each image
- [ ] Use Box Editor to draw boxes around readings
- [ ] Train the model
- [ ] Install trained model
- [ ] Update PHP code
- [ ] Test with new images

---

## Current Status

✅ jTessBoxEditor is open
⏳ Setting Training Data path (you're here)
⏳ Next: Collect training images
⏳ Then: Draw boxes in Box Editor
⏳ Finally: Train the model

Good progress! 🎯

