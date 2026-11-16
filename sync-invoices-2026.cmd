@echo off
setlocal
set "TASK_ROOT=%~dp0"
set "TASK_PHP=D:\xampp\php\php.exe"
if not exist "%TASK_PHP%" (
  echo PHP was not found at %TASK_PHP%.
  pause
  exit /b 1
)
echo POPSTAR invoice sync: up to 1,000 invoices per run.
echo It resumes from the last saved checkpoint.
echo Keep the VPN and Local Bridge running.
echo.
"%TASK_PHP%" "%TASK_ROOT%tools\reconcile-invoices-2026.php" --pages=10
set "TASK_EXIT=%ERRORLEVEL%"
echo.
if not "%TASK_EXIT%"=="0" echo Sync stopped with an error. Read the message above before retrying.
pause
exit /b %TASK_EXIT%
