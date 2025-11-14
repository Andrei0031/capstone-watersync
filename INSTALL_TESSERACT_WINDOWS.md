# Install Tesseract OCR on Windows - Step by Step Guide

## Quick Installation Steps

### Step 1: Download Tesseract OCR

1. **Go to the official download page:**
   - Visit: https://github.com/UB-Mannheim/tesseract/wiki
   - Or direct link: https://digi.bib.uni-mannheim.de/tesseract/

2. **Download the Windows installer:**
   - Look for: `tesseract-ocr-w64-setup-5.x.x.exe` (latest version)
   - Example: `tesseract-ocr-w64-setup-5.4.0.20240619.exe`
   - **Important:** Download the 64-bit version (w64)

### Step 2: Install Tesseract

1. **Run the installer:**
   - Double-click the downloaded `.exe` file
   - Click "Next" through the installation wizard

2. **Choose installation location:**
   - **Default:** `C:\Program Files\Tesseract-OCR\`
   - **Recommended:** Keep the default location
   - Click "Next"

3. **Select components:**
   - ✅ Check "Tesseract OCR" (main program)
   - ✅ Check "Language data (best)" or "Language data (fast)" 
     - This includes English language data
   - ✅ **IMPORTANT:** Check "Add to PATH" 
     - This makes Tesseract accessible from command line
   - Click "Next"

4. **Complete installation:**
   - Click "Install"
   - Wait for installation to complete
   - Click "Finish"

### Step 3: Verify Installation

1. **Open Command Prompt (CMD) or PowerShell:**
   - Press `Win + R`
   - Type `cmd` and press Enter
   - Or search "Command Prompt" in Start menu

2. **Test Tesseract:**
   ```cmd
   tesseract --version
   ```
   
   **Expected output:**
   ```
   tesseract 5.x.x
    leptonica-1.x.x
    ...
   ```

3. **If you see version info:** ✅ Tesseract is installed correctly!

4. **If you see "command not found":**
   - Tesseract is not in PATH
   - See "Manual PATH Setup" below

### Step 4: Manual PATH Setup (If Needed)

If `tesseract --version` doesn't work:

1. **Find Tesseract installation:**
   - Usually at: `C:\Program Files\Tesseract-OCR\`
   - Check if `tesseract.exe` exists there

2. **Add to PATH manually:**
   - Press `Win + X` → "System"
   - Click "Advanced system settings"
   - Click "Environment Variables"
   - Under "System variables", find "Path"
   - Click "Edit"
   - Click "New"
   - Add: `C:\Program Files\Tesseract-OCR`
   - Click "OK" on all dialogs

3. **Restart Command Prompt:**
   - Close and reopen CMD/PowerShell
   - Test again: `tesseract --version`

### Step 5: Test with Your Training Tool

1. **Open your browser:**
   - Go to: `http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php?action=test`

2. **Upload a test image:**
   - Use the "Test OCR" tab
   - Upload a water meter image
   - Check if OCR works

3. **If it works:** ✅ Everything is set up correctly!

## Troubleshooting

### Problem: "tesseract: command not found" after installation

**Solution 1: Restart your computer**
- Sometimes PATH changes need a restart

**Solution 2: Use full path in PHP**
- Update `upload_reading.php` line 161:
  ```php
  $tesseractPath = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';
  ```

**Solution 3: Check installation location**
- Verify Tesseract is at: `C:\Program Files\Tesseract-OCR\tesseract.exe`
- If installed elsewhere, update the path accordingly

### Problem: "Access denied" when running tesseract

**Solution:**
- Run Command Prompt as Administrator
- Or check file permissions on Tesseract folder

### Problem: PHP can't find Tesseract

**Solution:**
- Update `upload_reading.php` with full path:
  ```php
  $tesseractPath = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';
  ```
- Restart XAMPP/Apache after installation

## Verify Installation Locations

Check these locations for `tesseract.exe`:

- ✅ `C:\Program Files\Tesseract-OCR\tesseract.exe` (64-bit, default)
- ✅ `C:\Program Files (x86)\Tesseract-OCR\tesseract.exe` (32-bit)
- ✅ `C:\Tesseract-OCR\tesseract.exe` (custom location)

## Next Steps After Installation

1. ✅ Test Tesseract: `tesseract --version`
2. ✅ Test with training tool: `http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php?action=test`
3. ✅ Start collecting training images
4. ✅ Train Tesseract for water meters (see `TRAIN_TESSERACT_STEP_BY_STEP.md`)

## Quick Test Command

Test Tesseract on an image:
```cmd
tesseract "path\to\image.png" output -l eng --psm 6
```

This creates `output.txt` with OCR results.

## Need Help?

- **Official Docs:** https://tesseract-ocr.github.io/
- **Windows Installer:** https://github.com/UB-Mannheim/tesseract/wiki
- **Test Script:** `http://192.168.100.5/CAPSTONE/test_tesseract.php`



