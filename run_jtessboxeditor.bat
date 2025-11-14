@echo off
echo ========================================
echo jTessBoxEditor Launcher
echo ========================================
echo.

REM Check if Java is installed
java -version >nul 2>&1
if %ERRORLEVEL% neq 0 (
    echo ERROR: Java is not installed or not in PATH!
    echo.
    echo Please install Java from: https://www.java.com/download/
    echo.
    pause
    exit /b 1
)

echo Java is installed. Starting jTessBoxEditor...
echo.

REM Change to the directory where jTessBoxEditor.jar is located
REM Update this path to where you saved jTessBoxEditor.jar
set JTESSBOXEDITOR_PATH=C:\TesseractTools

REM Check if jTessBoxEditor.jar exists
if not exist "%JTESSBOXEDITOR_PATH%\jTessBoxEditor.jar" (
    echo ERROR: jTessBoxEditor.jar not found!
    echo.
    echo Expected location: %JTESSBOXEDITOR_PATH%\jTessBoxEditor.jar
    echo.
    echo Please:
    echo 1. Download jTessBoxEditor from: https://sourceforge.net/projects/vietocr/files/jTessBoxEditor/
    echo 2. Save jTessBoxEditor.jar to: %JTESSBOXEDITOR_PATH%\
    echo 3. Or edit this batch file and update JTESSBOXEDITOR_PATH
    echo.
    pause
    exit /b 1
)

REM Run jTessBoxEditor
cd /d "%JTESSBOXEDITOR_PATH%"
java -Xmx1024m -jar jTessBoxEditor.jar

if %ERRORLEVEL% neq 0 (
    echo.
    echo ERROR: Failed to start jTessBoxEditor
    echo.
    pause
)

