@echo off
rem POPSTAR Platform - local start (replaces docker compose up)
rem Backend  : http://127.0.0.1:8000
rem CRM      : http://127.0.0.1:18081
rem Member   : http://127.0.0.1:18082
setlocal
set "ROOT=%~dp0"
set "PHP=C:\php84\php.exe"
if not exist "%PHP%" ( echo [ERROR] PHP not found at %PHP% & pause & exit /b 1 )

net start postgresql-x64-17 >nul 2>&1

cd /d "%ROOT%backend"
start "POPSTAR backend :8000" "%PHP%" artisan serve --host=127.0.0.1 --port=8000
start "POPSTAR queue worker" "%PHP%" artisan queue:work --tries=3 --sleep=3

cd /d "%ROOT%"
start "POPSTAR CRM :18081" node tools\serve-spa.mjs crm 18081 http://127.0.0.1:8000
start "POPSTAR Member :18082" node tools\serve-spa.mjs member 18082 http://127.0.0.1:8000

timeout /t 3 >nul
start "" http://127.0.0.1:18081
echo POPSTAR started. Close the 4 windows to stop.
endlocal
