#Requires -Version 5.1
<#
.SYNOPSIS
    Fast resumable forward-only 2026 invoice & reservation bulk sync from Bplus SQL Server to PostgreSQL.
    Streams UTF-8 TSV chunks directly via .NET StreamWriter (ultra-fast, zero JSON serialization overhead).
    Reads strictly from erp_sync_cursors forward (DI_DATE >= '20260101' AND DI_KEY > cursor) — never goes backwards.
#>
param(
    [int]$BatchSize = 3000,
    [int]$MaxBatches = 0,
    [string]$StartDate = "20260101"
)

$ErrorActionPreference = "Stop"
Import-Module (Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\Modules\Microsoft.PowerShell.Security\Microsoft.PowerShell.Security.psd1') -ErrorAction Stop
Add-Type -AssemblyName System.Data

$AgentDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RootDir = Split-Path -Parent $AgentDir
$ConfigFile = Join-Path $AgentDir "config.env"
$PhpExe = "D:\xampp\php\php.exe"
$ImporterScript = Join-Path $RootDir "backend\scripts\bulk_upsert_invoices.php"
$LogFile = Join-Path $AgentDir "sync-2026-progress.log"
$TmpInvTsv = Join-Path $AgentDir ".tmp-sync-2026-inv.tsv"
$TmpItemTsv = Join-Path $AgentDir ".tmp-sync-2026-items.tsv"
$Utf8NoBom = [System.Text.UTF8Encoding]::new($false)

function Write-Log {
    param([string]$Msg)
    $line = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $Msg"
    Write-Host $line
    Add-Content -LiteralPath $LogFile -Value $line -Encoding UTF8
}

function Load-ConfigEnv {
    param([string]$Path)
    $map = @{}
    foreach ($line in Get-Content $Path -Encoding UTF8) {
        $trim = $line.Trim()
        if ($trim -eq "" -or $trim.StartsWith("#")) { continue }
        $idx = $trim.IndexOf("=")
        if ($idx -gt 0) {
            $map[$trim.Substring(0, $idx).Trim()] = $trim.Substring($idx + 1).Trim().Trim('"').Trim("'")
        }
    }
    return $map
}

function Format-TsvVal {
    param($val)
    if ($null -eq $val -or $val -is [DBNull]) { return "" }
    if ($val -is [bool]) { return $(if ($val) { "1" } else { "0" }) }
    if ($val -is [DateTime]) { return $val.ToString("yyyy-MM-ddTHH:mm:sszzz") }
    $s = ([string]$val).Trim()
    if ($s.IndexOfAny(@([char]9, [char]10, [char]13)) -ge 0) {
        $s = $s -replace "[\t\r\n]+", " "
    }
    return $s
}

$cfg = Load-ConfigEnv -Path $ConfigFile
$SourceSystem = if ($cfg["SYNC_SOURCE"]) { $cfg["SYNC_SOURCE"] } else { "BPLUS" }

$securePwd = ConvertTo-SecureString -String $cfg["SQL_PASSWORD_DPAPI"]
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePwd)
$pwd = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)

$builder = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
$builder["Data Source"] = "tcp:$($cfg['SQL_SERVER']),$($cfg['SQL_PORT'])"
$builder["Initial Catalog"] = $cfg["SQL_DATABASE"]
$builder["User ID"] = $cfg["SQL_USER"]
$builder["Password"] = $pwd
$builder["Encrypt"] = $true
$builder["TrustServerCertificate"] = $true
$builder["Connect Timeout"] = 20
$connStr = $builder.ConnectionString
$pwd = $null

$cursorRaw = & $PhpExe $ImporterScript --get-cursor $SourceSystem
[long]$cursor = [long]($cursorRaw.Trim())

$HeaderSql = @"
SELECT TOP (@limit)
  di_key,
  branch_code,
  doc_type,
  dt_properties,
  doc_category,
  invoice_no,
  erp_customer_code,
  salesperson_code,
  salesperson_name,
  sold_at,
  before_vat_amount,
  vat_amount,
  gross_amount,
  net_amount,
  status
FROM (
  SELECT
    DOCINFO.DI_KEY AS di_key,
    CAST(COALESCE(BRANCH.BR_CODE, '001') AS varchar(32)) AS branch_code,
    CAST(DOCTYPE.DT_DOCCODE AS varchar(32)) AS doc_type,
    CAST(DOCTYPE.DT_PROPERTIES AS int) AS dt_properties,
    CASE WHEN DOCTYPE.DT_PROPERTIES = 308 THEN 'return' ELSE 'sale' END AS doc_category,
    CAST(DOCINFO.DI_REF AS varchar(80)) AS invoice_no,
    CAST(ARFILE.AR_CODE AS varchar(64)) AS erp_customer_code,
    CAST(COALESCE(SM.SLMN_CODE, ARC_SM.SLMN_CODE, ARFILE.AR_SLMNCODE) AS varchar(32)) AS salesperson_code,
    CAST(COALESCE(SM.SLMN_NAME, ARC_SM.SLMN_NAME) AS nvarchar(120)) AS salesperson_name,
    DOCINFO.DI_DATE AS sold_at,
    CAST(CASE WHEN DOCTYPE.DT_PROPERTIES = 308 THEN -ABS(COALESCE(ARDETAIL.ARD_B_SV, 0) + COALESCE(ARDETAIL.ARD_B_SNV, 0)) ELSE (COALESCE(ARDETAIL.ARD_B_SV, 0) + COALESCE(ARDETAIL.ARD_B_SNV, 0)) END AS decimal(16, 2)) AS before_vat_amount,
    CAST(CASE WHEN DOCTYPE.DT_PROPERTIES = 308 THEN -ABS(COALESCE(ARDETAIL.ARD_B_VAT, 0)) ELSE COALESCE(ARDETAIL.ARD_B_VAT, 0) END AS decimal(16, 2)) AS vat_amount,
    CAST(CASE WHEN DOCTYPE.DT_PROPERTIES = 308 THEN -ABS(COALESCE(ARDETAIL.ARD_B_AMT, 0)) ELSE COALESCE(ARDETAIL.ARD_B_AMT, 0) END AS decimal(16, 2)) AS gross_amount,
    CAST(CASE WHEN DOCTYPE.DT_PROPERTIES = 308 THEN -ABS(COALESCE(ARDETAIL.ARD_B_SV, 0) + COALESCE(ARDETAIL.ARD_B_SNV, 0)) ELSE (COALESCE(ARDETAIL.ARD_B_SV, 0) + COALESCE(ARDETAIL.ARD_B_SNV, 0)) END AS decimal(16, 2)) AS net_amount,
    CASE WHEN DOCINFO.DI_ACTIVE = 1 THEN 'cancelled' WHEN DOCINFO.DI_ACTIVE = 0 AND DOCTYPE.DT_PROPERTIES = 308 THEN 'returned' WHEN DOCINFO.DI_ACTIVE = 0 THEN 'posted' ELSE 'other' END AS status
  FROM DOCINFO
  INNER JOIN DOCTYPE ON DOCINFO.DI_DT = DOCTYPE.DT_KEY
  INNER JOIN ARDETAIL ON DOCINFO.DI_KEY = ARDETAIL.ARD_DI
  INNER JOIN ARFILE ON ARDETAIL.ARD_AR = ARFILE.AR_KEY
  LEFT JOIN TRANSTKH ON DOCINFO.DI_KEY = TRANSTKH.TRH_DI
  LEFT JOIN BRANCH ON TRANSTKH.TRH_BR = BRANCH.BR_KEY
  OUTER APPLY (
    SELECT TOP 1 S.SLMN_CODE, S.SLMN_NAME
    FROM SLDETAIL SD
    INNER JOIN SALESMAN S ON S.SLMN_KEY = SD.SLD_SLMN
    WHERE SD.SLD_DI = DOCINFO.DI_KEY
    ORDER BY S.SLMN_CODE ASC
  ) AS SM
  LEFT JOIN SALESMAN ARC_SM ON ARC_SM.SLMN_CODE = ARFILE.AR_SLMNCODE
  WHERE DOCTYPE.DT_PROPERTIES IN (302, 307, 308, 337)
    AND DOCINFO.DI_REF IS NOT NULL
    AND DOCINFO.DI_DATE >= @startDate
    AND DOCINFO.DI_KEY > @afterKey

  UNION ALL

  SELECT
    DOCINFO.DI_KEY AS di_key,
    CAST(COALESCE(BRANCH.BR_CODE, '001') AS varchar(32)) AS branch_code,
    CAST(DOCTYPE.DT_DOCCODE AS varchar(32)) AS doc_type,
    CAST(DOCTYPE.DT_PROPERTIES AS int) AS dt_properties,
    'reservation' AS doc_category,
    CAST(DOCINFO.DI_REF AS varchar(80)) AS invoice_no,
    CAST(ARFILE.AR_CODE AS varchar(64)) AS erp_customer_code,
    CAST(COALESCE(SM.SLMN_CODE, ARC_SM.SLMN_CODE, ARFILE.AR_SLMNCODE) AS varchar(32)) AS salesperson_code,
    CAST(COALESCE(SM.SLMN_NAME, ARC_SM.SLMN_NAME) AS nvarchar(120)) AS salesperson_name,
    DOCINFO.DI_DATE AS sold_at,
    CAST(COALESCE(AROE.AROE_B_SV, 0) + COALESCE(AROE.AROE_B_SNV, 0) AS decimal(16, 2)) AS before_vat_amount,
    CAST(COALESCE(AROE.AROE_B_VAT, 0) AS decimal(16, 2)) AS vat_amount,
    CAST(COALESCE(AROE.AROE_B_AMT, 0) AS decimal(16, 2)) AS gross_amount,
    CAST(COALESCE(AROE.AROE_B_SV, 0) + COALESCE(AROE.AROE_B_SNV, 0) AS decimal(16, 2)) AS net_amount,
    CASE WHEN DOCINFO.DI_ACTIVE = 1 THEN 'cancelled' WHEN DOCINFO.DI_ACTIVE = 0 THEN 'reserved' ELSE 'other' END AS status
  FROM DOCINFO
  INNER JOIN DOCTYPE ON DOCINFO.DI_DT = DOCTYPE.DT_KEY
  INNER JOIN AROE ON DOCINFO.DI_KEY = AROE.AROE_DI
  INNER JOIN ARFILE ON AROE.AROE_AR = ARFILE.AR_KEY
  LEFT JOIN TRANSTKH ON DOCINFO.DI_KEY = TRANSTKH.TRH_DI
  LEFT JOIN BRANCH ON TRANSTKH.TRH_BR = BRANCH.BR_KEY
  LEFT JOIN SALESMAN SM ON AROE.AROE_SLMN = SM.SLMN_KEY
  LEFT JOIN SALESMAN ARC_SM ON ARC_SM.SLMN_CODE = ARFILE.AR_SLMNCODE
  WHERE DOCTYPE.DT_PROPERTIES = 207
    AND DOCINFO.DI_REF IS NOT NULL
    AND DOCINFO.DI_DATE >= @startDate
    AND DOCINFO.DI_KEY > @afterKey
) AS merged
ORDER BY di_key ASC;
"@

$ItemsSql = @"
SELECT
  CAST(COALESCE(BRANCH.BR_CODE, '001') AS varchar(32)) AS branch_code,
  CAST(DOCTYPE.DT_DOCCODE AS varchar(32)) AS doc_type,
  CAST(DOCINFO.DI_REF AS varchar(80)) AS invoice_no,
  CAST(TRANSTKD.TRD_SEQ AS int) AS line_no,
  CAST(COALESCE(SKUMASTER.SKU_CODE, TRANSTKD.TRD_SH_CODE) AS varchar(64)) AS erp_product_code,
  CAST(COALESCE(SKUMASTER.SKU_NAME, TRANSTKD.TRD_SH_NAME) AS nvarchar(255)) AS product_name,
  CAST(ICCAT.ICCAT_CODE AS varchar(32)) AS iccat_code,
  CAST(ICCAT.ICCAT_NAME AS nvarchar(120)) AS iccat_name,
  CAST(CASE WHEN UPPER(LTRIM(RTRIM(COALESCE(ICCAT.ICCAT_CODE, '')))) IN ('CC', 'CO', 'AS', 'PK') THEN 1 ELSE 0 END AS bit) AS is_excluded_kpi,
  CAST(TRANSTKD.TRD_QTY AS decimal(16, 4)) AS qty,
  CAST(TRANSTKD.TRD_UTQNAME AS nvarchar(30)) AS unit_name,
  CAST(TRANSTKD.TRD_U_PRC AS decimal(16, 4)) AS unit_price,
  CAST(CASE WHEN DOCTYPE.DT_PROPERTIES = 308 THEN -ABS(COALESCE(TRANSTKD.TRD_B_SELL, 0)) ELSE COALESCE(TRANSTKD.TRD_B_SELL, 0) END AS decimal(16, 2)) AS before_vat_amount,
  CAST(CASE WHEN DOCTYPE.DT_PROPERTIES = 308 THEN -ABS(COALESCE(TRANSTKD.TRD_B_VAT, 0)) ELSE COALESCE(TRANSTKD.TRD_B_VAT, 0) END AS decimal(16, 2)) AS vat_amount,
  CAST(CASE WHEN DOCTYPE.DT_PROPERTIES = 308 THEN -ABS(COALESCE(TRANSTKD.TRD_B_AMT, 0)) ELSE COALESCE(TRANSTKD.TRD_B_AMT, 0) END AS decimal(16, 2)) AS gross_amount,
  CAST(CASE WHEN DOCTYPE.DT_PROPERTIES = 308 THEN -ABS(COALESCE(TRANSTKD.TRD_B_SELL, 0)) ELSE COALESCE(TRANSTKD.TRD_B_SELL, 0) END AS decimal(16, 2)) AS net_amount,
  CAST(TRANSTKD.TRD_REFER_TRD AS varchar(64)) AS refer_trd_key,
  CAST(TRANSTKD.TRD_REFER_REF AS varchar(80)) AS refer_doc_ref
FROM DOCINFO
INNER JOIN DOCTYPE ON DOCINFO.DI_DT = DOCTYPE.DT_KEY
INNER JOIN TRANSTKH ON DOCINFO.DI_KEY = TRANSTKH.TRH_DI
INNER JOIN TRANSTKD ON TRANSTKH.TRH_KEY = TRANSTKD.TRD_TRH
LEFT JOIN BRANCH ON TRANSTKH.TRH_BR = BRANCH.BR_KEY
LEFT JOIN SKUMASTER ON TRANSTKD.TRD_SKU = SKUMASTER.SKU_KEY
LEFT JOIN ICCAT ON SKUMASTER.SKU_ICCAT = ICCAT.ICCAT_KEY
WHERE DOCTYPE.DT_PROPERTIES IN (302, 307, 308, 337)
  AND DOCINFO.DI_REF IS NOT NULL
  AND DOCINFO.DI_DATE >= @startDate
  AND DOCINFO.DI_KEY > @minKey
  AND DOCINFO.DI_KEY <= @maxKey
ORDER BY DOCINFO.DI_KEY ASC, TRANSTKD.TRD_SEQ ASC;
"@

$conn = New-Object System.Data.SqlClient.SqlConnection($connStr)
$conn.Open()

$batchNum = 0
$totalInv = 0
$totalItems = 0
$swAll = [System.Diagnostics.Stopwatch]::StartNew()

Write-Log "เริ่มซิงก์บิลปี 2026 (โหมด TSV ความเร็วสูง) ต่อจาก cursor DI_KEY > $cursor (BatchSize=$BatchSize, StartDate=$StartDate)"

try {
    while ($true) {
        if ($MaxBatches -gt 0 -and $batchNum -ge $MaxBatches) {
            Write-Log "ครบจำนวนรอบทดสอบ MaxBatches=$MaxBatches (หยุดชั่วคราวที่ cursor=$cursor)"
            break
        }
        $batchNum++
        $swBatch = [System.Diagnostics.Stopwatch]::StartNew()

        # 1. Stream invoice headers straight to TSV file
        $cmdH = $conn.CreateCommand()
        $cmdH.CommandTimeout = 180
        $cmdH.CommandText = $HeaderSql
        [void]$cmdH.Parameters.Add("@limit", [System.Data.SqlDbType]::Int)
        $cmdH.Parameters["@limit"].Value = $BatchSize
        [void]$cmdH.Parameters.Add("@startDate", [System.Data.SqlDbType]::VarChar, 8)
        $cmdH.Parameters["@startDate"].Value = $StartDate
        [void]$cmdH.Parameters.Add("@afterKey", [System.Data.SqlDbType]::BigInt)
        $cmdH.Parameters["@afterKey"].Value = $cursor

        $invCount = 0
        $maxKey = $cursor
        $lastDate = ""
        $hasSalesInBatch = $false

        $rH = $cmdH.ExecuteReader()
        $swInv = New-Object System.IO.StreamWriter($TmpInvTsv, $false, $Utf8NoBom)
        try {
            $fc = $rH.FieldCount
            $cols = New-Object string[] $fc
            for ($i = 0; $i -lt $fc; $i++) { $cols[$i] = $rH.GetName($i) }
            $swInv.WriteLine([string]::Join("`t", $cols))
            $vals = New-Object string[] $fc
            while ($rH.Read()) {
                for ($i = 0; $i -lt $fc; $i++) {
                    $vals[$i] = Format-TsvVal $rH.GetValue($i)
                }
                $swInv.WriteLine([string]::Join("`t", $vals))
                $dk = [long]$vals[0]
                if ($dk -gt $maxKey) { $maxKey = $dk }
                if ($vals[4] -ne "reservation") { $hasSalesInBatch = $true }
                if ($vals[9].Length -ge 10) { $lastDate = $vals[9].Substring(0, 10) }
                $invCount++
            }
        }
        finally {
            $swInv.Close()
            $rH.Close()
        }

        if ($invCount -eq 0) {
            Write-Log "ไม่มีเอกสารใหม่เหลือแล้ว ซิงก์ครบถึงปัจจุบันแล้วครับ!"
            break
        }

        # 2. Stream sales/return line items straight to TSV file
        $itemCount = 0
        $swIt = New-Object System.IO.StreamWriter($TmpItemTsv, $false, $Utf8NoBom)
        try {
            if ($hasSalesInBatch) {
                $cmdI = $conn.CreateCommand()
                $cmdI.CommandTimeout = 180
                $cmdI.CommandText = $ItemsSql
                [void]$cmdI.Parameters.Add("@startDate", [System.Data.SqlDbType]::VarChar, 8)
                $cmdI.Parameters["@startDate"].Value = $StartDate
                [void]$cmdI.Parameters.Add("@minKey", [System.Data.SqlDbType]::BigInt)
                $cmdI.Parameters["@minKey"].Value = $cursor
                [void]$cmdI.Parameters.Add("@maxKey", [System.Data.SqlDbType]::BigInt)
                $cmdI.Parameters["@maxKey"].Value = $maxKey

                $rI = $cmdI.ExecuteReader()
                try {
                    $ifc = $rI.FieldCount
                    $icols = New-Object string[] $ifc
                    for ($i = 0; $i -lt $ifc; $i++) { $icols[$i] = $rI.GetName($i) }
                    $swIt.WriteLine([string]::Join("`t", $icols))
                    $ivals = New-Object string[] $ifc
                    while ($rI.Read()) {
                        for ($i = 0; $i -lt $ifc; $i++) {
                            $ivals[$i] = Format-TsvVal $rI.GetValue($i)
                        }
                        $swIt.WriteLine([string]::Join("`t", $ivals))
                        $itemCount++
                    }
                }
                finally {
                    $rI.Close()
                }
            }
        }
        finally {
            $swIt.Close()
        }

        # 3. Bulk upsert TSV files into PostgreSQL
        $phpOut = & $PhpExe $ImporterScript --tsv $SourceSystem $maxKey $TmpInvTsv $TmpItemTsv
        if ($LASTEXITCODE -ne 0) {
            throw "PHP bulk_upsert_invoices failed (exit $LASTEXITCODE): $phpOut"
        }

        $cursor = $maxKey
        $totalInv += $invCount
        $totalItems += $itemCount
        $secBatch = [Math]::Round($swBatch.Elapsed.TotalSeconds, 2)
        $rate = if ($swAll.Elapsed.TotalSeconds -gt 0) { [Math]::Round($totalInv / $swAll.Elapsed.TotalSeconds, 0) } else { 0 }
        Write-Log "Batch #$batchNum | บิล +$invCount (สะสม $totalInv) | สินค้าท้ายบิล +$itemCount (สะสม $totalItems) | ถึงวันที่ $lastDate | DI_KEY=$cursor | ใช้เวลา ${secBatch}s (~$rate บิล/วิ)"

        if ($invCount -lt $BatchSize) {
            Write-Log "ถึงเอกสารล่าสุดของปี 2026 แล้ว!"
            break
        }
    }
}
finally {
    if ($conn.State -ne [System.Data.ConnectionState]::Closed) { $conn.Close() }
    $conn.Dispose()
    if (Test-Path -LiteralPath $TmpInvTsv) { Remove-Item -LiteralPath $TmpInvTsv -Force -ErrorAction SilentlyContinue }
    if (Test-Path -LiteralPath $TmpItemTsv) { Remove-Item -LiteralPath $TmpItemTsv -Force -ErrorAction SilentlyContinue }
}

$finalJson = & $PhpExe $ImporterScript --finalize-staff
Write-Log "เสร็จสิ้น! สรุปหลังซิงก์: $finalJson"
