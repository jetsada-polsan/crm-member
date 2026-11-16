' Runs every minute from the Windows Task Scheduler task "POPSTAR schedule-run",
' without flashing a console window:
'   1. php artisan schedule:run
'   2. php artisan queue:work --stop-when-empty  (drains queued jobs when the site runs on Apache
'      and start-popstar.bat's long-running worker is not open)
Set fso = CreateObject("Scripting.FileSystemObject")
backend = fso.GetParentFolderName(fso.GetParentFolderName(WScript.ScriptFullName)) & "\backend"
php = "D:\xampp\php\php.exe"
cmd = "cmd /c cd /d """ & backend & """ && " & _
      php & " artisan schedule:run >> storage\logs\scheduler.log 2>&1 & " & _
      php & " artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> storage\logs\queue.log 2>&1"
CreateObject("WScript.Shell").Run cmd, 0, False
