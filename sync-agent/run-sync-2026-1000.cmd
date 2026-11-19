@echo off
chcp 65001 >nul
echo ============================================================
echo POPSTAR ERP sync - 1,000 documents per batch
echo ============================================================
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "%~dp0run-sync-2026-1000.ps1"
set "SYNC_EXIT=%ERRORLEVEL%"
echo.
if not "%SYNC_EXIT%"=="0" echo Sync did not complete. The last completed batch is saved.
pause
exit /b %SYNC_EXIT%
