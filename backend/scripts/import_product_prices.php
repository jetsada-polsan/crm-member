<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$path = $argv[1] ?? '';
$payload = $path && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
if (! is_array($payload) || ! isset($payload['rows']) || ! is_array($payload['rows']) || count($payload['rows']) === 0) {
    fwrite(STDERR, "Price snapshot is empty or invalid; existing prices were not changed.\n");
    exit(1);
}
$rows = [];
foreach ($payload['rows'] as $entry) {
    if (! isset($entry['price_key'], $entry['sku'], $entry['goods'], $entry['name'], $entry['price']) || ! is_numeric($entry['price']) || (float) $entry['price'] < 0) {
        fwrite(STDERR, "Invalid price row; existing prices were not changed.\n");
        exit(1);
    }
    $key = (string) $entry['price_key'];
    if (isset($rows[$key])) {
        fwrite(STDERR, "Duplicate source price key; existing prices were not changed.\n");
        exit(1);
    }
    $rows[$key] = [
        'source_system' => 'BPLUS',
        'price_book_code' => '0',
        'erp_price_key' => $key,
        'erp_product_code' => (string) $entry['sku'],
        'goods_code' => (string) $entry['goods'],
        'product_name' => (string) $entry['name'],
        'category_name' => isset($entry['category']) && trim((string) $entry['category']) !== '' ? mb_substr(trim((string) $entry['category']), 0, 120) : null,
        'unit' => isset($entry['unit']) && trim((string) $entry['unit']) !== '' ? trim((string) $entry['unit']) : null,
        'unit_qty' => isset($entry['unit_qty']) && is_numeric($entry['unit_qty']) ? (string) $entry['unit_qty'] : null,
        'price' => (string) $entry['price'],
    ];
}
$syncedAt = Carbon::now('UTC')->toIso8601String();
DB::transaction(function () use ($rows, $syncedAt): void {
    DB::table('erp_product_prices')->where('source_system', 'BPLUS')->where('price_book_code', '0')->delete();
    foreach (array_chunk(array_values($rows), 300) as $chunk) {
        DB::table('erp_product_prices')->insert($chunk);
    }
    DB::table('erp_price_snapshots')->updateOrInsert(['source_system' => 'BPLUS', 'price_book_code' => '0'], ['synced_at' => $syncedAt, 'row_count' => count($rows)]);
});
echo json_encode(['rows' => count($rows), 'synced_at' => $syncedAt], JSON_UNESCAPED_UNICODE).PHP_EOL;
