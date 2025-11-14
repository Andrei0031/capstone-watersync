@echo off
echo ========================================
echo jTessBoxEditor Launcher
echo ========================================
echo.
echo Starting jTessBoxEditor...
echo.

cd /d "%~dp0"
java -Xmx1024m -jar jTessBoxEditor.jar

if %ERRORLEVEL% neq 0 (
    echo.
    echo ERROR: Failed to start jTessBoxEditor
    echo.
    echo Make sure Java is installed: java -version
    echo.
    pause
)

