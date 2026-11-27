# POPSTAR Business Platform — Automated Daily PostgreSQL Backup
# Reads database connection settings from backend/.env without logging secrets.
# Writes compressed custom-format dumps (.dump) into _backups/db/ and retains 14 days.

$ErrorActionPreference = 'Stop'

$RootDir = Split-Path -Parent $PSScriptRoot
$EnvFile = Join-Path $RootDir 'backend\.env'
$BackupDir = Join-Path $RootDir '_backups\db'
$LogFile = Join-Path $BackupDir 'backup.log'
$RetentionDays = 14

if (-not (Test-Path $EnvFile)) {
    throw "Missing backend/.env at $EnvFile"
}

$PgDumpCandidates = @(
    'C:\Program Files\PostgreSQL\17\bin\pg_dump.exe',
    'C:\Program Files\PostgreSQL\16\bin\pg_dump.exe',
    'C:\Program Files\PostgreSQL\15\bin\pg_dump.exe'
)
$PgDump = $PgDumpCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $PgDump) {
    $cmd = Get-Command pg_dump.exe -ErrorAction SilentlyContinue
    if ($cmd) { $PgDump = $cmd.Source }
}
if (-not $PgDump) {
    throw 'pg_dump.exe not found in PostgreSQL installation or PATH'
}

$envMap = @{}
foreach ($line in Get-Content $EnvFile) {
    $trimmed = $line.Trim()
    if ($trimmed -eq '' -or $trimmed.StartsWith('#')) { continue }
    $idx = $trimmed.IndexOf('=')
    if ($idx -lt 1) { continue }
    $k = $trimmed.Substring(0, $idx).Trim()
    $v = $trimmed.Substring($idx + 1).Trim().Trim('"').Trim("'")
    $envMap[$k] = $v
}

$dbHost = if ($envMap['DB_HOST']) { $envMap['DB_HOST'] } else { '127.0.0.1' }
$dbPort = if ($envMap['DB_PORT']) { $envMap['DB_PORT'] } else { '5432' }
$dbName = if ($envMap['DB_DATABASE']) { $envMap['DB_DATABASE'] } else { 'popstar_business' }
$dbUser = if ($envMap['DB_USERNAME']) { $envMap['DB_USERNAME'] } else { 'postgres' }
$dbPass = $envMap['DB_PASSWORD']

New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null

$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$outFile = Join-Path $BackupDir ("${dbName}_${stamp}.dump")

$prevPgPass = $env:PGPASSWORD
try {
    if ($null -ne $dbPass) {
        $env:PGPASSWORD = $dbPass
    }
    & $PgDump -h $dbHost -p $dbPort -U $dbUser -F c -b -f $outFile $dbName
    if ($LASTEXITCODE -ne 0) {
        throw "pg_dump exited with code $LASTEXITCODE"
    }
} finally {
    if ($null -eq $prevPgPass) {
        Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
    } else {
        $env:PGPASSWORD = $prevPgPass
    }
}

$sizeBytes = (Get-Item $outFile).Length
$sizeMB = [math]::Round($sizeBytes / 1MB, 2)

# Prune dumps older than $RetentionDays
$cutoff = (Get-Date).AddDays(-$RetentionDays)
$pruned = @(Get-ChildItem -Path $BackupDir -Filter "${dbName}_*.dump" -File | Where-Object { $_.LastWriteTime -lt $cutoff })
foreach ($old in $pruned) {
    Remove-Item $old.FullName -Force -ErrorAction SilentlyContinue
}

$msg = "[{0}] OK: {1} ({2} MB), pruned={3}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), (Split-Path -Leaf $outFile), $sizeMB, $pruned.Count
Add-Content -Path $LogFile -Value $msg
Write-Output $msg
