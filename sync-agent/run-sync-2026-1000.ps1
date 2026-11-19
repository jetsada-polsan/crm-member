# Run the existing forward sync in 1,000-document batches until it reaches the end.
$ErrorActionPreference = 'Stop'
$syncScript = Join-Path $PSScriptRoot 'sync-2026-invoices.ps1'
$mutex = [System.Threading.Mutex]::new($false, 'Local\PopstarInvoiceSync2026_1000')
$ownsMutex = $false

try {
    try {
        $ownsMutex = $mutex.WaitOne(0)
    } catch [System.Threading.AbandonedMutexException] {
        $ownsMutex = $true
    }
    if (-not $ownsMutex) {
        throw 'Another copy of this 1,000-document sync is already running.'
    }
    if (-not (Test-Path -LiteralPath $syncScript -PathType Leaf)) {
        throw 'The invoice sync script could not be found.'
    }

    $otherSync = Get-CimInstance Win32_Process -Filter "Name='powershell.exe' OR Name='pwsh.exe'" |
        Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -match '(?i)[\\/]sync-2026-invoices\.ps1(?:\s|"|$)' }
    if ($otherSync) {
        throw 'An invoice sync is already running. Close the other sync before starting this one.'
    }

    Write-Host 'POPSTAR invoice sync: 1,000 documents per batch; continuing from the saved cursor.'
    Write-Host 'Keep this window open. Press Ctrl+C to stop; the last completed batch is saved.'
    Write-Host 'Progress log: sync-agent\sync-2026-progress.log'
    Write-Host ''
    & $syncScript -BatchSize 1000 -MaxBatches 0
} catch {
    Write-Host ''
    Write-Host 'Sync stopped before completion. Check the message below and the progress log.' -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
} finally {
    if ($ownsMutex) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
