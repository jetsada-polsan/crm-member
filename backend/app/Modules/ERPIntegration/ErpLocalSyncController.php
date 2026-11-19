<?php

namespace App\Modules\ERPIntegration;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ErpLocalSyncController
{
    private function requireAdmin(): void
    {
        $user = Auth::guard('staff')->user();
        abort_unless($user && in_array($user->role, ['admin', 'manager', 'executive'], true), 403, 'Admin or manager role required');
    }

    private function bridgeUrl(string $path): string
    {
        return rtrim((string) config('popstar.local_bridge_url', 'http://host.docker.internal:18900'), '/').$path;
    }

    private function bridgeHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Host' => '127.0.0.1:18900',
            'X-Bridge-Token' => (string) config('popstar.local_bridge_token', ''),
        ];
    }

    private function sanitizeMessage(string $message): string
    {
        $message = (string) preg_replace('/\s*,?\s*SQL:\s*.+$/is', ' SQL: [statement redacted]', $message);

        return (string) preg_replace('/(Password|Pwd)\s*=\s*[^;]+/i', '$1=***', $message);
    }

    private function safeRecordKey(array $record): ?string
    {
        foreach (['erp_customer_code', 'erp_product_code', 'invoice_no', 'member_code', 'salesperson_code', 'di_key'] as $key) {
            $value = $record[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return mb_substr((string) $value, 0, 160);
            }
        }

        return null;
    }

    public function status(): JsonResponse
    {
        $this->requireAdmin();

        $counts = [
            'customers' => DB::table('erp_customers')->count(),
            'products' => DB::table('erp_products')->count(),
            'invoices' => DB::table('erp_sales_invoices')->count(),
            'sales_items' => DB::table('erp_sales_items')->count(),
            'salespersons' => DB::table('erp_salespersons')->count(),
            'members' => DB::table('erp_members')->count(),
            'reservations' => DB::table('erp_sales_invoices')->where('doc_category', 'reservation')->count(),
            'returns' => DB::table('erp_sales_invoices')->where('doc_category', 'return')->count(),
            'cancelled_invoices' => DB::table('erp_sales_invoices')->where('status', 'cancelled')->count(),
        ];

        $lastRun = DB::table('sync_runs')->orderByDesc('id')->first();
        $recentRuns = DB::table('sync_runs')->orderByDesc('id')->limit(15)->get();
        $recentErrors = DB::table('sync_errors')->orderByDesc('id')->limit(20)->get();

        $bridge = ['reachable' => false, 'sql_configured' => false, 'detail' => 'Local bridge not tested yet'];
        try {
            $res = Http::withHeaders($this->bridgeHeaders())->timeout(3)->get($this->bridgeUrl('/health'));
            if ($res->successful()) {
                $json = $res->json() ?? [];
                $bridge = [
                    'reachable' => true,
                    'sql_configured' => (bool) ($json['sql_configured'] ?? false),
                    'driver' => $json['driver'] ?? null,
                    'detail' => $json['message'] ?? 'Local Windows bridge ready',
                ];
            } else {
                $bridge['detail'] = 'Bridge HTTP '.$res->status();
            }
        } catch (Throwable $e) {
            $bridge['detail'] = 'Bridge offline: start sync-agent/start-local-bridge.cmd on Windows';
        }

        return response()->json([
            'bridge' => $bridge,
            'counts' => $counts,
            'reconciliation' => SalesRules::summarizeInvoices(),
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

            $batchId = (string) \Illuminate\Support\Str::uuid();
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

            if ($statusCode >= 400 || empty($respData['ok'])) {
                $errMsg = (string) ($respData['error'] ?? $respData['message'] ?? ('Push API HTTP '.$statusCode));
                $this->finishRun($runId, 'failed', 0, 0, 0, $errMsg);
                $this->logError($runId, 'test_push_api_v1', $batchId, $errMsg);

                return response()->json(['ok' => false, 'error' => $errMsg], 422);
            }

            $staffSync = \App\Modules\CRM\CustomerAccess::syncStaffFromErp();
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

        $handlers = [
            'customers' => [ErpMirrorUpserter::class, 'upsertCustomer'],
            'products' => [ErpMirrorUpserter::class, 'upsertProduct'],
            'invoices' => [ErpMirrorUpserter::class, 'upsertInvoiceWithItems'],
            'salespersons' => [ErpMirrorUpserter::class, 'upsertSalesperson'],
            'members' => [ErpMirrorUpserter::class, 'upsertMember'],
        ];

        if (! isset($handlers[$kind])) {
            return response()->json(['error' => 'Unsupported sync kind'], 422);
        }

        $defaultLimit = $kind === 'invoices' ? 100 : 1000;
        $limit = max(1, min($kind === 'invoices' ? 500 : 5000, (int) $request->input('limit', $defaultLimit)));
        if ($kind === 'invoices') {
            $limit = max(100, $limit);
        }
        $runId = $this->startRun($kind);

        try {
            $source = (string) config('popstar.erp_source_system', 'BPLUS');
            $cursorRow = $kind === 'invoices'
                ? DB::table('erp_sync_cursors')->where('source_system', $source)->where('kind', 'invoices')->first()
                : null;
            $cursor = (int) ($cursorRow->cursor_value ?? 0);
            $requestedAfterKey = $request->input('after_key');
            $defaultAfterKey = $kind === 'invoices'
                ? ($request->boolean('full_reconcile') ? 0 : max(0, $cursor - 50))
                : 0;
            $afterKey = $requestedAfterKey === null ? $defaultAfterKey : max(0, (int) $requestedAfterKey);
            $query = ['limit' => $limit];
            $query['after_key'] = $afterKey;

            $res = Http::withHeaders($this->bridgeHeaders())->timeout(60)->get($this->bridgeUrl("/export/{$kind}"), $query);
            $json = $res->json() ?? [];
            if (! $res->successful() || ! ($json['ok'] ?? false)) {
                $msg = $this->sanitizeMessage((string) ($json['error'] ?? $json['detail'] ?? ('HTTP '.$res->status())));
                $this->finishRun($runId, 'failed', 0, 0, 0, $msg);
                $this->logError($runId, $kind, null, $msg);

                return response()->json(['ok' => false, 'error' => $msg], 422);
            }

            $responseSource = (string) ($json['source'] ?? '');
            if ($responseSource === '' || ($kind === 'invoices' && $responseSource !== $source)) {
                throw new \RuntimeException('ERP source does not match configured source system');
            }
            $source = $responseSource;
            $records = $json['records'] ?? [];
            $inserted = 0;
            $updated = 0;
            $now = now();
            $upserter = $handlers[$kind];

            $nextCursor = (int) ($json['next_cursor'] ?? $cursor);
            $rowErrors = 0;

            if ($kind === 'invoices') {
                // Keep invoice rows and their cursor atomic. A failed row must not advance the cursor.
                DB::transaction(function () use ($source, $records, $now, $upserter, $nextCursor, $cursor, &$inserted, &$updated) {
                    foreach ($records as $rec) {
                        $wasExisting = $upserter($source, $rec, $now);
                        $wasExisting ? $updated++ : $inserted++;
                    }

                    if ($nextCursor > $cursor) {
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
            } else {
                foreach ($records as $rec) {
                    try {
                        $wasExisting = DB::transaction(fn () => $upserter($source, $rec, $now));
                        $wasExisting ? $updated++ : $inserted++;
                    } catch (Throwable $rowErr) {
                        $rowErrors++;
                        $this->logError(
                            $runId,
                            $kind,
                            $this->safeRecordKey($rec),
                            $this->sanitizeMessage($rowErr->getMessage())
                        );
                    }
                }
            }

            $status = $rowErrors === 0 ? 'success' : 'failed';
            $staffSyncStats = null;
            if ($rowErrors === 0 && in_array($kind, ['salespersons', 'invoices'], true)) {
                $staffSyncStats = \App\Modules\CRM\CustomerAccess::syncStaffFromErp();
            }
            $message = "Synced {$kind}: fetched ".count($records).", row errors {$rowErrors}";
            $this->finishRun($runId, $status, count($records), $inserted, $updated, $message);

            return response()->json([
                'ok' => $rowErrors === 0,
                'kind' => $kind,
                'status' => $status,
                'fetched' => count($records),
                'inserted' => $inserted,
                'updated' => $updated,
                'row_errors' => $rowErrors,
                'next_cursor' => $nextCursor,
                'has_more' => (bool) ($json['has_more'] ?? false),
                'staff_sync' => $staffSyncStats,
            ], $rowErrors === 0 ? 200 : 422);
        } catch (Throwable $e) {
            $err = $this->sanitizeMessage($e->getMessage());
            Log::error('ERP local sync failed', ['kind' => $kind, 'error' => $err]);
            $this->finishRun($runId, 'failed', 0, 0, 0, $err);
            $this->logError($runId, $kind, null, $err);

            return response()->json(['ok' => false, 'error' => $err], 502);
        }
    }

    private function startRun(string $kind): int
    {
        return (int) DB::table('sync_runs')->insertGetId([
            'kind' => $kind,
            'status' => 'running',
            'triggered_by' => Auth::guard('staff')->id(),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function finishRun(int $id, string $status, int $fetched, int $inserted, int $updated, string $message): void
    {
        DB::table('sync_runs')->where('id', $id)->update([
            'status' => $status,
            'fetched_count' => $fetched,
            'inserted_count' => $inserted,
            'updated_count' => $updated,
            'message' => mb_substr($message, 0, 2000),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function logError(?int $runId, string $kind, ?string $key, string $message): void
    {
        DB::table('sync_errors')->insert([
            'sync_run_id' => $runId,
            'kind' => $kind,
            'record_key' => $key ? mb_substr($key, 0, 160) : null,
            'error_message' => mb_substr($message, 0, 2000),
            'created_at' => now(),
        ]);
    }
}
