@echo off
REM ============================================================
REM SmartTerminal backend launcher (LavaLust / PHP built-in server)
REM ------------------------------------------------------------
REM FIX: The old server on port 8000 was started as:
REM   php -S localhost:8000          <- WRONG: no docroot/router, so
REM                                     /api/login always 404'd and
REM                                     the UI showed "Login failed".
REM
REM Correct command (this file):
REM   php -S localhost:8000 -t public public/router.php
REM ============================================================

setlocal
cd /d "%~dp0"

REM Kill anything already listening on :8000 (old broken server)
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":8000" ^| findstr LISTENING') do (
    echo Killing old process on port 8000: PID %%a
    taskkill /F /PID %%a >nul 2>&1
)

set "PHP_EXE=C:\wamp64\bin\php\php8.4.0\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=php"

echo.
echo Starting SmartTerminal backend on http://localhost:8000 ...
echo (Keep this window open. Press Ctrl+C to stop.)
echo.

"%PHP_EXE%" -S localhost:8000 -t "%~dp0public" "%~dp0public\router.php"

endlocal
