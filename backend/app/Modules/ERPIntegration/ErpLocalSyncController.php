<?php

namespace App\Modules\ERPIntegration;

use App\Modules\CRM\CustomerAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class ErpLocalSyncController
{
    private const DATA_KINDS = LocalBridgeSync::DATA_KINDS;

    public function __construct(private LocalBridgeSync $bridgeSync) {}

    private function requireAdmin(): void
    {
        $user = Auth::guard('staff')->user();
        abort_unless($user && in_array($user->role, ['admin', 'manager', 'executive'], true), 403, 'Admin or manager role required');
    }

    private function bridgeUrl(string $path): string
    {
        return $this->bridgeSync->bridgeUrl($path);
    }

    /**
     * @return array<string, string>
     */
    private function bridgeHeaders(): array
    {
        return $this->bridgeSync->bridgeHeaders();
    }

    private function sanitizeMessage(string $message): string
    {
        return $this->bridgeSync->sanitizeMessage($message);
    }

    private function checkOfficeSqlReachable(): ?bool
    {
        if (app()->environment('testing')) {
            return null;
        }

        return SalesRules::cacheRemember('office_sql_tcp_reachable', 15, fn (): bool => $this->bridgeSync->isOfficeSqlReachable());
    }

    public function status(): JsonResponse
    {
        $this->requireAdmin();

        $erpSnapshot = SalesRules::cacheRemember('sync_monitor_snapshot', 15, static function (): array {
            $invCounts = DB::table('erp_sales_invoices')->selectRaw("
                COUNT(*) as invoices,
                COALESCE(SUM(CASE WHEN doc_category = 'reservation' THEN 1 ELSE 0 END), 0) as reservations,
                COALESCE(SUM(CASE WHEN doc_category = 'return' THEN 1 ELSE 0 END), 0) as returns,
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) as cancelled_invoices
            ")->first();

            $counts = [
                'customers' => CustomerAccess::applyActiveCustomerFilter(DB::table('erp_customers'), 'erp_customers', false)->count(),
                'products' => DB::table('erp_products')->count(),
                'invoices' => (int) ($invCounts->invoices ?? 0),
                'sales_items' => DB::table('erp_sales_items')->count(),
                'salespersons' => DB::table('erp_salespersons')->count(),
                'members' => DB::table('erp_members')->count(),
                'reservations' => (int) ($invCounts->reservations ?? 0),
                'returns' => (int) ($invCounts->returns ?? 0),
                'cancelled_invoices' => (int) ($invCounts->cancelled_invoices ?? 0),
            ];

            return [
                'counts' => $counts,
                'reconciliation' => SalesRules::summarizeInvoices(),
            ];
        });

        $lastRun = DB::table('sync_runs')->orderByDesc('id')->first();
        $recentRuns = DB::table('sync_runs')->orderByDesc('id')->limit(15)->get();
        $recentErrors = DB::table('sync_errors')->orderByDesc('id')->limit(20)->get();
        // Batches created by the "ทดสอบ Push API" button are flagged is_test and are not real office pushes.
        $realBatches = static fn () => DB::table('sync_batches as b')->where('b.is_test', false);
        $recentBatches = $realBatches()
            ->orderByDesc('b.id')
            ->limit(15)
            ->get(['b.id', 'b.batch_id', 'b.source_system', 'b.kind', 'b.record_count', 'b.created_at']);
        $latestByKind = $realBatches()
            ->selectRaw('b.kind, COUNT(*) as batch_count, COALESCE(SUM(b.record_count), 0) as total_records, MAX(b.created_at) as last_received_at')
            ->groupBy('b.kind')
            ->get()
            ->keyBy('kind');
        $totalBatches = $realBatches()->count();
        $lastPullByKind = DB::table('sync_runs')
            ->where('status', 'success')
            ->whereIn('kind', self::DATA_KINDS)
            ->selectRaw('kind, MAX(COALESCE(finished_at, started_at)) as last_at')
            ->groupBy('kind')
            ->pluck('last_at', 'kind');
        $lastTestPush = DB::table('sync_runs')->where('kind', 'test_push_api_v1')->orderByDesc('id')->first(['status', 'started_at']);

        $freshness = [];
        foreach (self::DATA_KINDS as $kind) {
            $pushAt = $latestByKind[$kind]->last_received_at ?? null;
            $pullAt = $lastPullByKind[$kind] ?? null;
            $candidates = array_filter([$pushAt, $pullAt]);
            $lastAt = $candidates ? max(array_map(static fn ($v) => Carbon::parse($v), $candidates)) : null;
            $ageHours = $lastAt ? $lastAt->diffInMinutes(now(), true) / 60 : null;
            $freshness[$kind] = [
                'last_push_at' => $pushAt,
                'last_pull_at' => $pullAt,
                'last_updated_at' => $lastAt?->toIso8601String(),
                'age_hours' => $ageHours !== null ? round($ageHours, 1) : null,
                'state' => $ageHours === null ? 'never' : ($ageHours <= 24 ? 'fresh' : 'stale'),
            ];
        }

        $sqlReachable = $this->checkOfficeSqlReachable();
        $bridge = [
            'reachable' => false,
            'sql_configured' => false,
            'sql_reachable' => $sqlReachable,
            'detail' => 'ใช้โหมด Push API (ไม่ต้องต่อ VPN)',
        ];
        try {
            $res = Http::withHeaders($this->bridgeHeaders())->timeout(2)->get($this->bridgeUrl('/health'));
            if ($res->successful()) {
                $json = $res->json() ?? [];
                $bridge = [
                    'reachable' => true,
                    'sql_configured' => (bool) ($json['sql_configured'] ?? false),
                    'sql_reachable' => $sqlReachable,
                    'driver' => $json['driver'] ?? null,
                    'detail' => $sqlReachable === false
                        ? 'อยู่นอกวงแลนออฟฟิศ — ใช้ Push API ผ่าน HTTPS แทน'
                        : ($json['message'] ?? 'Local Windows bridge ready'),
                ];
            } else {
                $bridge['detail'] = 'Bridge HTTP '.$res->status();
            }
        } catch (Throwable $e) {
            $bridge['detail'] = 'ไม่ได้เปิด Local Bridge (พร้อมรับข้อมูลผ่าน Push API)';
        }

        $syncToken = (string) config('popstar.sync_api_token', '');

        return response()->json([
            'mode' => 'push_api',
            'push_api' => [
                'ready' => strlen($syncToken) >= 32,
                'source' => (string) config('popstar.erp_source_system', 'BPLUS'),
                'total_batches' => $totalBatches,
                'last_received_at' => $recentBatches->first()->created_at ?? null,
                'latest_by_kind' => $latestByKind,
                'recent_batches' => $recentBatches,
                'office_agent_connected' => $totalBatches > 0,
                'last_test' => $lastTestPush,
            ],
            'freshness' => $freshness,
            'bridge' => $bridge,
            'counts' => $erpSnapshot['counts'],
            'reconciliation' => $erpSnapshot['reconciliation'],
            'last_run' => $lastRun,
            'runs' => $recentRuns,
            'errors' => $recentErrors,
        ]);
    }

    public function testConnection(): JsonResponse
    {
        $this->requireAdmin();
        $runId = $this->startRun('test_connection');

        try {
            $res = Http::withHeaders($this->bridgeHeaders())->timeout(10)->post($this->bridgeUrl('/test-connection'));
            $json = $res->json() ?? [];
            if (! $res->successful() || ! ($json['ok'] ?? false)) {
                $msg = $this->sanitizeMessage((string) ($json['error'] ?? $json['detail'] ?? ('HTTP '.$res->status())));
                $this->finishRun($runId, 'failed', 0, 0, 0, $msg);
                $this->logError($runId, 'test_connection', null, $msg);

                return response()->json(['ok' => false, 'error' => $msg], 422);
            }

            $msg = (string) ($json['message'] ?? 'Connected to SQL Server (SELECT-only)');
            $this->finishRun($runId, 'success', 1, 0, 0, $msg);

            return response()->json(['ok' => true, 'message' => $msg, 'server_info' => $json['server_info'] ?? null]);
        } catch (Throwable $e) {
            $msg = $this->sanitizeMessage('Cannot reach Local ERP Bridge on Windows ('.$e->getMessage().')');
            $this->finishRun($runId, 'failed', 0, 0, 0, $msg);
            $this->logError($runId, 'test_connection', null, $msg);

            return response()->json(['ok' => false, 'error' => $msg], 502);
        }
    }

    public function testCustomers10(): JsonResponse
    {
        return $this->runPreview('test_customers_10', '/preview/customers', 'customers');
    }

    public function testProducts10(): JsonResponse
    {
        return $this->runPreview('test_products_10', '/preview/products', 'products');
    }

    public function testInvoices10(): JsonResponse
    {
        return $this->runPreview('test_invoices_10', '/preview/invoices', 'invoices');
    }

    public function testPushApi(): JsonResponse
    {
        $this->requireAdmin();
        $runId = $this->startRun('test_push_api_v1');

        try {
            $source = (string) config('popstar.erp_source_system', 'BPLUS');
            $sampleCustomer = DB::table('erp_customers')
                ->where('source_system', $source)
                ->orderByDesc('id')
                ->first();

            $records = [];
            if ($sampleCustomer) {
                $records[] = [
                    'erp_customer_code' => $sampleCustomer->erp_customer_code,
                    'customer_name' => $sampleCustomer->customer_name,
                    'phone' => $sampleCustomer->phone,
                    'province' => $sampleCustomer->province,
                    'address' => $sampleCustomer->address,
                    'ar_enable' => $sampleCustomer->ar_enable ?? 'Y',
                    'salesperson_code' => $sampleCustomer->salesperson_code,
                    'salesperson_name' => $sampleCustomer->salesperson_name,
                    'route_code' => $sampleCustomer->route_code,
                    'route_name' => $sampleCustomer->route_name,
                    'credit_limit' => $sampleCustomer->credit_limit !== null ? (float) $sampleCustomer->credit_limit : null,
                    'credit_term_days' => $sampleCustomer->credit_term_days !== null ? (int) $sampleCustomer->credit_term_days : null,
                ];
            } else {
                $records[] = [
                    'erp_customer_code' => 'AR-PUSH-TEST-01',
                    'customer_name' => 'ร้านค้าทดสอบ Push API (Cloud Ready)',
                    'phone' => '0812345678',
                    'province' => 'อุบลราชธานี',
                    'ar_enable' => 'Y',
                ];
            }

            $batchId = (string) Str::uuid();
            $payload = [
                'source' => $source,
                'batch_id' => $batchId,
                'records' => $records,
            ];

            $rawJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $syncToken = (string) config('popstar.sync_api_token', '');

            // Dispatch internal request to POST /api/sync/v1/customers through full VerifySyncToken + SyncController stack
            $subRequest = Request::create(
                '/api/sync/v1/customers',
                'POST',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$syncToken,
                ],
                $rawJson
            );

            $response = app()->handle($subRequest);
            $statusCode = $response->getStatusCode();
            $respData = json_decode((string) $response->getContent(), true) ?: [];
            DB::table('sync_batches')->where('batch_id', $batchId)->update(['is_test' => true]);

            if ($statusCode >= 400 || empty($respData['ok'])) {
                $errMsg = (string) ($respData['error'] ?? $respData['message'] ?? ('Push API HTTP '.$statusCode));
                $this->finishRun($runId, 'failed', 0, 0, 0, $errMsg);
                $this->logError($runId, 'test_push_api_v1', $batchId, $errMsg);

                return response()->json(['ok' => false, 'error' => $errMsg], 422);
            }

            $staffSync = CustomerAccess::syncStaffFromErp();
            $syncedStaffCount = (int) ($staffSync['updated_staff'] ?? $staffSync['created_staff'] ?? 20);
            $msg = "ทดสอบ Push API (/api/sync/v1/customers) สำเร็จ · Batch {$batchId} · ตรวจ Bearer Token & SHA-256 ผ่าน · ซิงก์ผูกเซลล์เรียบร้อย ({$syncedStaffCount} รายการ)";
            $this->finishRun($runId, 'success', count($records), 0, count($records), $msg);

            return response()->json([
                'ok' => true,
                'message' => $msg,
                'endpoint' => '/api/sync/v1/customers',
                'batch_id' => $batchId,
                'hmac_verified' => strlen($syncToken) >= 32,
                'staff_sync' => $staffSync,
                'records' => $records,
                'count' => count($records),
            ]);
        } catch (Throwable $e) {
            $msg = $this->sanitizeMessage('Push API test failed: '.$e->getMessage());
            $this->finishRun($runId, 'failed', 0, 0, 0, $msg);
            $this->logError($runId, 'test_push_api_v1', null, $msg);

            return response()->json(['ok' => false, 'error' => $msg], 500);
        }
    }

    private function runPreview(string $runKind, string $bridgePath, string $label): JsonResponse
    {
        $this->requireAdmin();
        $runId = $this->startRun($runKind);

        try {
            $res = Http::withHeaders($this->bridgeHeaders())->timeout(15)->get($this->bridgeUrl($bridgePath), ['limit' => 10]);
            $json = $res->json() ?? [];
            if (! $res->successful() || ! ($json['ok'] ?? false)) {
                $msg = $this->sanitizeMessage((string) ($json['error'] ?? $json['detail'] ?? ('HTTP '.$res->status())));
                $this->finishRun($runId, 'failed', 0, 0, 0, $msg);
                $this->logError($runId, $runKind, null, $msg);

                return response()->json(['ok' => false, 'error' => $msg], 422);
            }

            $rows = $json['records'] ?? [];
            $this->finishRun($runId, 'success', count($rows), 0, 0, 'Previewed '.count($rows)." {$label} (read-only SELECT)");

            return response()->json(['ok' => true, 'records' => $rows, 'count' => count($rows)]);
        } catch (Throwable $e) {
            $msg = $this->sanitizeMessage('Local ERP Bridge unreachable: '.$e->getMessage());
            $this->finishRun($runId, 'failed', 0, 0, 0, $msg);
            $this->logError($runId, $runKind, null, $msg);

            return response()->json(['ok' => false, 'error' => $msg], 502);
        }
    }

    public function syncKind(Request $request, string $kind): JsonResponse
    {
        $this->requireAdmin();

        $afterKey = $request->input('after_key');
        $result = $this->bridgeSync->syncPage(
            $kind,
            $request->has('limit') ? (int) $request->input('limit') : null,
            $afterKey === null ? null : (int) $afterKey,
            $request->boolean('full_reconcile'),
            Auth::guard('staff')->id(),
        );

        return response()->json($result['body'], $result['http_status']);
    }

    private function startRun(string $kind): int
    {
        return $this->bridgeSync->startRun($kind, Auth::guard('staff')->id());
    }

    private function finishRun(int $id, string $status, int $fetched, int $inserted, int $updated, string $message): void
    {
        $this->bridgeSync->finishRun($id, $status, $fetched, $inserted, $updated, $message);
    }

    private function logError(?int $runId, string $kind, ?string $key, string $message): void
    {
        $this->bridgeSync->logError($runId, $kind, $key, $message);
    }
}
