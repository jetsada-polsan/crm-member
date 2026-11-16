<?php

declare(strict_types=1);

$pagesPerRun = 10;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        echo "Usage: php reconcile-invoices-2026.php [--pages=1..100]\n";
        echo "Reads up to 100 invoices per page and saves a checkpoint after each page.\n";
        exit(0);
    }
    if (preg_match('/^--pages=(\d+)$/', $argument, $matches) && (int) $matches[1] >= 1 && (int) $matches[1] <= 100) {
        $pagesPerRun = (int) $matches[1];
        continue;
    }
    fwrite(STDERR, "Invalid option. Use --pages=1..100 or --help.\n");
    exit(1);
}

$backend = dirname(__DIR__).DIRECTORY_SEPARATOR.'backend';
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_time_limit(0);

$lock = fopen($backend.'/storage/app/invoice-reconcile-2026.lock', 'c');
if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Invoice reconciliation is already running or its lock cannot be opened.\n");
    exit(1);
}

$checkpoint = $backend.'/storage/app/invoice-reconcile-2026.json';
$saved = is_file($checkpoint) ? json_decode((string) file_get_contents($checkpoint), true) : null;
if (is_file($checkpoint) && (! is_array($saved) || ! isset($saved['cursor'], $saved['pages'], $saved['fetched'], $saved['completed']))) {
    fwrite(STDERR, "Checkpoint is unreadable. Sync stopped to avoid restarting unexpectedly.\n");
    exit(1);
}
if ($saved['completed'] ?? false) {
    echo "This reconciliation is already complete.\n";
    exit(0);
}
$cursor = max(0, (int) ($saved['cursor'] ?? 0));
$pages = (int) ($saved['pages'] ?? 0);
$total = (int) ($saved['fetched'] ?? 0);
$admin = App\Models\StaffUser::query()->whereIn('role', ['admin', 'manager'])->where('is_active', true)->orderBy('id')->firstOrFail();
Illuminate\Support\Facades\Auth::guard('staff')->setUser($admin);

$pagesThisRun = 0;
echo "Resuming from {$total} invoices. This run will process up to {$pagesPerRun} pages (100 invoices per page).\n";
while (true) {
    $request = Illuminate\Http\Request::create('/', 'POST', [
        'limit' => 100,
        'after_key' => $cursor,
        'full_reconcile' => true,
    ]);
    $response = $app->make(App\Modules\ERPIntegration\ErpLocalSyncController::class)->syncKind($request, 'invoices');
    $data = $response->getData(true);
    if (! ($data['ok'] ?? false)) {
        fwrite(STDERR, 'Sync stopped: '.($data['error'] ?? 'unknown error').PHP_EOL);
        exit(1);
    }
    $next = (int) ($data['next_cursor'] ?? $cursor);
    if (($data['has_more'] ?? false) && $next <= $cursor) {
        fwrite(STDERR, "Sync stopped: cursor did not advance.\n");
        exit(1);
    }
    $cursor = $next;
    $pages++;
    $pagesThisRun++;
    $total += (int) ($data['fetched'] ?? 0);
    file_put_contents($checkpoint, json_encode([
        'cursor' => $cursor, 'pages' => $pages, 'fetched' => $total,
        'completed' => ! ($data['has_more'] ?? false), 'updated_at' => date(DATE_ATOM),
    ], JSON_PRETTY_PRINT), LOCK_EX);
    echo "pages={$pages} fetched={$total} cursor={$cursor}\n";
    if (function_exists('ob_flush')) { @ob_flush(); }
    flush();
    if (! ($data['has_more'] ?? false)) {
        echo "Sync complete.\n";
        break;
    }
    if ($pagesThisRun >= $pagesPerRun) {
        echo "This run is complete. Run it again to continue from the saved checkpoint.\n";
        break;
    }
    gc_collect_cycles();
}
