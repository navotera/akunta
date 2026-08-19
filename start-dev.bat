@echo off
setlocal

cd /d "%~dp0"

echo Starting Akunta development services...
echo Laravel API: http://127.0.0.1:8000
echo Accounting Web: http://127.0.0.1:5175
echo.

where bun >nul 2>&1
if not errorlevel 1 (
    bun run dev
    if errorlevel 1 (
        echo.
        echo Akunta development services stopped with an error.
        exit /b 1
    )
    exit /b 0
)

echo Bun is not available; starting the services with PHP and Node directly.
start "Akunta API" /D "%~dp0apps\accounting" php.exe -S 127.0.0.1:8000 -t public
start "Akunta Web" /D "%~dp0apps\accounting-web" node.exe node_modules/vite/bin/vite.js dev --host 127.0.0.1 --port 5175

echo.
echo Services started in separate windows. Close those windows to stop them.
exit /b 0
