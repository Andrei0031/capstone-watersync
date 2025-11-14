# jTessBoxEditorFX Guide - Newer & Better Version!

## ✅ Perfect! You Found the Right Tool!

**jTessBoxEditorFX** is the **newer JavaFX version** of jTessBoxEditor with:
- ✅ Better user interface
- ✅ More features
- ✅ Easier to use
- ✅ Same training functionality

## Installation Steps

### Step 1: Extract the ZIP File

1. **Download:** `jTessBoxEditorFX-2.7.0.zip`
2. **Extract** the ZIP file to a folder:
   - Example: `C:\TesseractTools\jTessBoxEditorFX-2.7.0\`
   - You can use any folder you prefer

3. **Inside the extracted folder, you'll find:**
   - `jTessBoxEditorFX.jar` (or similar .jar file)
   - Other supporting files

### Step 2: Install Java (If Not Already Installed)

1. **Check if Java is installed:**
   ```cmd
   java -version
   ```

2. **If not installed:**
   - Download: https://www.java.com/download/
   - Install Java
   - Restart Command Prompt

### Step 3: Run jTessBoxEditorFX

**Method 1: Using Command Prompt**

1. **Open Command Prompt**
2. **Navigate to extracted folder:**
   ```cmd
   cd C:\TesseractTools\jTessBoxEditorFX-2.7.0
   ```
3. **Run:**
   ```cmd
   java -Xmx1024m -jar jTessBoxEditorFX.jar
   ```
   (Replace `jTessBoxEditorFX.jar` with the actual .jar filename if different)

**Method 2: Double-Click (If JavaFX is set up)**

- Navigate to the extracted folder
- Double-click the `.jar` file
- If it opens, great! ✅

**Method 3: Create Batch File**

1. **Create:** `run_jtessboxeditorfx.bat` in the extracted folder
2. **Add this content:**
   ```batch
   @echo off
   cd /d "%~dp0"
   java -Xmx1024m -jar jTessBoxEditorFX.jar
   pause
   ```
3. **Double-click** the batch file to run

## Using jTessBoxEditorFX for Training

### Step 1: Prepare Your Training Images

1. **Collect images** using your training helper:
   - `http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php?action=upload`
   - Images are saved to: `C:\xampp\htdocs\CAPSTONE\tesseract_training\images\`

### Step 2: Open Images in jTessBoxEditorFX

1. **Launch jTessBoxEditorFX**
2. **Click "Box Editor" tab** (or similar)
3. **Click "Open"** or **File → Open**
4. **Navigate to:** `C:\xampp\htdocs\CAPSTONE\tesseract_training\images\`
5. **Select your training images**

### Step 3: Draw Boxes Around Meter Readings

1. **For each image:**
   - Click and drag to draw a box around "00792"
   - A text input will appear
   - Type: `00792 m³` (or just `00792`)
   - Press Enter or click OK

2. **Repeat for all images**

3. **Save your work:**
   - File → Save (or Ctrl+S)

### Step 4: Generate Training Files

1. **Click "Train" tab** (or Training tab)
2. **Select your training data**
3. **Set model name:** `watermeter`
4. **Click "Train"** or "Generate"
5. **Wait for training** (30 minutes to several hours)

### Step 5: Install Trained Model

1. **After training completes**, you'll get: `watermeter.traineddata`
2. **Copy to:** `C:\Program Files\Tesseract-OCR\tessdata\`
3. **Update PHP:** In `upload_reading.php` line 208, change:
   ```php
   $language = 'watermeter'; // Your trained model
   ```

## Troubleshooting

### Problem: "JavaFX runtime components are missing"

**Solution:**
- Install JavaFX SDK or use Java 11+ (includes JavaFX)
- Or download: https://openjfx.io/
- Or use regular jTessBoxEditor instead

### Problem: "Could not find or load main class"

**Solution:**
- Make sure you're in the correct folder
- Check the exact .jar filename (might be different)
- List files: `dir *.jar` to see available .jar files

### Problem: Window won't open

**Solution:**
- Try: `java -Xmx2048m -jar jTessBoxEditorFX.jar`
- Check Java version: `java -version` (need Java 8+)
- Try running from Command Prompt to see error messages

## Features of jTessBoxEditorFX

- ✅ Visual box editor (draw boxes around text)
- ✅ Easy ground truth editing
- ✅ Batch processing
- ✅ Better UI than older version
- ✅ Training automation
- ✅ Model testing

## Quick Start Checklist

- [ ] Extract `jTessBoxEditorFX-2.7.0.zip`
- [ ] Install Java (if needed)
- [ ] Run: `java -Xmx1024m -jar jTessBoxEditorFX.jar`
- [ ] Open your training images
- [ ] Draw boxes around "00792"
- [ ] Train the model
- [ ] Install trained model
- [ ] Test with your images

## Need Help?

- **Official Docs:** Check the extracted folder for README files
- **Training Guide:** See `TRAIN_TESSERACT_STEP_BY_STEP.md`
- **Your Training Helper:** `http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php`

Good luck with training! 🎯

