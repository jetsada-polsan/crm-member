#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Parse and inspect Bplus report export files (sql.csv & รายงาน.csv).
 *
 * Detects BOM/character encoding (UTF-8, UTF-8-BOM, UTF-16LE/BE, Windows-874/TIS-620),
 * delimiter (comma vs tab), quoting style (RFC-4180 CSV vs unquoted multiline TSV),
 * validates field counts, compares normalized SQL between files, categorizes reports,
 * extracts referenced SQL tables, joins, runtime RPF_SELECT_* filter flags,
 * document-type filters (DT_PROPERTIES), and enforces read-only SELECT auditing.
 *
 * Usage:
 *   php tools/parse_report_csv.php [sql_csv_path] [tab_csv_path] [--json=out.json]
 */

$defaultRoot = dirname(__DIR__, 2);
$args = array_slice($argv, 1);
$jsonOut = null;
$paths = [];

foreach ($args as $arg) {
    if (str_starts_with($arg, '--json=')) {
        $jsonOut = substr($arg, 7);
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php parse_report_csv.php [sql.csv] [รายงาน.csv] [--json=output.json]\n");
        exit(0);
    } else {
        $paths[] = $arg;
    }
}

$sqlCsvPath = $paths[0] ?? $defaultRoot . DIRECTORY_SEPARATOR . 'sql.csv';
$tsvCsvPath = $paths[1] ?? $defaultRoot . DIRECTORY_SEPARATOR . 'รายงาน.csv';

/**
 * Detect encoding & normalize input to UTF-8 without BOM.
 */
function inspectAndNormalizeBytes(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException("File not found: {$path}");
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Failed to read file: {$path}");
    }

    $size = strlen($raw);
    $encoding = 'UTF-8';
    $bom = 'none';

    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $bom = 'UTF-8-BOM (EF BB BF)';
        $encoding = 'UTF-8';
        $text = substr($raw, 3);
    } elseif (str_starts_with($raw, "\xFF\xFE")) {
        $bom = 'UTF-16LE-BOM (FF FE)';
        $encoding = 'UTF-16LE';
        $text = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
    } elseif (str_starts_with($raw, "\xFE\xFF")) {
        $bom = 'UTF-16BE-BOM (FE FF)';
        $encoding = 'UTF-16BE';
        $text = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
    } elseif (mb_check_encoding($raw, 'UTF-8')) {
        $text = $raw;
    } else {
        $encoding = 'Windows-874 / TIS-620 (converted to UTF-8)';
        $converted = @iconv('CP874', 'UTF-8//IGNORE', $raw);
        if ($converted === false) {
            $converted = @iconv('TIS-620', 'UTF-8//IGNORE', $raw);
        }
        $text = $converted !== false ? $converted : $raw;
    }

    $crlf = substr_count($text, "\r\n");
    $lf = substr_count($text, "\n");
    $textNorm = str_replace(["\r\n", "\r"], "\n", $text);
    $firstNewline = strpos($textNorm, "\n");
    $headerLine = $firstNewline === false ? $textNorm : substr($textNorm, 0, $firstNewline);
    $tabCount = substr_count($headerLine, "\t");
    $commaCount = substr_count($headerLine, ',');
    $delimiter = $tabCount >= $commaCount && $tabCount > 0 ? "\t" : ',';
    $hasQuotesInData = (bool) preg_match('/(?:^|,|\t)"/', substr($textNorm, ($firstNewline ?: 0) + 1, 2000));

    return [
        'path' => $path,
        'bytes' => $size,
        'bom' => $bom,
        'encoding' => $encoding,
        'delimiter' => $delimiter === "\t" ? 'TAB (\t)' : 'COMMA (,)',
        'delimiter_char' => $delimiter,
        'quoting' => $delimiter === "\t" && ! $hasQuotesInData ? 'unquoted (multiline SQL spans lines)' : 'RFC-4180 double-quoted',
        'line_endings' => $crlf > 0 ? "CRLF ({$crlf})" : "LF ({$lf})",
        'physical_lines' => substr_count(rtrim($textNorm, "\n"), "\n") + 1,
        'text' => $textNorm,
    ];
}

/**
 * Parse RFC-4180 quoted CSV (such as sql.csv).
 */
function parseQuotedCsv(string $text, string $delimiter): array
{
    $fp = fopen('php://temp', 'r+b');
    fwrite($fp, $text);
    rewind($fp);

    $headers = fgetcsv($fp, 0, $delimiter, '"', '');
    if (! is_array($headers)) {
        fclose($fp);
        throw new RuntimeException('Failed to read CSV headers.');
    }
    $headers = array_map('trim', $headers);
    $expectedCols = count($headers);

    $rows = [];
    $badRowCount = 0;
    while (($row = fgetcsv($fp, 0, $delimiter, '"', '')) !== false) {
        if ($row === [null] || $row === []) {
            continue;
        }
        if (count($row) !== $expectedCols) {
            $badRowCount++;
            continue;
        }
        $assoc = [];
        foreach ($headers as $i => $h) {
            $assoc[$h] = $row[$i] ?? '';
        }
        $rows[] = $assoc;
    }
    fclose($fp);

    return [
        'headers' => $headers,
        'rows' => $rows,
        'bad_rows' => $badRowCount,
    ];
}

/**
 * Parse unquoted multiline TSV export (such as รายงาน.csv).
 * Each record begins with 3 numeric columns: RPF_KEY \t RPF_RPG \t RPF_ABS_INDEX \t ...
 */
function parseUnquotedMultilineTsv(string $text): array
{
    $lines = explode("\n", rtrim($text, "\n"));
    $headerLine = array_shift($lines);
    $headers = array_map('trim', explode("\t", (string) $headerLine));
    $expectedCols = count($headers);

    $sqlIdx = array_search('RPF_SQL', $headers, true);
    $orderIdx = array_search('RPF_ORDER', $headers, true);
    if ($sqlIdx === false || $orderIdx === false || $orderIdx !== $sqlIdx + 1) {
        throw new RuntimeException('Unexpected header layout for unquoted multiline TSV.');
    }

    $prefixCols = $sqlIdx;                  // 10 columns before RPF_SQL (0..9)
    $suffixCols = $expectedCols - ($orderIdx + 1); // 20 columns after RPF_ORDER (12..31)

    $blocks = [];
    $current = [];
    foreach ($lines as $line) {
        if (preg_match('/^\d+\t\d+\t\d+\t/', $line)) {
            if ($current !== []) {
                $blocks[] = implode("\n", $current);
            }
            $current = [$line];
        } elseif ($current !== []) {
            $current[] = $line;
        }
    }
    if ($current !== []) {
        $blocks[] = implode("\n", $current);
    }

    $rows = [];
    $badRowCount = 0;

    foreach ($blocks as $block) {
        $parts = explode("\t", $block);
        if (count($parts) < $expectedCols) {
            $badRowCount++;
            continue;
        }
        if (count($parts) === $expectedCols) {
            $fields = $parts;
        } else {
            // If raw SQL/ORDER contained literal tab characters, slice deterministic prefix and suffix.
            $head = array_slice($parts, 0, $prefixCols);
            $tail = array_slice($parts, -$suffixCols);
            $midParts = array_slice($parts, $prefixCols, count($parts) - $prefixCols - $suffixCols);
            // Locate the tab separating RPF_SQL and RPF_ORDER.
            $splitPos = count($midParts) - 1;
            for ($i = count($midParts) - 1; $i >= 1; $i--) {
                if (preg_match('/^\s*ORDER\s+BY\b/i', $midParts[$i]) || trim($midParts[$i]) === '') {
                    $splitPos = $i;
                    break;
                }
            }
            $sqlVal = implode("\t", array_slice($midParts, 0, $splitPos));
            $orderVal = implode("\t", array_slice($midParts, $splitPos));
            $fields = array_merge($head, [$sqlVal, $orderVal], $tail);
        }

        $assoc = [];
        foreach ($headers as $i => $h) {
            $assoc[$h] = $fields[$i] ?? '';
        }
        $rows[] = $assoc;
    }

    return [
        'headers' => $headers,
        'rows' => $rows,
        'bad_rows' => $badRowCount,
    ];
}

function normalizeSqlWhitespace(string $sql): string
{
    $sql = str_replace(["\r\n", "\r"], "\n", trim($sql));
    return (string) preg_replace('/\s+/', ' ', $sql);
}

/**
 * Strip comments and single-quoted literals before checking keywords or extracting tables.
 */
function stripSqlLiteralsAndComments(string $sql): string
{
    $sql = (string) preg_replace('/--[^\n]*/', ' ', $sql);
    $sql = (string) preg_replace('/\/\*[\s\S]*?\*\//', ' ', $sql);
    return (string) preg_replace("/'(?:''|[^'])*'/", "''", $sql);
}

function checkReadOnlySql(string $sql): array
{
    $trimmed = trim($sql);
    if ($trimmed === '') {
        return ['read_only' => true, 'reason' => 'no_sql_rpt_only'];
    }
    $clean = stripSqlLiteralsAndComments($trimmed);
    $startsSelect = (bool) preg_match('/^\s*(?:\(?\s*SELECT|WITH)\b/i', $clean);
    $writeHits = [];
    if (preg_match_all('/\b(INSERT|UPDATE|DELETE|EXEC|EXECUTE|DROP|ALTER|CREATE|TRUNCATE|MERGE|GRANT|REVOKE)\b/i', $clean, $m)) {
        $writeHits = array_values(array_unique(array_map('strtoupper', $m[1])));
    }
    if (preg_match('/\bSELECT\b[\s\S]*?\bINTO\s+(?!@)[#\w.\[\]]+/i', $clean)) {
        $writeHits[] = 'SELECT_INTO';
    }
    $readOnly = $startsSelect && $writeHits === [];

    return [
        'read_only' => $readOnly,
        'reason' => $readOnly ? 'pure_select' : implode(',', array_unique(array_merge($startsSelect ? [] : ['NOT_SELECT_START'], $writeHits))),
    ];
}

function extractSqlTables(string $sql): array
{
    if (trim($sql) === '') {
        return [];
    }
    $clean = stripSqlLiteralsAndComments($sql);
    $tables = [];
    $skip = ['SELECT', 'WHERE', 'ORDER', 'GROUP', 'HAVING', 'UNION', 'ALL', 'ON', 'AND', 'OR', 'AS', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'JOIN', 'OUTER', 'APPLY', 'SET', 'VALUES', 'INTO', 'TOP', 'DISTINCT', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END'];

    // Extract FROM clauses (supporting ANSI-89 comma lists without crossing into subqueries)
    if (preg_match_all('/\bFROM\s+(?!\()((?:(?!\b(?:SELECT|WHERE|INNER|LEFT|RIGHT|FULL|CROSS|OUTER|JOIN|GROUP|HAVING|ORDER|UNION|ON|APPLY)\b)[^();])+)/i', $clean, $matches)) {
        foreach ($matches[1] as $fromChunk) {
            $items = explode(',', $fromChunk);
            foreach ($items as $item) {
                $item = trim($item);
                if ($item === '' || str_starts_with($item, '(')) {
                    continue;
                }
                if (preg_match('/^([A-Za-z_][A-Za-z0-9_.\[\]]*)/', $item, $tm)) {
                    $name = strtoupper(str_replace(['[', ']'], '', $tm[1]));
                    if (str_contains($name, '.')) {
                        $parts = explode('.', $name);
                        $name = end($parts);
                    }
                    if (! in_array($name, $skip, true)) {
                        $tables[$name] = true;
                    }
                }
            }
        }
    }

    // Extract ANSI-92 JOIN targets
    if (preg_match_all('/\bJOIN\s+([A-Za-z_][A-Za-z0-9_.\[\]]*)/i', $clean, $jMatches)) {
        foreach ($jMatches[1] as $rawName) {
            $name = strtoupper(str_replace(['[', ']'], '', $rawName));
            if (str_contains($name, '.')) {
                $parts = explode('.', $name);
                $name = end($parts);
            }
            if (! in_array($name, $skip, true)) {
                $tables[$name] = true;
            }
        }
    }

    return array_keys($tables);
}

function extractJoinAndFilterDetails(array $row): array
{
    $sql = (string) ($row['RPF_SQL'] ?? '');
    $selectCols = [];
    foreach ($row as $k => $v) {
        if (str_starts_with($k, 'RPF_SELECT_') && str_contains((string) $v, 'Y')) {
            $selectCols[] = substr($k, 11);
        }
    }

    $docProps = [];
    if ($sql !== '' && preg_match_all('/\bDT_PROPERTIES\b[^;\n)]*/i', $sql, $pm)) {
        foreach ($pm[0] as $expr) {
            if (preg_match_all('/\b(\d{3})\b/', $expr, $nums)) {
                foreach ($nums[1] as $n) {
                    $docProps[$n] = true;
                }
            }
        }
    }

    $dateCols = [];
    if ($sql !== '' && preg_match_all('/\b([A-Z0-9_]*DATE[A-Z0-9_]*)\b/i', $sql, $dm)) {
        foreach (array_unique(array_map('strtoupper', $dm[1])) as $d) {
            $dateCols[$d] = true;
        }
    }

    $joins = [];
    if ($sql !== '') {
        $hasAnsi92 = (bool) preg_match('/\bJOIN\b/i', $sql);
        $hasComma = (bool) preg_match('/\bFROM\s+[A-Za-z0-9_]+\s*,/i', $sql);
        if ($hasAnsi92) {
            $joins[] = 'ANSI-92 JOIN';
        }
        if ($hasComma) {
            $joins[] = 'ANSI-89 comma join';
        }
        if (preg_match('/\bUNION(\s+ALL)?\b/i', $sql)) {
            $joins[] = 'UNION';
        }
    }

    return [
        'select_flags' => $selectCols,
        'dt_properties' => array_keys($docProps),
        'date_cols' => array_keys($dateCols),
        'join_style' => $joins,
    ];
}

function categorizeReport(array $row, array $tables): array
{
    $name = mb_strtolower((string) ($row['RPF_NAME'] ?? ''), 'UTF-8');
    $code = strtoupper((string) ($row['RPF_CODE'] ?? ''));
    $sqlUpper = strtoupper((string) ($row['RPF_SQL'] ?? ''));
    $tableSet = array_flip($tables);

    $cats = [];

    if (isset($tableSet['MEMBER']) || isset($tableSet['MBTYPE']) || isset($tableSet['MBPOINT']) || str_contains($name, 'สมาชิก') || str_starts_with($code, 'MP')) {
        $cats[] = (isset($tableSet['MBPOINT']) || str_contains($name, 'คะแนน') || str_contains($name, 'แต้ม') || str_starts_with($code, 'MP'))
            ? 'Member / Loyalty'
            : 'Member';
    }
    if (isset($tableSet['SALESMAN']) || isset($tableSet['SLDETAIL']) || isset($tableSet['SLLINE']) || isset($tableSet['SLTEAM']) || str_contains($name, 'พนักงานขาย')) {
        $cats[] = (str_contains($name, 'ขาย') && (isset($tableSet['DOCINFO']) || isset($tableSet['ARDETAIL'])))
            ? 'Sales by salesperson'
            : 'Salesperson';
    }
    if (isset($tableSet['ARFILE']) || isset($tableSet['ARCONTACT']) || isset($tableSet['ARLINE']) || isset($tableSet['ARCAT']) || str_contains($name, 'ลูกหนี้') || str_contains($name, 'ลูกค้า')) {
        if (str_contains($name, 'ยอดขาย') || str_contains($name, 'ขายสินค้า') || str_contains($name, 'ซื้อ') || isset($tableSet['ARDETAIL']) || isset($tableSet['SKUMOVE'])) {
            $cats[] = 'Sales by customer / Purchase history';
        } else {
            $cats[] = 'Customer';
        }
    }
    if (isset($tableSet['SKUMASTER']) || isset($tableSet['GOODSMASTER']) || isset($tableSet['ARPLU']) || isset($tableSet['ICCAT']) || isset($tableSet['PRICETAG']) || str_contains($name, 'สินค้า') || str_contains($name, 'ป้ายราคา')) {
        if (! str_contains($name, 'ขาย') && ! str_contains($name, 'รับคืน')) {
            $cats[] = 'Product';
        }
    }
    if (isset($tableSet['TRANSTKD']) || isset($tableSet['TRANSTKH'])) {
        if (str_contains($sqlUpper, 'TRANSTKD') && (str_contains($sqlUpper, 'ARDETAIL') || str_contains($sqlUpper, 'DOCINFO'))) {
            $cats[] = 'Invoice detail';
        }
    }
    if (str_contains($name, 'รับคืน') || str_contains($name, 'ลดหนี้') || str_contains($sqlUpper, '308')) {
        $cats[] = 'Returns / Credit note';
    }
    if (isset($tableSet['ARDETAIL']) || isset($tableSet['AROE']) || str_contains($name, 'ยอดขาย') || str_contains($name, 'ใบกำกับ') || str_contains($name, 'ใบขาย') || str_contains($name, 'การขาย')) {
        $cats[] = 'Sales';
    }

    if ($cats === []) {
        $cats[] = 'Other';
    }

    return array_values(array_unique($cats));
}

function classifyReuse(array $row, array $tables, array $roCheck, array $categories): array
{
    $sql = trim((string) ($row['RPF_SQL'] ?? ''));
    if ($sql === '') {
        return ['reuse' => 'RPT-only (.rpt binary logic)', 'status' => 'RPT_ONLY'];
    }
    if (! $roCheck['read_only']) {
        return ['reuse' => 'Excluded (non-read-only)', 'status' => 'BLOCKED_WRITE'];
    }

    $code = strtoupper((string) ($row['RPF_CODE'] ?? ''));
    $exactCurated = [
        'SQL05060001' => ['reuse' => 'ERP Mirror sync / CRM Customers', 'status' => 'ADAPTED_IN_V4'],
        'SQL01206004' => ['reuse' => 'ERP Mirror sync / Customer Contacts', 'status' => 'ADAPTED_IN_V4'],
        'SQL11001103' => ['reuse' => 'ERP Mirror sync / Product Catalog', 'status' => 'ADAPTED_IN_V4'],
        'SQL11001205' => ['reuse' => 'ERP Mirror sync / Invoices & Reservations & Dashboard', 'status' => 'ADAPTED_IN_V4'],
        'SQL11001043' => ['reuse' => 'ERP Mirror sync / Invoice Line Items', 'status' => 'ADAPTED_IN_V4'],
        'SQL08050001' => ['reuse' => 'ERP Mirror sync / Salespersons', 'status' => 'ADAPTED_IN_V4'],
        'SQL05090002' => ['reuse' => 'ERP Mirror sync / Members', 'status' => 'ADAPTED_IN_V4'],
        'MP0010011'   => ['reuse' => 'Loyalty / Bplus Member Points History', 'status' => 'ADAPTED_IN_V4'],
        'MP003002'    => ['reuse' => 'Loyalty / Member Redemption Audit', 'status' => 'ADAPTED_IN_V4'],
    ];
    if (isset($exactCurated[$code])) {
        return $exactCurated[$code];
    }

    $catStr = implode(', ', $categories);
    if (str_contains($catStr, 'Sales') || str_contains($catStr, 'Invoice detail') || str_contains($catStr, 'Returns')) {
        return ['reuse' => 'Dashboard / Member purchase history / Reconciliation ref', 'status' => 'REUSE_REFERENCE'];
    }
    if (str_contains($catStr, 'Customer')) {
        return ['reuse' => 'CRM / Customer Master ref', 'status' => 'REUSE_REFERENCE'];
    }
    if (str_contains($catStr, 'Member')) {
        return ['reuse' => 'Member / Loyalty ref', 'status' => 'REUSE_REFERENCE'];
    }
    if (str_contains($catStr, 'Product')) {
        return ['reuse' => 'Product / Pricing ref', 'status' => 'REUSE_REFERENCE'];
    }
    if (str_contains($catStr, 'Salesperson')) {
        return ['reuse' => 'CRM / Salesperson ref', 'status' => 'REUSE_REFERENCE'];
    }

    return ['reuse' => 'Other ERP backoffice report', 'status' => 'OTHER_REFERENCE'];
}

$sqlInfo = inspectAndNormalizeBytes($sqlCsvPath);
$tsvInfo = inspectAndNormalizeBytes($tsvCsvPath);

$sqlParsed = parseQuotedCsv($sqlInfo['text'], $sqlInfo['delimiter_char']);
$tsvParsed = parseUnquotedMultilineTsv($tsvInfo['text']);

// Compare row-by-row between sql.csv and รายงาน.csv
$tsvByKey = [];
foreach ($tsvParsed['rows'] as $r) {
    $tsvByKey[$r['RPF_KEY'] . '|' . $r['RPF_CODE']] = $r;
}
$exactMatches = 0;
$wsDiffs = 0;
$contentDiffs = 0;
foreach ($sqlParsed['rows'] as $r) {
    $k = $r['RPF_KEY'] . '|' . $r['RPF_CODE'];
    if (! isset($tsvByKey[$k])) {
        $contentDiffs++;
        continue;
    }
    $tr = $tsvByKey[$k];
    if (($r['RPF_SQL'] ?? '') === ($tr['RPF_SQL'] ?? '')) {
        $exactMatches++;
    } elseif (normalizeSqlWhitespace((string) ($r['RPF_SQL'] ?? '')) === normalizeSqlWhitespace((string) ($tr['RPF_SQL'] ?? ''))) {
        $wsDiffs++;
    } else {
        $contentDiffs++;
    }
}

$catalog = [];
$stats = [
    'total_reports' => count($sqlParsed['rows']),
    'with_sql' => 0,
    'rpt_only' => 0,
    'read_only_sql' => 0,
    'non_read_only_sql' => 0,
    'enabled_y' => 0,
    'categories' => [],
    'doc_properties_mentions' => [],
    'select_flag_counts' => [],
    'reuse_counts' => [],
];

foreach ($sqlParsed['rows'] as $row) {
    $sql = trim((string) ($row['RPF_SQL'] ?? ''));
    $hasSql = $sql !== '';
    if ($hasSql) {
        $stats['with_sql']++;
    } else {
        $stats['rpt_only']++;
    }
    if (strtoupper(trim((string) ($row['RPF_ENABLE'] ?? ''))) === 'Y') {
        $stats['enabled_y']++;
    }

    $ro = checkReadOnlySql($sql);
    if ($hasSql) {
        $ro['read_only'] ? $stats['read_only_sql']++ : $stats['non_read_only_sql']++;
    }

    $tables = extractSqlTables($sql);
    $details = extractJoinAndFilterDetails($row);
    $categories = categorizeReport($row, $tables);
    $reuse = classifyReuse($row, $tables, $ro, $categories);

    foreach ($categories as $c) {
        $stats['categories'][$c] = ($stats['categories'][$c] ?? 0) + 1;
    }
    foreach ($details['select_flags'] as $sf) {
        $stats['select_flag_counts'][$sf] = ($stats['select_flag_counts'][$sf] ?? 0) + 1;
    }
    foreach ($details['dt_properties'] as $dp) {
        $stats['doc_properties_mentions'][$dp] = ($stats['doc_properties_mentions'][$dp] ?? 0) + 1;
    }
    $stats['reuse_counts'][$reuse['status']] = ($stats['reuse_counts'][$reuse['status']] ?? 0) + 1;

    $catalog[] = [
        'rpf_key' => (int) ($row['RPF_KEY'] ?? 0),
        'rpf_code' => trim((string) ($row['RPF_CODE'] ?? '')),
        'rpf_name' => trim((string) ($row['RPF_NAME'] ?? '')),
        'rpf_rptfile' => trim((string) ($row['RPF_RPTFILE'] ?? '')),
        'rpf_enable' => trim((string) ($row['RPF_ENABLE'] ?? '')),
        'has_sql' => $hasSql,
        'categories' => $categories,
        'tables' => $tables,
        'select_flags' => $details['select_flags'],
        'dt_properties' => $details['dt_properties'],
        'date_cols' => $details['date_cols'],
        'join_style' => $details['join_style'],
        'read_only' => $ro['read_only'],
        'read_only_reason' => $ro['reason'],
        'reuse_for' => $reuse['reuse'],
        'status' => $reuse['status'],
        'sql_preview' => $hasSql ? mb_substr(normalizeSqlWhitespace($sql), 0, 140, 'UTF-8') : '',
    ];
}

arsort($stats['categories']);
arsort($stats['select_flag_counts']);
ksort($stats['doc_properties_mentions']);

echo "=== RAW FORMAT INSPECTION ===\n";
foreach ([[$sqlInfo, $sqlParsed], [$tsvInfo, $tsvParsed]] as [$info, $parsed]) {
    echo sprintf(
        "- %s\n  Bytes: %d | Physical lines: %d | Encoding: %s | BOM: %s\n  Delimiter: %s | Quoting: %s | Line endings: %s\n  Header cols: %d | Parsed rows: %d | Bad rows: %d\n",
        $info['path'],
        $info['bytes'],
        $info['physical_lines'],
        $info['encoding'],
        $info['bom'],
        $info['delimiter'],
        $info['quoting'],
        $info['line_endings'],
        count($parsed['headers']),
        count($parsed['rows']),
        $parsed['bad_rows']
    );
}

echo "\n=== CROSS-FILE EQUIVALENCE (sql.csv vs รายงาน.csv) ===\n";
echo "Exact SQL match: {$exactMatches} | Whitespace-only SQL diff: {$wsDiffs} | Content diff: {$contentDiffs}\n";

echo "\n=== REPORT SUMMARY COUNTS ===\n";
echo "Total reports: {$stats['total_reports']} | Enabled (Y): {$stats['enabled_y']}\n";
echo "With RPF_SQL: {$stats['with_sql']} | RPT-only (empty RPF_SQL): {$stats['rpt_only']}\n";
echo "SQL Read-only (SELECT-only): {$stats['read_only_sql']} | Non-read-only flagged: {$stats['non_read_only_sql']}\n";
echo "\nCategories:\n";
foreach ($stats['categories'] as $cat => $cnt) {
    echo "  - {$cat}: {$cnt}\n";
}
echo "\nDT_PROPERTIES mentioned in RPF_SQL:\n";
foreach ($stats['doc_properties_mentions'] as $dp => $cnt) {
    echo "  - DT_PROPERTIES={$dp}: {$cnt} reports\n";
}
echo "\nActive RPF_SELECT_* runtime filter bitmasks:\n";
foreach ($stats['select_flag_counts'] as $sf => $cnt) {
    echo "  - {$sf}: {$cnt}\n";
}
echo "\nReuse status breakdown:\n";
foreach ($stats['reuse_counts'] as $st => $cnt) {
    echo "  - {$st}: {$cnt}\n";
}

if ($jsonOut !== null) {
    $dir = dirname($jsonOut);
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($jsonOut, json_encode([
        'files' => [
            'sql_csv' => array_diff_key($sqlInfo, ['text' => true]),
            'tab_csv' => array_diff_key($tsvInfo, ['text' => true]),
        ],
        'equivalence' => [
            'exact_matches' => $exactMatches,
            'whitespace_only_diffs' => $wsDiffs,
            'content_diffs' => $contentDiffs,
        ],
        'stats' => $stats,
        'catalog' => $catalog,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\nWrote JSON catalog to {$jsonOut}\n";
}
