# How to Run jTessBoxEditor - Step by Step Guide

## Prerequisites

### Step 1: Install Java (Required)

1. **Check if Java is installed:**
   - Open Command Prompt (Press `Win + R`, type `cmd`, press Enter)
   - Type: `java -version`
   - If you see version info, Java is installed ✅
   - If you see "command not found", install Java

2. **Download Java (if not installed):**
   - Go to: https://www.java.com/download/
   - Click "Download Java"
   - Run the installer
   - Follow installation wizard
   - Restart your computer if prompted

3. **Verify Java installation:**
   ```cmd
   java -version
   ```
   Should show something like: `java version "17.0.x"` or similar

---

## Download jTessBoxEditor

### Step 2: Download jTessBoxEditor

1. **Go to download page:**
   - Visit: https://sourceforge.net/projects/vietocr/files/jTessBoxEditor/
   - Or direct link: https://sourceforge.net/projects/vietocr/files/jTessBoxEditor/jTessBoxEditor-2.3.1/jTessBoxEditor.jar/download

2. **Download the file:**
   - Click "Download" button
   - Save `jTessBoxEditor.jar` to a folder (e.g., `C:\TesseractTools\`)
   - Remember where you saved it!

---

## Run jTessBoxEditor

### Step 3: Run jTessBoxEditor

**Method 1: Using Command Prompt (Recommended)**

1. **Open Command Prompt:**
   - Press `Win + R`
   - Type `cmd` and press Enter

2. **Navigate to where you saved jTessBoxEditor:**
   ```cmd
   cd C:\TesseractTools
   ```
   (Replace `C:\TesseractTools` with your actual folder)

3. **Run jTessBoxEditor:**
   ```cmd
   java -Xmx1024m -jar jTessBoxEditor.jar
   ```

4. **jTessBoxEditor window should open!** ✅

---

**Method 2: Double-Click (If Java is associated with .jar files)**

1. Navigate to where you saved `jTessBoxEditor.jar`
2. Double-click the file
3. If it opens, great! ✅
4. If it doesn't open, use Method 1 instead

---

**Method 3: Create a Batch File (Easiest)**

1. **Create a new text file** named `run_jtessboxeditor.bat`
2. **Add this content:**
   ```batch
   @echo off
   cd /d "C:\TesseractTools"
   java -Xmx1024m -jar jTessBoxEditor.jar
   pause
   ```
   (Replace `C:\TesseractTools` with your actual folder)

3. **Save the file**
4. **Double-click `run_jtessboxeditor.bat`** to run jTessBoxEditor

---

## Using jTessBoxEditor

### Step 4: Load Your Training Images

1. **In jTessBoxEditor, click "Box Editor" tab**

2. **Click "Open" button** (or File → Open)

3. **Navigate to your training images folder:**
   - Location: `C:\xampp\htdocs\CAPSTONE\tesseract_training\images\`
   - Select all your meter images (Ctrl+A to select all)

4. **Images will load in the editor**

---

### Step 5: Draw Boxes Around Meter Readings

1. **For each image:**
   - Click and drag to draw a box around "00792" (the 5-digit reading)
   - A text box will appear
   - Type: `00792 m³` (or just `00792`)
   - Press Enter

2. **Repeat for all images**

3. **Save your work:**
   - File → Save (or Ctrl+S)

---

### Step 6: Generate Training Files

1. **Click "Train" tab**

2. **Select your training data**

3. **Click "Train" button**

4. **Wait for training to complete** (may take 30 minutes to hours)

5. **You'll get a `.traineddata` file**

---

## Troubleshooting

### Problem: "java is not recognized"

**Solution:**
- Java is not installed or not in PATH
- Install Java from https://www.java.com/download/
- Restart Command Prompt after installation

### Problem: "Could not find or load main class"

**Solution:**
- Make sure you're in the correct folder
- Check that `jTessBoxEditor.jar` exists in that folder
- Try using full path: `java -Xmx1024m -jar "C:\TesseractTools\jTessBoxEditor.jar"`

### Problem: jTessBoxEditor won't open

**Solution:**
- Make sure Java is installed: `java -version`
- Try increasing memory: `java -Xmx2048m -jar jTessBoxEditor.jar`
- Check if file downloaded correctly (should be ~2-3 MB)

### Problem: Can't see images in Box Editor

**Solution:**
- Make sure images are in supported format (PNG, JPG, TIFF)
- Try opening images one by one first
- Check image file permissions

---

## Quick Reference Commands

```cmd
# Check Java version
java -version

# Navigate to folder
cd C:\TesseractTools

# Run jTessBoxEditor
java -Xmx1024m -jar jTessBoxEditor.jar

# Run with more memory (if needed)
java -Xmx2048m -jar jTessBoxEditor.jar
```

---

## Next Steps After Training

1. **Copy trained model** to: `C:\Program Files\Tesseract-OCR\tessdata\`
2. **Update PHP code** in `upload_reading.php`:
   ```php
   $language = 'watermeter'; // Your trained model name
   ```
3. **Test** with your training images
4. **Use in production** - mobile app will use trained model!

---

## Video Tutorial

Search YouTube for: "jTessBoxEditor tutorial" or "Tesseract OCR training jTessBoxEditor"

---

## Alternative: Use Your Training Helper

If jTessBoxEditor is too complex, you can:
1. Collect images using: `http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php?action=upload`
2. Use tesstrain command-line tool (more advanced)
3. Or use online training services

But **jTessBoxEditor is the easiest** for visual training! 🎯

