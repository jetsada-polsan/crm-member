<?php

namespace App\Modules\ERPIntegration;

use App\Modules\CRM\CustomerAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Pulls ERP data from the local Windows bridge (SELECT-only Bplus SQL) into the mirror tables.
 *
 * Shared by the Sync Monitor buttons (one page per request) and the scheduled
 * `popstar:sync-erp` command (pages until the bridge reports no more rows).
 */
final class LocalBridgeSync
{
    public const DATA_KINDS = ['customers', 'products', 'salespersons', 'invoices', 'members'];

    private const UPSERTERS = [
        'customers' => [ErpMirrorUpserter::class, 'upsertCustomer'],
        'products' => [ErpMirrorUpserter::class, 'upsertProduct'],
        'invoices' => [ErpMirrorUpserter::class, 'upsertInvoiceWithItems'],
        'salespersons' => [ErpMirrorUpserter::class, 'upsertSalesperson'],
        'members' => [ErpMirrorUpserter::class, 'upsertMember'],
    ];

    public function bridgeUrl(string $path): string
    {
        return rtrim((string) config('popstar.local_bridge_url', 'http://host.docker.internal:18900'), '/').$path;
    }

    /**
     * @return array<string, string>
     */
    public function bridgeHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Host' => '127.0.0.1:18900',
            'X-Bridge-Token' => (string) config('popstar.local_bridge_token', ''),
        ];
    }

    public function sanitizeMessage(string $message): string
    {
        $message = (string) preg_replace('/\s*,?\s*SQL:\s*.+$/is', ' SQL: [statement redacted]', $message);
        $message = (string) preg_replace('/(Password|Pwd)\s*=\s*[^;]+/i', '$1=***', $message);

        if (stripos($message, 'The wait operation timed out') !== false
            || stripos($message, 'server was not found or was not accessible') !== false) {
            return 'เชื่อมต่อ Bplus SQL Server ไม่ได้ (Timeout) — เครื่องนี้ไม่ได้อยู่ในวงแลนออฟฟิศ กรุณาใช้โหมด Push API แทน';
        }

        return $message;
    }

    /**
     * Fast TCP probe of the office SQL Server, so a scheduled run can skip cleanly when the VPN is down.
     */
    public function isOfficeSqlReachable(float $timeoutSeconds = 1.0): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen(
            (string) config('popstar.office_sql_host', '192.168.88.200'),
            (int) config('popstar.office_sql_port', 1433),
            $errno,
            $errstr,
            $timeoutSeconds,
        );
        if (is_resource($socket)) {
            fclose($socket);

            return true;
        }

        return false;
    }

    /**
     * Sync one page of a kind from the bridge.
     *
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function syncPage(string $kind, ?int $limit = null, ?int $afterKey = null, bool $fullReconcile = false, ?int $triggeredBy = null): array
    {
        if (! isset(self::UPSERTERS[$kind])) {
            return ['http_status' => 422, 'body' => ['error' => 'Unsupported sync kind']];
        }

        $defaultLimit = $kind === 'invoices' ? 100 : 1000;
        $limit = max(1, min($kind === 'invoices' ? 500 : 5000, $limit ?? $defaultLimit));
        if ($kind === 'invoices') {
            $limit = max(100, $limit);
        }
        $runId = $this->startRun($kind, $triggeredBy);

        try {
            $source = (string) config('popstar.erp_source_system', 'BPLUS');
            $cursorRow = $kind === 'invoices'
                ? DB::table('erp_sync_cursors')->where('source_system', $source)->where('kind', 'invoices')->first()
                : null;
            $cursor = (int) ($cursorRow->cursor_value ?? 0);
            $defaultAfterKey = $kind === 'invoices'
                ? ($fullReconcile ? 0 : max(0, $cursor - 50))
                : 0;
            $afterKey = $afterKey === null ? $defaultAfterKey : max(0, $afterKey);
            $query = ['limit' => $limit, 'after_key' => $afterKey];

            $res = Http::withHeaders($this->bridgeHeaders())->timeout(60)->get($this->bridgeUrl("/export/{$kind}"), $query);
            $json = $res->json() ?? [];
            if (! $res->successful() || ! ($json['ok'] ?? false)) {
                $msg = $this->sanitizeMessage((string) ($json['error'] ?? $json['detail'] ?? ('HTTP '.$res->status())));
                $this->finishRun($runId, 'failed', 0, 0, 0, $msg);
                $this->logError($runId, $kind, null, $msg);

                return ['http_status' => 422, 'body' => ['ok' => false, 'error' => $msg]];
            }

            $responseSource = (string) ($json['source'] ?? '');
            if ($responseSource === '' || ($kind === 'invoices' && $responseSource !== $source)) {
                throw new RuntimeException('ERP source does not match configured source system');
            }
            $source = $responseSource;
            $records = $json['records'] ?? [];
            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $now = now();
            $upserter = self::UPSERTERS[$kind];

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
                        if ($wasExisting === null) {
                            $skipped++;
                        } else {
                            $wasExisting ? $updated++ : $inserted++;
                        }
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
                $staffSyncStats = CustomerAccess::syncStaffFromErp();
            }
            $message = "Synced {$kind}: fetched ".count($records).", row errors {$rowErrors}";
            $this->finishRun($runId, $status, count($records), $inserted, $updated, $message);

            return [
                'http_status' => $rowErrors === 0 ? 200 : 422,
                'body' => [
                    'ok' => $rowErrors === 0,
                    'kind' => $kind,
                    'status' => $status,
                    'fetched' => count($records),
                    'inserted' => $inserted,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'row_errors' => $rowErrors,
                    'next_cursor' => $nextCursor,
                    'has_more' => (bool) ($json['has_more'] ?? false),
                    'staff_sync' => $staffSyncStats,
                ],
            ];
        } catch (Throwable $e) {
            $err = $this->sanitizeMessage($e->getMessage());
            Log::error('ERP local sync failed', ['kind' => $kind, 'error' => $err]);
            $this->finishRun($runId, 'failed', 0, 0, 0, $err);
            $this->logError($runId, $kind, null, $err);

            return ['http_status' => 502, 'body' => ['ok' => false, 'error' => $err]];
        }
    }

    /**
     * Sync every page of a kind, following the bridge cursor until it reports no more rows.
     *
     * @param  callable(array<string, mixed>, int): void|null  $onPage
     * @return array{ok: bool, pages: int, fetched: int, inserted: int, updated: int, skipped: int, error: ?string}
     */
    public function syncAll(string $kind, int $maxPages = 50, ?int $limit = null, ?callable $onPage = null): array
    {
        $totals = ['ok' => true, 'pages' => 0, 'fetched' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'error' => null];
        $afterKey = null;

        while ($totals['pages'] < $maxPages) {
            $body = $this->syncPage($kind, $limit, $afterKey)['body'];
            $totals['pages']++;

            if (($body['ok'] ?? false) !== true) {
                $totals['ok'] = false;
                $totals['error'] = (string) ($body['error'] ?? ('row errors '.($body['row_errors'] ?? 0)));

                return $totals;
            }

            foreach (['fetched', 'inserted', 'updated', 'skipped'] as $field) {
                $totals[$field] += (int) ($body[$field] ?? 0);
            }
            if ($onPage !== null) {
                $onPage($body, $totals['pages']);
            }

            if (! ($body['has_more'] ?? false)) {
                return $totals;
            }

            $nextCursor = (int) ($body['next_cursor'] ?? 0);
            if ($nextCursor <= ($afterKey ?? 0)) {
                $totals['ok'] = false;
                $totals['error'] = 'Bridge cursor did not advance; stopped to avoid re-reading the same rows.';

                return $totals;
            }
            $afterKey = $nextCursor;
        }

        return $totals;
    }

    public function startRun(string $kind, ?int $triggeredBy = null): int
    {
        return (int) DB::table('sync_runs')->insertGetId([
            'kind' => $kind,
            'status' => 'running',
            'triggered_by' => $triggeredBy,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function finishRun(int $id, string $status, int $fetched, int $inserted, int $updated, string $message): void
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
        if ($inserted > 0 || $updated > 0) {
            SalesRules::invalidateCache();
            CustomerAccess::clearCache();
        }
    }

    public function logError(?int $runId, string $kind, ?string $key, string $message): void
    {
        DB::table('sync_errors')->insert([
            'sync_run_id' => $runId,
            'kind' => $kind,
            'record_key' => $key ? mb_substr($key, 0, 160) : null,
            'error_message' => mb_substr($message, 0, 2000),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
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
}
