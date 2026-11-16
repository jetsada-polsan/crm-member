#Requires -Version 5.1
<#
.SYNOPSIS
    Local Read-Only Windows ERP Bridge for Popstar Business Platform V4 (Phase 1).
    Connects to Bplus Microsoft SQL Server over Windows VPN using SELECT-only queries.
#>

Add-Type -AssemblyName System.Data

$AgentDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$QueriesDir = Join-Path $AgentDir "queries"
$ConfigFile = Join-Path $AgentDir "config.env"

function Load-ConfigEnv {
    param([string]$Path)
    $map = @{}
    if (Test-Path $Path) {
        foreach ($line in Get-Content $Path -Encoding UTF8) {
            $trim = $line.Trim()
            if ($trim -eq "" -or $trim.StartsWith("#")) { continue }
            $idx = $trim.IndexOf("=")
            if ($idx -gt 0) {
                $k = $trim.Substring(0, $idx).Trim()
                $v = $trim.Substring($idx + 1).Trim().Trim('"').Trim("'")
                $map[$k] = $v
            }
        }
    }
    return $map
}

$cfg = Load-ConfigEnv -Path $ConfigFile
$BridgeToken = if ($env:BRIDGE_API_TOKEN) { $env:BRIDGE_API_TOKEN } elseif ($cfg["BRIDGE_API_TOKEN"]) { $cfg["BRIDGE_API_TOKEN"] } else { "" }
$SyncToken = if ($env:SYNC_API_TOKEN) { $env:SYNC_API_TOKEN } elseif ($cfg["SYNC_API_TOKEN"]) { $cfg["SYNC_API_TOKEN"] } else { "" }
$SourceSystem = if ($env:SYNC_SOURCE) { $env:SYNC_SOURCE } elseif ($cfg["SYNC_SOURCE"]) { $cfg["SYNC_SOURCE"] } else { "BPLUS" }
$SqlConnStr = ""
$Port = if ($env:BRIDGE_PORT) { [int]$env:BRIDGE_PORT } elseif ($cfg["BRIDGE_PORT"]) { [int]$cfg["BRIDGE_PORT"] } else { 18900 }

function Get-ConfiguredSqlConnectionString {
    param([hashtable]$Config)

    if ($env:SQL_CONNECTION_STRING) {
        Assert-AuthorizedCertificateBypass -ConnStr $env:SQL_CONNECTION_STRING
        return $env:SQL_CONNECTION_STRING
    }
    if ($Config["SQL_CONNECTION_STRING"]) {
        Assert-AuthorizedCertificateBypass -ConnStr $Config["SQL_CONNECTION_STRING"]
        return $Config["SQL_CONNECTION_STRING"]
    }

    if (-not $Config["SQL_PASSWORD_DPAPI"]) { return "" }
    foreach ($required in @("SQL_SERVER", "SQL_PORT", "SQL_DATABASE", "SQL_USER")) {
        if (-not $Config[$required]) { throw "$required is required with SQL_PASSWORD_DPAPI." }
    }

    $trustRaw = [string]$Config["SQL_TRUST_SERVER_CERTIFICATE"]
    if ($trustRaw -and $trustRaw -notmatch '^(?i:true|false|yes|no|1|0)$') {
        throw "SQL_TRUST_SERVER_CERTIFICATE must be true or false."
    }
    $trustServerCertificate = $trustRaw -match '^(?i:true|yes|1)$'
    if ($trustServerCertificate -and (
        [string]$Config["SQL_SERVER"] -cne "192.168.88.200" -or
        [string]$Config["SQL_PORT"] -cne "1433" -or
        [string]$Config["SQL_DATABASE"] -cne "BPLUSERP_POPSTAR_2021"
    )) {
        throw "TrustServerCertificate is authorized only for 192.168.88.200:1433 / BPLUSERP_POPSTAR_2021."
    }

    $securePassword = ConvertTo-SecureString -String $Config["SQL_PASSWORD_DPAPI"]
    $passwordPtr = [IntPtr]::Zero
    try {
        $passwordPtr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
        $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPtr)
        $builder = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
        $builder["Data Source"] = "tcp:$($Config['SQL_SERVER']),$($Config['SQL_PORT'])"
        $builder["Initial Catalog"] = $Config["SQL_DATABASE"]
        $builder["User ID"] = $Config["SQL_USER"]
        $builder["Password"] = $password
        $builder["Encrypt"] = $true
        $builder["TrustServerCertificate"] = $trustServerCertificate
        $builder["Connect Timeout"] = 15
        return $builder.ConnectionString
    }
    finally {
        if ($passwordPtr -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPtr)
        }
        if ($null -ne $securePassword) { $securePassword.Dispose() }
        if ($null -ne $password) { $password = $null }
    }
}

function Assert-AuthorizedCertificateBypass {
    param([string]$ConnStr)

    if ($ConnStr -match '(?i)(^|;)\s*(Driver|DSN)\s*=') {
        if ($ConnStr -match '(?i)(TrustServerCertificate|Trust Server Certificate)\s*=\s*(true|yes|1)') {
            throw "TrustServerCertificate bypass is allowed only through the scoped .NET SqlClient configuration."
        }
        return
    }

    $builder = New-Object System.Data.SqlClient.SqlConnectionStringBuilder($ConnStr)
    if (-not [bool]$builder["TrustServerCertificate"]) { return }
    if (-not [bool]$builder["Encrypt"]) {
        throw "TrustServerCertificate requires Encrypt=True."
    }
    if ([string]$builder["Data Source"] -notmatch '^(?i)(tcp:)?192\.168\.88\.200,1433$' -or
        [string]$builder["Initial Catalog"] -cne "BPLUSERP_POPSTAR_2021") {
        throw "TrustServerCertificate is authorized only for 192.168.88.200:1433 / BPLUSERP_POPSTAR_2021."
    }
}

if ($BridgeToken.Length -lt 32 -or $BridgeToken -match '^(REPLACE_|CHANGE_ME|YOUR_)') {
    throw "BRIDGE_API_TOKEN must be explicitly configured with at least 32 characters."
}
if ($SyncToken -and [string]::Equals($BridgeToken, $SyncToken, [System.StringComparison]::Ordinal)) {
    throw "BRIDGE_API_TOKEN must be different from SYNC_API_TOKEN."
}

function Test-ConstantTimeString {
    param([string]$Expected, [string]$Provided)
    if ($null -eq $Expected -or $null -eq $Provided -or $Expected.Length -ne $Provided.Length) {
        return $false
    }
    $difference = 0
    for ($i = 0; $i -lt $Expected.Length; $i++) {
        $difference = $difference -bor ([int][char]$Expected[$i] -bxor [int][char]$Provided[$i])
    }
    return $difference -eq 0
}

function Convert-SqlValue {
    param($val)
    if ($null -eq $val -or $val -is [DBNull]) { return $null }
    if ($val -is [DateTime]) { return $val.ToString("yyyy-MM-ddTHH:mm:sszzz") }
    if ($val -is [Decimal] -or $val -is [Double] -or $val -is [Single]) { return [string]$val }
    return ([string]$val).Trim()
}

function Assert-SafeConnectionString {
    param([string]$ConnStr)
    if (-not $ConnStr) {
        throw "SQL_CONNECTION_STRING is not configured in sync-agent\config.env"
    }
    if ($ConnStr -match '(?i)(^|;)\s*(User Id|Uid)\s*=\s*sa\s*(;|$)') {
        throw "Blocked forbidden privileged SQL account 'sa'. Use a dedicated read-only SQL Server login."
    }
}

function Test-IsOdbcConnectionString {
    param([string]$ConnStr)
    return ($ConnStr -match '(?i)(^|;)\s*(Driver|DSN)\s*=')
}

function Open-ReadOnlyConnection {
    param([string]$ConnStr)
    Assert-SafeConnectionString -ConnStr $ConnStr
    if (Test-IsOdbcConnectionString -ConnStr $ConnStr) {
        $conn = New-Object System.Data.Odbc.OdbcConnection($ConnStr)
        $conn.ConnectionTimeout = 15
        $conn.Open()
        return @{ Connection = $conn; Mode = "ODBC" }
    }
    $conn = New-Object System.Data.SqlClient.SqlConnection($ConnStr)
    $conn.Open()
    return @{ Connection = $conn; Mode = "SqlClient" }
}

function Get-SqlErrorMetadata {
    param($Exception)
    $errCode = $null
    $sqlState = $null
    $msg = $Exception.Message
    $cur = $Exception
    while ($null -ne $cur) {
        if ($cur -is [System.Data.SqlClient.SqlException]) {
            $errCode = $cur.Number
            $sqlState = "Class:$($cur.Class),State:$($cur.State)"
            $msg = $cur.Message
            break
        }
        elseif ($cur -is [System.Data.Odbc.OdbcException] -and $cur.Errors.Count -gt 0) {
            $errCode = $cur.Errors[0].NativeError
            $sqlState = $cur.Errors[0].SQLState
            $msg = $cur.Message
            break
        }
        $cur = $cur.InnerException
    }
    $msg = ($msg -replace '(?i)(Password|Pwd)\s*=\s*[^;]*', '$1=***')
    return @{ error_code = $errCode; sqlstate = $sqlState; message = $msg }
}

function Invoke-ReadOnlySql {
    param(
        [string]$SqlFile,
        [int]$Limit = 0,
        [long]$DocumentKey = 0,
        [long]$AfterDocumentKey = -1,
        [long]$AfterRowKey = -1
    )
    Assert-SafeConnectionString -ConnStr $SqlConnStr
    $sqlPath = Join-Path $QueriesDir $SqlFile
    if (-not (Test-Path $sqlPath)) {
        throw "Query file not found: $SqlFile"
    }
    $sql = Get-Content $sqlPath -Raw -Encoding UTF8
    $sqlClean = ($sql -replace '(?m)--[^\r\n]*', '' -replace '/\*[\s\S]*?\*/', '').Trim()
    if ($sqlClean -notmatch '^(?is)(SELECT|WITH)\b') {
        throw "Blocked non-SELECT query in $SqlFile"
    }
    if ($sqlClean -match '(?is)\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|EXEC|EXECUTE|MERGE|GRANT|REVOKE|INTO\s+OUTFILE)\b') {
        throw "Blocked write/DML keyword in read-only query $SqlFile"
    }
    if ($Limit -lt 0 -or $Limit -gt 50000) {
        throw "Invalid limit parameter: $Limit"
    }

    $opened = Open-ReadOnlyConnection -ConnStr $SqlConnStr
    $conn = $opened.Connection
    $mode = $opened.Mode
    if ($Limit -gt 0) {
        if ($mode -eq "ODBC") {
            $sql = $sql -replace 'SELECT TOP \(\?\)', "SELECT TOP ($Limit)"
        } else {
            $sql = $sql -replace 'SELECT TOP \(\?\)', 'SELECT TOP (@limit)'
        }
    }
    if ($DocumentKey -gt 0) {
        if ($mode -ne "ODBC") {
            $sql = $sql -replace 'DOCINFO\.DI_KEY = \?', 'DOCINFO.DI_KEY = @document_key'
        }
    }
    if ($AfterDocumentKey -ge 0 -and $mode -ne "ODBC") {
        $sql = $sql -replace 'DOCINFO\.DI_KEY > \?', 'DOCINFO.DI_KEY > @after_document_key'
    }
    if ($AfterRowKey -ge 0 -and $mode -ne "ODBC") {
        $sql = $sql -replace 'ARFILE\.AR_KEY > \?', 'ARFILE.AR_KEY > @after_row_key'
        $sql = $sql -replace 'SKUMASTER\.SKU_KEY > \?', 'SKUMASTER.SKU_KEY > @after_row_key'
        $sql = $sql -replace 'SALESMAN\.SLMN_KEY > \?', 'SALESMAN.SLMN_KEY > @after_row_key'
        $sql = $sql -replace 'MEMBER\.MB_KEY > \?', 'MEMBER.MB_KEY > @after_row_key'
    }

    try {
        $cmd = $conn.CreateCommand()
        $cmd.CommandTimeout = 20
        $cmd.CommandText = $sql
        if ($Limit -gt 0 -and $mode -eq "SqlClient") {
            $p = $cmd.Parameters.Add("@limit", [System.Data.SqlDbType]::Int)
            $p.Value = $Limit
        }
        if ($DocumentKey -gt 0) {
            if ($mode -eq "ODBC") {
                $p = $cmd.Parameters.Add("document_key", [System.Data.Odbc.OdbcType]::BigInt)
            } else {
                $p = $cmd.Parameters.Add("@document_key", [System.Data.SqlDbType]::BigInt)
            }
            $p.Value = $DocumentKey
        }
        if ($AfterDocumentKey -ge 0) {
            if ($mode -eq "ODBC") {
                $p = $cmd.Parameters.Add("after_document_key_1", [System.Data.Odbc.OdbcType]::BigInt)
                $p.Value = $AfterDocumentKey
                $p = $cmd.Parameters.Add("after_document_key_2", [System.Data.Odbc.OdbcType]::BigInt)
                $p.Value = $AfterDocumentKey
            } else {
                $p = $cmd.Parameters.Add("@after_document_key", [System.Data.SqlDbType]::BigInt)
                $p.Value = $AfterDocumentKey
            }
        }
        if ($AfterRowKey -ge 0) {
            if ($mode -eq "ODBC") {
                $p = $cmd.Parameters.Add("after_row_key", [System.Data.Odbc.OdbcType]::BigInt)
            } else {
                $p = $cmd.Parameters.Add("@after_row_key", [System.Data.SqlDbType]::BigInt)
            }
            $p.Value = $AfterRowKey
        }
        $reader = $cmd.ExecuteReader()
        $rows = New-Object System.Collections.ArrayList
        while ($reader.Read()) {
            $obj = [ordered]@{}
            for ($i = 0; $i -lt $reader.FieldCount; $i++) {
                $name = $reader.GetName($i)
                $obj[$name] = Convert-SqlValue $reader.GetValue($i)
            }
            [void]$rows.Add($obj)
        }
        $reader.Close()
        return ,$rows
    }
    finally {
        if ($conn.State -ne [System.Data.ConnectionState]::Closed) { $conn.Close() }
        $conn.Dispose()
    }
}

function Test-SqlServerConnection {
    $opened = Open-ReadOnlyConnection -ConnStr $SqlConnStr
    $conn = $opened.Connection
    try {
        $cmd = $conn.CreateCommand()
        $cmd.CommandTimeout = 10
        $cmd.CommandText = "SELECT @@SERVERNAME AS server_name, DB_NAME() AS db_name"
        $reader = $cmd.ExecuteReader()
        $info = @{ driver_mode = $opened.Mode }
        if ($reader.Read()) {
            $info["server_name"] = Convert-SqlValue $reader.GetValue(0)
            $info["database"] = Convert-SqlValue $reader.GetValue(1)
        }
        $reader.Close()
        return $info
    }
    finally {
        if ($conn.State -ne [System.Data.ConnectionState]::Closed) { $conn.Close() }
        $conn.Dispose()
    }
}

function Write-JsonResponse {
    param($Response, [int]$StatusCode, $Payload)
    $json = $Payload | ConvertTo-Json -Depth 6 -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $Response.StatusCode = $StatusCode
    $Response.ContentType = "application/json; charset=utf-8"
    $Response.ContentLength64 = $bytes.Length
    $Response.OutputStream.Write($bytes, 0, $bytes.Length)
    $Response.OutputStream.Close()
}

$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://127.0.0.1:$Port/")
$listener.Start()

Write-Host "Popstar Local ERP Bridge running on http://127.0.0.1:$Port/ (SELECT-only)" -ForegroundColor Green
Write-Host "SQL Configured: $([bool]$SqlConnStr) | Source: $SourceSystem" -ForegroundColor Cyan

try {
    while ($listener.IsListening) {
        $ctx = $listener.GetContext()
        $req = $ctx.Request
        $res = $ctx.Response
        try {
            $path = $req.Url.AbsolutePath.TrimEnd('/')
            if ($path -eq "") { $path = "/" }

            # Reload settings dynamically so /health and authenticated calls reflect config.env edits immediately.
            # DPAPI-protected passwords can only be unprotected under the same Windows user that saved them.
            $cfg = Load-ConfigEnv -Path $ConfigFile
            $SqlConnStr = Get-ConfiguredSqlConnectionString -Config $cfg
            if ($env:ERP_SOURCE_SYSTEM) { $SourceSystem = $env:ERP_SOURCE_SYSTEM } elseif ($cfg["ERP_SOURCE_SYSTEM"]) { $SourceSystem = $cfg["ERP_SOURCE_SYSTEM"] }

            if ($path -eq "/health") {
                $driverMode = if (-not $SqlConnStr) { "unconfigured" } elseif (Test-IsOdbcConnectionString -ConnStr $SqlConnStr) { "ODBC ({SQL Server})" } else { ".NET SqlClient" }
                Write-JsonResponse $res 200 @{
                    ok = $true
                    driver = ".NET SqlClient + ODBC (Windows Host)"
                    driver_mode = $driverMode
                    sql_configured = [bool]$SqlConnStr
                    source = $SourceSystem
                    message = if ($SqlConnStr) { "Bridge online & SQL config present ($driverMode)" } else { "Bridge online; waiting for SQL_CONNECTION_STRING in sync-agent/config.env" }
                }
                continue
            }

            $token = $req.Headers["X-Bridge-Token"]
            if (-not (Test-ConstantTimeString -Expected $BridgeToken -Provided ([string]$token))) {
                Write-JsonResponse $res 401 @{ ok = $false; error = "Unauthorized bridge token" }
                continue
            }

            $rawLimit = $req.QueryString["limit"]
            $limitParam = if ($rawLimit -match '^\d+$') { [Math]::Max(1, [Math]::Min(5000, [int]$rawLimit)) } else { 500 }

            if ($path -eq "/test-connection" -and $req.HttpMethod -eq "POST") {
                $info = Test-SqlServerConnection
                Write-JsonResponse $res 200 @{
                    ok = $true
                    message = "Connected to SQL Server ($($info.database))"
                    server_info = $info
                }
            }
            elseif ($path -eq "/preview/customers") {
                $rows = Invoke-ReadOnlySql -SqlFile "bplus_customers_10.sql"
                Write-JsonResponse $res 200 @{ ok = $true; source = $SourceSystem; records = $rows }
            }
            elseif ($path -eq "/preview/customer-contacts") {
                $rows = Invoke-ReadOnlySql -SqlFile "bplus_customer_contacts.sql" -Limit 20
                Write-JsonResponse $res 200 @{ ok = $true; source = $SourceSystem; records = $rows }
            }
            elseif ($path -eq "/preview/products") {
                $rows = Invoke-ReadOnlySql -SqlFile "bplus_products.sql" -Limit 10
                Write-JsonResponse $res 200 @{ ok = $true; source = $SourceSystem; records = $rows }
            }
            elseif ($path -eq "/preview/invoices") {
                $rows = Invoke-ReadOnlySql -SqlFile "bplus_invoices.sql" -Limit 10 -AfterDocumentKey 0
                Write-JsonResponse $res 200 @{ ok = $true; source = $SourceSystem; records = $rows }
            }
            elseif ($path -eq "/export/salespersons") {
                $rawAfterKey = $req.QueryString["after_key"]
                if ($rawAfterKey -and $rawAfterKey -notmatch '^\d+$') { throw "Invalid after_key cursor." }
                $afterKey = if ($rawAfterKey) { [long]$rawAfterKey } else { 0 }
                $rows = Invoke-ReadOnlySql -SqlFile "bplus_salespersons.sql" -Limit $limitParam -AfterRowKey $afterKey
                $nextCursor = if ($rows.Count -gt 0) { [long]$rows[$rows.Count - 1].erp_row_key } else { $afterKey }
                Write-JsonResponse $res 200 @{ ok = $true; source = $SourceSystem; records = $rows; next_cursor = $nextCursor; has_more = ($rows.Count -eq $limitParam) }
            }
            elseif ($path -eq "/export/members") {
                $rawAfterKey = $req.QueryString["after_key"]
                if ($rawAfterKey -and $rawAfterKey -notmatch '^\d+$') { throw "Invalid after_key cursor." }
                $afterKey = if ($rawAfterKey) { [long]$rawAfterKey } else { 0 }
                $rows = Invoke-ReadOnlySql -SqlFile "bplus_members.sql" -Limit $limitParam -AfterRowKey $afterKey
                $nextCursor = if ($rows.Count -gt 0) { [long]$rows[$rows.Count - 1].erp_row_key } else { $afterKey }
                Write-JsonResponse $res 200 @{ ok = $true; source = $SourceSystem; records = $rows; next_cursor = $nextCursor; has_more = ($rows.Count -eq $limitParam) }
            }
            elseif ($path -eq "/export/member-points") {
                $rows = Invoke-ReadOnlySql -SqlFile "bplus_member_points.sql" -Limit $limitParam
                Write-JsonResponse $res 200 @{ ok = $true; source = $SourceSystem; records = $rows }
            }
            elseif ($path -eq "/export/customers") {
                $limit = [int]($req.QueryString["limit"] | ForEach-Object { if ($_ -match '^\d+$') { $_ } else { 500 } })
                if ($limit -lt 1 -or $limit -gt 5000) { $limit = 500 }
                $rawAfterKey = $req.QueryString["after_key"]
                if ($rawAfterKey -and $rawAfterKey -notmatch '^\d+$') { throw "Invalid after_key cursor." }
                $afterKey = if ($rawAfterKey) { [long]$rawAfterKey } else { 0 }
                $rows = Invoke-ReadOnlySql -SqlFile "bplus_customers.sql" -Limit $limit -AfterRowKey $afterKey
                $nextCursor = if ($rows.Count -gt 0) { [long]$rows[$rows.Count - 1].erp_row_key } else { $afterKey }
                Write-JsonResponse $res 200 @{ ok = $true; source = $SourceSystem; records = $rows; next_cursor = $nextCursor; has_more = ($rows.Count -eq $limit) }
            }
            elseif ($path -eq "/export/products") {
                $limit = [int]($req.QueryString["limit"] | ForEach-Object { if ($_ -match '^\d+$') { $_ } else { 500 } })
                if ($limit -lt 1 -or $limit -gt 5000) { $limit = 500 }
                $rawAfterKey = $req.QueryString["after_key"]
                if ($rawAfterKey -and $rawAfterKey -notmatch '^\d+$') { throw "Invalid after_key cursor." }
                $afterKey = if ($rawAfterKey) { [long]$rawAfterKey } else { 0 }
                $rows = Invoke-ReadOnlySql -SqlFile "bplus_products.sql" -Limit $limit -AfterRowKey $afterKey
                $nextCursor = if ($rows.Count -gt 0) { [long]$rows[$rows.Count - 1].erp_row_key } else { $afterKey }
                Write-JsonResponse $res 200 @{ ok = $true; source = $SourceSystem; records = $rows; next_cursor = $nextCursor; has_more = ($rows.Count -eq $limit) }
            }
            elseif ($path -eq "/export/invoices") {
                $limit = [int]($req.QueryString["limit"] | ForEach-Object { if ($_ -match '^\d+$') { $_ } else { 500 } })
                if ($limit -lt 1 -or $limit -gt 2000) { $limit = 500 }
                $rawAfterKey = $req.QueryString["after_key"]
                if ($rawAfterKey -and $rawAfterKey -notmatch '^\d+$') { throw "Invalid after_key cursor." }
                $afterKey = if ($rawAfterKey) { [long]$rawAfterKey } else { 0 }
                $invoices = Invoke-ReadOnlySql -SqlFile "bplus_invoices.sql" -Limit $limit -AfterDocumentKey $afterKey
                foreach ($inv in $invoices) {
                    if (-not $inv.di_key) { throw "Invoice result is missing its ERP document key; refusing incomplete line sync." }
                    $items = Invoke-ReadOnlySql -SqlFile "bplus_sales_items.sql" -Limit 201 -DocumentKey ([long]$inv.di_key)
                    if ($items.Count -gt 200) { throw "Invoice $($inv.invoice_no) has more than 200 lines; refusing incomplete sync." }
                    $inv["items"] = @(
                      foreach ($it in $items) {
                        @{
                        line_no = [int]$it.line_no
                        erp_product_code = $it.erp_product_code
                        product_name = $it.product_name
                        iccat_code = $it.iccat_code
                        iccat_name = $it.iccat_name
                        is_excluded_kpi = [bool]$it.is_excluded_kpi
                        qty = $it.qty
                        unit_name = $it.unit_name
                        unit_price = $it.unit_price
                        before_vat_amount = $it.before_vat_amount
                        vat_amount = $it.vat_amount
                        gross_amount = $it.gross_amount
                        net_amount = $it.net_amount
                        refer_trd_key = $it.refer_trd_key
                        refer_doc_ref = $it.refer_doc_ref
                        }
                      }
                    )
                    $inv["items_complete"] = $true
                }
                $nextCursor = if ($invoices.Count -gt 0) { [long]$invoices[$invoices.Count - 1].di_key } else { $afterKey }
                Write-JsonResponse $res 200 @{ ok = $true; source = $SourceSystem; records = $invoices; next_cursor = $nextCursor; has_more = ($invoices.Count -eq $limit) }
            }
            else {
                Write-JsonResponse $res 404 @{ ok = $false; error = "Not found" }
            }
        }
        catch {
            $meta = Get-SqlErrorMetadata -Exception $_.Exception
            $errMsg = $meta.message
            Write-Host "[ERROR] code=$($meta.error_code) sqlstate=$($meta.sqlstate) msg=$errMsg" -ForegroundColor Red
            Write-JsonResponse $res 500 @{
                ok = $false
                error = $errMsg
                error_code = $meta.error_code
                sqlstate = $meta.sqlstate
            }
        }
    }
}
finally {
    $listener.Stop()
    $listener.Close()
}
