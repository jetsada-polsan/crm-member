# Reset the password of the "postgres" superuser on the local PostgreSQL 17.
# Run in PowerShell as Administrator:
#   Set-ExecutionPolicy -Scope Process Bypass
#   D:\xampp\htdocs\popstar-business-platform-v4\tools\reset-postgres-password.ps1
#
# What it does:
#   1. Backs up pg_hba.conf and temporarily allows passwordless login from this machine only (127.0.0.1 / ::1)
#   2. Restarts PostgreSQL, sets the new password you type
#   3. Restores the original pg_hba.conf and restarts PostgreSQL again (always, even on error)
#   4. Saves the password to backend\.pgpass-setup.txt (git-ignored) for the POPSTAR setup
$ErrorActionPreference = "Stop"
$svc     = "postgresql-x64-17"
$pgRoot  = "C:\Program Files\PostgreSQL\17"
$hba     = "$pgRoot\data\pg_hba.conf"
$psql    = "$pgRoot\bin\psql.exe"
$pgpass  = Join-Path (Split-Path $PSScriptRoot) "backend\.pgpass-setup.txt"

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) { Write-Host "Please run this in PowerShell as Administrator." -ForegroundColor Red; exit 1 }

$p1 = Read-Host "New postgres password" -AsSecureString
$p2 = Read-Host "Type it again" -AsSecureString
$pw  = [Net.NetworkCredential]::new('', $p1).Password
if ($pw -ne [Net.NetworkCredential]::new('', $p2).Password) { Write-Host "Passwords do not match." -ForegroundColor Red; exit 1 }
if ($pw.Length -lt 8) { Write-Host "Use at least 8 characters." -ForegroundColor Red; exit 1 }

$backup = "$hba.bak-reset"
Copy-Item $hba $backup -Force
try {
    $lines = Get-Content $hba | ForEach-Object {
        if ($_ -match '^\s*host\s+all\s+all\s+(127\.0\.0\.1/32|::1/128)\s+scram-sha-256') { $_ -replace 'scram-sha-256', 'trust' } else { $_ }
    }
    Set-Content $hba $lines -Encoding ASCII
    Restart-Service $svc
    Start-Sleep 2

    $escaped = $pw.Replace("'", "''")
    & $psql -U postgres -h 127.0.0.1 -w -c "ALTER USER postgres WITH PASSWORD '$escaped'" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "ALTER USER failed" }
    Write-Host "[OK] postgres password changed" -ForegroundColor Green
}
finally {
    Copy-Item $backup $hba -Force
    Restart-Service $svc
    Write-Host "[OK] pg_hba.conf restored, PostgreSQL restarted" -ForegroundColor Green
}

# Verify the new password works with normal (scram) authentication
$env:PGPASSWORD = $pw
& $psql -U postgres -h 127.0.0.1 -w -tAc "select 'login ok'"
$ok = $LASTEXITCODE -eq 0
Remove-Item Env:PGPASSWORD

if ($ok) {
    [IO.File]::WriteAllText($pgpass, $pw)
    Write-Host "[OK] Saved to $pgpass" -ForegroundColor Green
    Write-Host "Done. Tell Claude: password reset done." -ForegroundColor Cyan
} else {
    Write-Host "Login test failed - password file not written." -ForegroundColor Red
}
