' Runs "php artisan schedule:run" without flashing a console window.
' Used by the Windows Task Scheduler task "POPSTAR schedule:run" (every minute).
Set fso = CreateObject("Scripting.FileSystemObject")
backend = fso.GetParentFolderName(fso.GetParentFolderName(WScript.ScriptFullName)) & "\backend"
CreateObject("WScript.Shell").Run "cmd /c cd /d """ & backend & """ && C:\php84\php.exe artisan schedule:run >> storage\logs\scheduler.log 2>&1", 0, False
