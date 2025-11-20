@echo off
echo ========================================
echo Roboflow Dataset Uploader
echo ========================================
echo.

REM Check if Python is installed
python --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Python is not installed or not in PATH
    echo Please install Python from https://www.python.org/
    pause
    exit /b 1
)

echo Checking dependencies...
echo.

REM Install required packages
echo Installing required packages (this may take a minute)...
pip install roboflow opencv-python numpy --quiet

if errorlevel 1 (
    echo.
    echo WARNING: Some packages may have failed to install
    echo Trying to run anyway...
    echo.
)

echo.
echo ========================================
echo Starting Upload Script...
echo ========================================
echo.

REM Run the upload script
python upload_to_roboflow.py

echo.
echo ========================================
echo Script finished!
echo ========================================
pause

