@echo off
echo ========================================
echo Tesseract OCR Installation Helper
echo ========================================
echo.
echo This script will help you verify Tesseract installation.
echo.
echo Step 1: Download Tesseract OCR
echo   Visit: https://github.com/UB-Mannheim/tesseract/wiki
echo   Download: tesseract-ocr-w64-setup-5.x.x.exe
echo.
echo Step 2: Install Tesseract
echo   - Run the installer
echo   - Install to: C:\Program Files\Tesseract-OCR\
echo   - IMPORTANT: Check "Add to PATH" during installation
echo.
echo Step 3: Testing installation...
echo.

REM Check common installation paths
set TESSERACT_FOUND=0

if exist "C:\Program Files\Tesseract-OCR\tesseract.exe" (
    echo [OK] Found at: C:\Program Files\Tesseract-OCR\tesseract.exe
    set TESSERACT_FOUND=1
    set TESSERACT_PATH=C:\Program Files\Tesseract-OCR\tesseract.exe
)

if exist "C:\Program Files (x86)\Tesseract-OCR\tesseract.exe" (
    echo [OK] Found at: C:\Program Files (x86)\Tesseract-OCR\tesseract.exe
    set TESSERACT_FOUND=1
    set TESSERACT_PATH=C:\Program Files (x86)\Tesseract-OCR\tesseract.exe
)

if exist "C:\Tesseract-OCR\tesseract.exe" (
    echo [OK] Found at: C:\Tesseract-OCR\tesseract.exe
    set TESSERACT_FOUND=1
    set TESSERACT_PATH=C:\Tesseract-OCR\tesseract.exe
)

REM Test if tesseract is in PATH
where tesseract >nul 2>&1
if %ERRORLEVEL% == 0 (
    echo [OK] Found in PATH
    set TESSERACT_FOUND=1
    set TESSERACT_PATH=tesseract
)

echo.
if %TESSERACT_FOUND% == 1 (
    echo ========================================
    echo SUCCESS: Tesseract is installed!
    echo ========================================
    echo.
    echo Testing version...
    "%TESSERACT_PATH%" --version
    echo.
    echo Next steps:
    echo 1. Test OCR: http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php?action=test
    echo 2. Start training: http://192.168.100.5/CAPSTONE/train_tesseract_watermeter.php?action=upload
    echo.
) else (
    echo ========================================
    echo ERROR: Tesseract OCR not found!
    echo ========================================
    echo.
    echo Installation steps:
    echo 1. Download from: https://github.com/UB-Mannheim/tesseract/wiki
    echo 2. Install to: C:\Program Files\Tesseract-OCR\
    echo 3. Check "Add to PATH" during installation
    echo 4. Restart this script to verify
    echo.
    echo After installation, restart XAMPP/Apache for PHP to detect it.
    echo.
)

pause

