$ErrorActionPreference = 'Stop'

$bridgeScript = Join-Path $PSScriptRoot 'local_bridge.ps1'
if (-not (Test-Path -LiteralPath $bridgeScript -PathType Leaf)) {
    throw "Bridge script not found: $bridgeScript"
}

$existing = Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" |
    Where-Object { $_.CommandLine -and $_.CommandLine.IndexOf($bridgeScript, [StringComparison]::OrdinalIgnoreCase) -ge 0 }

foreach ($process in $existing) {
    Write-Host "Stopping this project's old bridge (PID $($process.ProcessId))..."
    Stop-Process -Id $process.ProcessId -ErrorAction Stop
}

$powershell = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
$bridge = Start-Process -FilePath $powershell -ArgumentList @(
    '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"' + $bridgeScript + '"')
) -WindowStyle Hidden -PassThru

for ($attempt = 1; $attempt -le 15; $attempt++) {
    Start-Sleep -Seconds 1
    try {
        $health = Invoke-RestMethod -Uri 'http://127.0.0.1:18900/health' -TimeoutSec 2
        if ($health.ok -and $health.sql_configured -and -not $bridge.HasExited) {
            Write-Host "Bridge restarted successfully (PID $($bridge.Id)); SQL settings are present."
            Write-Host 'Ready for the invoice sync. Return to Codex and say: opened.'
            exit 0
        }
    } catch {
        if ($bridge.HasExited) { break }
    }
}

throw 'Bridge did not start successfully or SQL settings are missing. Check whether port 18900 is already in use and review the bridge configuration.'
