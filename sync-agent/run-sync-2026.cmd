@echo off
chcp 65001 >nul
echo ============================================================
echo   POPSTAR Fast Forward-Only 2026 ERP Sync (Bplus -> CRM)
echo   ดึงเฉพาะบิลขายและใบจองปี 2026 ที่เหลือต่อจากจุดล่าสุด
echo ============================================================
C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0sync-2026-invoices.ps1" %*
echo.
pause
