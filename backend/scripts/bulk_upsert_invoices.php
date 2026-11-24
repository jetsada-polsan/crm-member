<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Modules\CRM\CustomerAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$action = $argv[1] ?? '';

if ($action === '--get-cursor') {
    $source = $argv[2] ?? 'BPLUS';
    $row = DB::table('erp_sync_cursors')
        ->where('source_system', $source)
        ->where('kind', 'invoices')
        ->first();
    echo (int) ($row->cursor_value ?? 0);
    exit(0);
}

if ($action === '--finalize-staff') {
    $stats = CustomerAccess::syncStaffFromErp();
    $counts = [
        'invoices' => DB::table('erp_sales_invoices')->count(),
        'items' => DB::table('erp_sales_items')->count(),
        'date_range' => DB::table('erp_sales_invoices')->selectRaw('MIN(sold_at) as min_date, MAX(sold_at) as max_date')->first(),
        'staff_sync' => $stats,
    ];
    echo json_encode($counts, JSON_UNESCAPED_UNICODE);
    exit(0);
}

/**
 * Read a headered UTF-8 TSV file into an array of associative arrays.
 *
 * @return list<array<string, string>>
 */
function readTsvRows(string $path): array
{
    if (! is_file($path)) {
        return [];
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return [];
    }
    $headerLine = fgets($fh);
    if ($headerLine === false) {
        fclose($fh);

        return [];
    }
    if (str_starts_with($headerLine, "\xEF\xBB\xBF")) {
        $headerLine = substr($headerLine, 3);
    }
    $headers = explode("\t", rtrim($headerLine, "\r\n"));
    $colCount = count($headers);
    $rows = [];
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            continue;
        }
        $parts = explode("\t", $line);
        $row = [];
        for ($i = 0; $i < $colCount; $i++) {
            $row[$headers[$i]] = $parts[$i] ?? '';
        }
        $rows[] = $row;
    }
    fclose($fh);

    return $rows;
}

$source = 'BPLUS';
$nextCursor = 0;
$rawInvoices = [];
$rawItems = [];

if ($action === '--tsv') {
    $source = (string) ($argv[2] ?? 'BPLUS');
    $nextCursor = (int) ($argv[3] ?? 0);
    $invPath = (string) ($argv[4] ?? '');
    $itemPath = (string) ($argv[5] ?? '');
    $rawInvoices = readTsvRows($invPath);
    $rawItems = readTsvRows($itemPath);
} else {
    $jsonPath = $action;
    if ($jsonPath === '' || ! is_file($jsonPath)) {
        fwrite(STDERR, "Usage: php bulk_upsert_invoices.php --tsv <SOURCE> <NEXT_CURSOR> <inv.tsv> <items.tsv>\n");
        exit(2);
    }
    $raw = file_get_contents($jsonPath);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $payload = json_decode($raw, true);
    if (! is_array($payload)) {
        fwrite(STDERR, "Invalid JSON payload in {$jsonPath}\n");
        exit(1);
    }
    $source = (string) ($payload['source'] ?? 'BPLUS');
    $nextCursor = (int) ($payload['next_cursor'] ?? 0);
    $rawInvoices = $payload['invoices'] ?? [];
    $rawItems = $payload['items'] ?? [];
}

$now = now()->toDateTimeString();

// Deduplicate invoices by business unique key (source_system, branch_code, doc_type, invoice_no)
$invMap = [];
foreach ($rawInvoices as $inv) {
    $branch = trim((string) ($inv['branch_code'] ?? '001')) ?: '001';
    $docType = trim((string) ($inv['doc_type'] ?? 'SALE'));
    $invNo = trim((string) ($inv['invoice_no'] ?? ''));
    if ($docType === '' || $invNo === '') {
        continue;
    }
    $key = $source.'|'.$branch.'|'.$docType.'|'.$invNo;
    $invMap[$key] = [
        'source_system' => $source,
        'branch_code' => mb_substr($branch, 0, 32),
        'doc_type' => mb_substr($docType, 0, 32),
        'invoice_no' => mb_substr($invNo, 0, 80),
        'erp_customer_code' => mb_substr(trim((string) ($inv['erp_customer_code'] ?? '')), 0, 64),
        'salesperson_code' => isset($inv['salesperson_code']) && trim((string) $inv['salesperson_code']) !== ''
            ? mb_substr(trim((string) $inv['salesperson_code']), 0, 32)
            : null,
        'salesperson_name' => isset($inv['salesperson_name']) && trim((string) $inv['salesperson_name']) !== ''
            ? mb_substr(trim((string) $inv['salesperson_name']), 0, 120)
            : null,
        'sold_at' => (string) ($inv['sold_at'] ?? $now),
        'dt_properties' => isset($inv['dt_properties']) && trim((string) $inv['dt_properties']) !== '' ? (int) $inv['dt_properties'] : null,
        'doc_category' => mb_substr((string) ($inv['doc_category'] ?? 'sale'), 0, 24),
        'before_vat_amount' => isset($inv['before_vat_amount']) && trim((string) $inv['before_vat_amount']) !== '' ? (string) $inv['before_vat_amount'] : '0.00',
        'vat_amount' => isset($inv['vat_amount']) && trim((string) $inv['vat_amount']) !== '' ? (string) $inv['vat_amount'] : '0.00',
        'gross_amount' => isset($inv['gross_amount']) && trim((string) $inv['gross_amount']) !== '' ? (string) $inv['gross_amount'] : '0.00',
        'net_amount' => isset($inv['net_amount']) && trim((string) $inv['net_amount']) !== '' ? (string) $inv['net_amount'] : '0.00',
        'status' => mb_substr((string) ($inv['status'] ?? 'posted'), 0, 20),
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

// Deduplicate items by business unique key (source_system, branch_code, doc_type, invoice_no, line_no)
$itemMap = [];
foreach ($rawItems as $it) {
    $branch = trim((string) ($it['branch_code'] ?? '001')) ?: '001';
    $docType = trim((string) ($it['doc_type'] ?? 'SALE'));
    $invNo = trim((string) ($it['invoice_no'] ?? ''));
    $lineNo = (int) ($it['line_no'] ?? 0);
    if ($docType === '' || $invNo === '' || $lineNo <= 0) {
        continue;
    }
    $key = $source.'|'.$branch.'|'.$docType.'|'.$invNo.'|'.$lineNo;
    $iccatCode = isset($it['iccat_code']) && trim((string) $it['iccat_code']) !== ''
        ? mb_substr(trim((string) $it['iccat_code']), 0, 32)
        : null;
    $rawEx = strtolower(trim((string) ($it['is_excluded_kpi'] ?? '0')));
    $isExcluded = in_array($rawEx, ['1', 'true', 't', 'yes'], true);
    if (! $isExcluded && $iccatCode !== null && in_array(strtoupper($iccatCode), ['CC', 'CO', 'AS', 'PK'], true)) {
        $isExcluded = true;
    }

    $itemMap[$key] = [
        'source_system' => $source,
        'branch_code' => mb_substr($branch, 0, 32),
        'doc_type' => mb_substr($docType, 0, 32),
        'invoice_no' => mb_substr($invNo, 0, 80),
        'line_no' => $lineNo,
        'erp_product_code' => mb_substr(trim((string) ($it['erp_product_code'] ?? 'UNKNOWN')), 0, 64),
        'product_name' => mb_substr(trim((string) ($it['product_name'] ?? 'สินค้า')), 0, 255),
        'iccat_code' => $iccatCode,
        'iccat_name' => isset($it['iccat_name']) && trim((string) $it['iccat_name']) !== ''
            ? mb_substr(trim((string) $it['iccat_name']), 0, 120)
            : null,
        'is_excluded_kpi' => $isExcluded,
        'qty' => isset($it['qty']) && trim((string) $it['qty']) !== '' ? (string) $it['qty'] : '0',
        'unit_name' => isset($it['unit_name']) && trim((string) $it['unit_name']) !== ''
            ? mb_substr(trim((string) $it['unit_name']), 0, 60)
            : null,
        'unit_price' => isset($it['unit_price']) && trim((string) $it['unit_price']) !== '' ? (string) $it['unit_price'] : '0',
        'before_vat_amount' => isset($it['before_vat_amount']) && trim((string) $it['before_vat_amount']) !== '' ? (string) $it['before_vat_amount'] : '0',
        'vat_amount' => isset($it['vat_amount']) && trim((string) $it['vat_amount']) !== '' ? (string) $it['vat_amount'] : '0',
        'gross_amount' => isset($it['gross_amount']) && trim((string) $it['gross_amount']) !== '' ? (string) $it['gross_amount'] : '0',
        'net_amount' => isset($it['net_amount']) && trim((string) $it['net_amount']) !== '' ? (string) $it['net_amount'] : '0',
        'refer_trd_key' => isset($it['refer_trd_key']) && trim((string) $it['refer_trd_key']) !== ''
            ? mb_substr(trim((string) $it['refer_trd_key']), 0, 64)
            : null,
        'refer_doc_ref' => isset($it['refer_doc_ref']) && trim((string) $it['refer_doc_ref']) !== ''
            ? mb_substr(trim((string) $it['refer_doc_ref']), 0, 80)
            : null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

$invRows = array_values($invMap);
$itemRows = array_values($itemMap);

DB::transaction(function () use ($invRows, $itemRows, $source, $nextCursor, $now): void {
    foreach (array_chunk($invRows, 500) as $chunk) {
        DB::table('erp_sales_invoices')->upsert(
            $chunk,
            ['source_system', 'branch_code', 'doc_type', 'invoice_no'],
            [
                'erp_customer_code',
                'salesperson_code',
                'salesperson_name',
                'sold_at',
                'dt_properties',
                'doc_category',
                'before_vat_amount',
                'vat_amount',
                'gross_amount',
                'net_amount',
                'status',
                'updated_at',
            ]
        );
    }

    foreach (array_chunk($itemRows, 600) as $chunk) {
        DB::table('erp_sales_items')->upsert(
            $chunk,
            ['source_system', 'branch_code', 'doc_type', 'invoice_no', 'line_no'],
            [
                'erp_product_code',
                'product_name',
                'iccat_code',
                'iccat_name',
                'is_excluded_kpi',
                'qty',
                'unit_name',
                'unit_price',
                'before_vat_amount',
                'vat_amount',
                'gross_amount',
                'net_amount',
                'refer_trd_key',
                'refer_doc_ref',
                'updated_at',
            ]
        );
    }

    if ($nextCursor > 0) {
        DB::table('erp_sync_cursors')->upsert(
            [[
                'source_system' => $source,
                'kind' => 'invoices',
                'cursor_value' => $nextCursor,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['source_system', 'kind'],
            ['cursor_value', 'updated_at']
        );
    }
});

echo json_encode([
    'ok' => true,
    'invoices_upserted' => count($invRows),
    'items_upserted' => count($itemRows),
    'next_cursor' => $nextCursor,
], JSON_UNESCAPED_UNICODE);
