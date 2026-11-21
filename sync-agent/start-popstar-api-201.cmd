@echo off
setlocal EnableDelayedExpansion

REM Detect if accidentally launched over a UNC network share from another PC
echo "%~dp0" | findstr /B /C:"\"\\" >nul
if %errorlevel%==0 (
    echo ============================================================================
    echo [WARNING] Detected UNC Network Share path: %~dp0
    echo Please run this file INSIDE Server 201 (TightVNC / server-bk) from local drive
    echo e.g. D:\Xamp\htdocs\sync-agent\start-popstar-api-201.cmd (NOT via \\192.168.88.201\)
    echo ============================================================================
    pause
    exit /b 1
)

cd /d "%~dp0"
echo Starting POPSTAR CRM Python API Monitor (Dedicated Port 8770 - Separate from bplusAPI 8765)...

set "PY_CMD="
for %%P in (
    "py"
    "python"
    "C:\Python313\python.exe"
    "C:\Python312\python.exe"
    "C:\Python311\python.exe"
    "C:\Python310\python.exe"
    "%LOCALAPPDATA%\Programs\Python\Python313\python.exe"
    "%LOCALAPPDATA%\Programs\Python\Python312\python.exe"
    "%LOCALAPPDATA%\Programs\Python\Python311\python.exe"
    "%LOCALAPPDATA%\Programs\Python\Python310\python.exe"
    "C:\Program Files\Python313\python.exe"
    "C:\Program Files\Python312\python.exe"
    "C:\Program Files\Python311\python.exe"
) do (
    if not defined PY_CMD (
        %%~P --version >nul 2>&1
        if !errorlevel!==0 set "PY_CMD=%%~P"
    )
)

if not defined PY_CMD (
    echo [ERROR] Python 3 not found on this machine.
    echo Check where Python is installed on Server 201 ^(e.g. "where python"^) and run:
    echo   python popstar_api_monitor_201.py
    pause
    exit /b 1
)

echo Using Python: %PY_CMD%
"%PY_CMD%" popstar_api_monitor_201.py
pause
