# Stops the CRM Sync Agent monitor so its supervisor (task "CRM Sync Agent 201") relaunches it with the current code.
$log = 'D:\Xamp\htdocs\sync-agent\restart-crm-agent-201.log'
$procs = Get-CimInstance Win32_Process -Filter "Name = 'python.exe'" | Where-Object { $_.CommandLine -like '*start-monitor-vpn-201.py*' }
foreach ($p in $procs) {
    Stop-Process -Id $p.ProcessId -Force
    Add-Content -Path $log -Value "$(Get-Date -Format s) stopped monitor pid $($p.ProcessId)"
}
if (-not $procs) { Add-Content -Path $log -Value "$(Get-Date -Format s) monitor process not found" }
