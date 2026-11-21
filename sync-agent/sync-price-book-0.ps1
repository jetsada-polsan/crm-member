$ErrorActionPreference = 'Stop'
$cfg = @{}
foreach ($line in Get-Content -LiteralPath (Join-Path $PSScriptRoot 'config.env') -Encoding UTF8) {
    $line = $line.Trim(); $i = $line.IndexOf('=')
    if ($i -gt 0 -and -not $line.StartsWith('#')) { $cfg[$line.Substring(0, $i).Trim()] = $line.Substring($i + 1).Trim().Trim('"').Trim("'") }
}
$secure = ConvertTo-SecureString -String $cfg['SQL_PASSWORD_DPAPI']
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try { $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
$builder = [System.Data.SqlClient.SqlConnectionStringBuilder]::new()
$builder['Data Source'] = 'tcp:' + $cfg['SQL_SERVER'] + ',' + $cfg['SQL_PORT']
$builder['Initial Catalog'] = $cfg['SQL_DATABASE']; $builder['User ID'] = $cfg['SQL_USER']; $builder['Password'] = $password
$builder['Encrypt'] = $true; $builder['TrustServerCertificate'] = $true; $builder['ApplicationIntent'] = 'ReadOnly'; $builder['Connect Timeout'] = 15
$password = $null
$conn = [System.Data.SqlClient.SqlConnection]::new($builder.ConnectionString)
$tempPath = Join-Path ([IO.Path]::GetTempPath()) ('popstar-prices-' + [guid]::NewGuid().ToString('N') + '.json')
try {
    $conn.Open()
    $cmd = $conn.CreateCommand(); $cmd.CommandTimeout = 90
    $cmd.CommandText = @'
SELECT ap.ARPLU_KEY, sm.SKU_CODE, gm.GOODS_CODE, sm.SKU_NAME, ic.ICCAT_NAME, uq.UTQ_NAME, uq.UTQ_QTY, ap.ARPLU_U_PRC
FROM SKUMASTER sm JOIN GOODSMASTER gm ON sm.SKU_KEY=gm.GOODS_SKU
JOIN ARPLU ap ON ap.ARPLU_GOODS=gm.GOODS_KEY JOIN UOFQTY uq ON gm.GOODS_UTQ=uq.UTQ_KEY
JOIN ICCAT ic ON sm.SKU_ICCAT=ic.ICCAT_KEY JOIN ARPRICETAB pt ON ap.ARPLU_ARPRB=pt.ARPRB_KEY
WHERE pt.ARPRB_CODE='0'
  AND UPPER(LTRIM(RTRIM(COALESCE(sm.SKU_ENABLE, 'N')))) = 'Y'
  AND UPPER(LTRIM(RTRIM(COALESCE(sm.SKU_P_ENABLE, 'N')))) = 'Y'
  AND UPPER(LTRIM(RTRIM(COALESCE(gm.GOODS_ENABLE, 'N')))) = 'Y'
  AND UPPER(LTRIM(RTRIM(COALESCE(gm.GOODS_P_ENABLE, 'N')))) = 'Y'
  AND ic.ICCAT_CODE NOT IN ('CC','CO','AS','PK')
  AND ap.ARPLU_U_PRC IS NOT NULL AND ap.ARPLU_U_PRC >= 0
ORDER BY sm.SKU_CODE, uq.UTQ_QTY, gm.GOODS_CODE, ap.ARPLU_KEY
'@
    $reader = $cmd.ExecuteReader(); $rows = [System.Collections.Generic.List[object]]::new()
    try {
        while ($reader.Read()) {
            $rows.Add(@{
                price_key = [string]$reader[0]
                sku = [string]$reader[1]
                goods = [string]$reader[2]
                name = ([string]$reader[3]).Trim()
                category = if ($reader.IsDBNull(4)) { $null } else { ([string]$reader[4]).Trim() }
                unit = if ($reader.IsDBNull(5)) { $null } else { ([string]$reader[5]).Trim() }
                unit_qty = if ($reader.IsDBNull(6)) { $null } else { [Convert]::ToString($reader[6], [Globalization.CultureInfo]::InvariantCulture) }
                price = [Convert]::ToString($reader[7], [Globalization.CultureInfo]::InvariantCulture)
            })
        }
    } finally { $reader.Close() }
    if ($rows.Count -eq 0) { throw 'No prices returned; previous snapshot retained.' }
    $json = ConvertTo-Json -InputObject @{ rows = @($rows.ToArray()) } -Depth 4 -Compress
    [IO.File]::WriteAllText($tempPath, $json, [Text.UTF8Encoding]::new($false))
    & 'D:\xampp\php\php.exe' (Join-Path (Split-Path $PSScriptRoot -Parent) 'backend\scripts\import_product_prices.php') $tempPath
    if ($LASTEXITCODE -ne 0) { throw 'Import failed; previous snapshot retained.' }
} finally {
    $conn.Dispose()
    if (Test-Path -LiteralPath $tempPath) { Remove-Item -LiteralPath $tempPath -Force }
}
