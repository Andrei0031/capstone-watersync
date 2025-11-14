@echo off
echo ========================================
echo jTessBoxEditorFX Launcher
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

echo Java is installed. Starting jTessBoxEditorFX...
echo.

REM Update this path to where you extracted jTessBoxEditorFX-2.7.0.zip
set JTESSBOXEDITORFX_PATH=C:\TesseractTools\jTessBoxEditorFX-2.7.0

REM Try to find the .jar file
set JAR_FILE=
if exist "%JTESSBOXEDITORFX_PATH%\jTessBoxEditorFX.jar" (
    set JAR_FILE=jTessBoxEditorFX.jar
) else if exist "%JTESSBOXEDITORFX_PATH%\jTessBoxEditor.jar" (
    set JAR_FILE=jTessBoxEditor.jar
) else (
    echo Searching for .jar file in %JTESSBOXEDITORFX_PATH%...
    for %%f in ("%JTESSBOXEDITORFX_PATH%\*.jar") do (
        set JAR_FILE=%%~nxf
        goto :found
    )
    :found
)

if "%JAR_FILE%"=="" (
    echo ERROR: jTessBoxEditorFX.jar not found!
    echo.
    echo Expected location: %JTESSBOXEDITORFX_PATH%\
    echo.
    echo Please:
    echo 1. Extract jTessBoxEditorFX-2.7.0.zip
    echo 2. Update JTESSBOXEDITORFX_PATH in this batch file
    echo 3. Or place this batch file in the extracted folder
    echo.
    pause
    exit /b 1
)

echo Found: %JAR_FILE%
echo.

REM Run jTessBoxEditorFX
cd /d "%JTESSBOXEDITORFX_PATH%"
java -Xmx1024m -jar %JAR_FILE%

if %ERRORLEVEL% neq 0 (
    echo.
    echo ERROR: Failed to start jTessBoxEditorFX
    echo.
    echo Try:
    echo 1. Check Java version: java -version
    echo 2. Try with more memory: java -Xmx2048m -jar %JAR_FILE%
    echo 3. Check if JavaFX is installed (for JavaFX version)
    echo.
    pause
)

