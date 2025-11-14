# Where to Find Error Logs

## PHP Error Logs Location

### XAMPP Default Locations:

1. **Apache Error Log:**
   - `C:\xampp\apache\logs\error.log`
   - Contains Apache and PHP errors

2. **PHP Error Log:**
   - `C:\xampp\php\logs\php_error_log`
   - Contains PHP-specific errors

3. **Windows Event Viewer:**
   - Press `Win + R`, type `eventvwr.msc`
   - Look under "Windows Logs" → "Application"

## How to Check Error Logs:

### Method 1: Check Apache Error Log
1. Open: `C:\xampp\apache\logs\error.log`
2. Scroll to the bottom (most recent errors)
3. Look for lines containing "OCR processing failed" or "reading ID"

### Method 2: Check PHP Error Log
1. Open: `C:\xampp\php\logs\php_error_log`
2. Look for recent errors

### Method 3: View Errors in Web Interface
- Go to "Failed Readings" tab
- Click on a failed reading
- The error message is now displayed in the table

## Common Error Causes:

1. **Tesseract Not Installed**
   - Error: "Tesseract OCR is not installed"
   - Fix: Install Tesseract from https://github.com/UB-Mannheim/tesseract/wiki

2. **Image File Not Found**
   - Error: "Image file not found"
   - Fix: Check if image exists at the path shown

3. **Roboflow API Error**
   - Error: "Roboflow detection failed"
   - Fix: Check API key in `api/roboflow_service.php`

4. **OCR Processing Failed**
   - Error: "No meter reading detected"
   - Fix: Image quality might be poor, try retrying or manually entering

5. **Database Error**
   - Error: "Failed to update database"
   - Fix: Check database connection and table structure

## Quick Check:

Open this file in Notepad:
```
C:\xampp\apache\logs\error.log
```

Press `Ctrl + End` to go to the bottom and see the most recent errors.

