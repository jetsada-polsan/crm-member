<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\StaffUser::query()->where('role', 'admin')->where('is_active', true)->orderBy('id')->first();
if (!$user) { throw new RuntimeException('No active admin available for the requested ERP sync.'); }
Illuminate\Support\Facades\Auth::guard('staff')->setUser($user);
$counts = function () {
    return [
        'customers' => Illuminate\Support\Facades\DB::table('erp_customers')->count(),
        'products' => Illuminate\Support\Facades\DB::table('erp_products')->count(),
        'invoices' => Illuminate\Support\Facades\DB::table('erp_sales_invoices')->count(),
        'sales_items' => Illuminate\Support\Facades\DB::table('erp_sales_items')->count(),
        'salespersons' => Illuminate\Support\Facades\DB::table('erp_salespersons')->count(),
        'members' => Illuminate\Support\Facades\DB::table('erp_members')->count(),
        'crm_customer_masters' => Illuminate\Support\Facades\DB::table('crm_customer_masters')->count(),
        'crm_leads' => Illuminate\Support\Facades\DB::table('crm_leads')->count(),
        'crm_customer_visits' => Illuminate\Support\Facades\DB::table('crm_customer_visits')->count(),
        'crm_visit_photos' => Illuminate\Support\Facades\DB::table('crm_visit_photos')->count(),
        'crm_follow_up_tasks' => Illuminate\Support\Facades\DB::table('crm_follow_up_tasks')->count(),
        'member_accounts' => Illuminate\Support\Facades\DB::table('member_accounts')->count(),
        'member_erp_links' => Illuminate\Support\Facades\DB::table('member_erp_links')->count(),
    ];
};
$kind = $argv[1] ?? 'status';
if ($kind === 'redact-run') {
    $runId = (int) ($argv[2] ?? 0);
    $run = Illuminate\Support\Facades\DB::table('sync_runs')->where('id', $runId)->where('kind', 'customers')->first();
    if (!$run) { throw new RuntimeException('Requested customer sync run not found.'); }
    Illuminate\Support\Facades\DB::table('sync_runs')->where('id', $runId)->update([
        'message' => 'Customer import failed on destination width; SQL values redacted.',
        'updated_at' => now(),
    ]);
    $errorCount = Illuminate\Support\Facades\DB::table('sync_errors')->where('sync_run_id', $runId)->update([
        'record_key' => null,
        'error_message' => 'Customer import failed on destination width; SQL values redacted.',
    ]);
    $logPath = storage_path('logs/laravel.log');
    $logRedacted = false;
    if (is_file($logPath)) {
        $logLines = file($logPath);
        for ($i = count($logLines) - 1; $i >= 0; $i--) {
            if (str_contains($logLines[$i], 'ERP local sync failed')) {
                $logLines[$i] = preg_replace('/ERP local sync failed.*/', 'ERP local sync failed [details redacted]', $logLines[$i]);
                file_put_contents($logPath, implode('', $logLines), LOCK_EX);
                $logRedacted = true;
                break;
            }
        }
    }
    echo json_encode(['run_redacted' => true, 'error_rows_redacted' => $errorCount, 'latest_sync_log_redacted' => $logRedacted]);
    exit(0);
}
if ($kind === 'status') {
    $runs = Illuminate\Support\Facades\DB::table('sync_runs')->orderByDesc('id')->limit(10)
        ->get(['id', 'kind', 'status', 'fetched_count', 'inserted_count', 'updated_count', 'started_at', 'finished_at']);
    echo json_encode(['admin_context'=>true, 'counts'=>$counts(), 'recent_runs'=>$runs], JSON_UNESCAPED_UNICODE);
    exit(0);
}
$limit = isset($argv[2]) ? (int)$argv[2] : 500;
$request = Illuminate\Http\Request::create('/api/crm/sync-monitor/sync/'.$kind, 'POST', ['limit'=>$limit]);
$response = app(App\Modules\ERPIntegration\ErpLocalSyncController::class)->syncKind($request, $kind);
$data = $response->getData(true);
$out = ['http_status'=>$response->getStatusCode(), 'ok'=>$data['ok'] ?? false, 'kind'=>$kind, 'result'=>array_intersect_key($data, array_flip(['fetched','inserted','updated','next_cursor','has_more','error'])), 'counts'=>$counts()];
echo json_encode($out, JSON_UNESCAPED_UNICODE);
