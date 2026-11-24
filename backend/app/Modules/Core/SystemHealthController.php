<?php

namespace App\Modules\Core;

use App\Modules\CRM\CustomerAccess;
use App\Modules\ERPIntegration\SalesRules;
use App\Modules\LINE\LineConfig;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Dedicated live API & infrastructure health monitor for POPSTAR Business Platform v4.
 * Completely independent from any external/legacy servers.
 */
final class SystemHealthController
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('staff')->user();
        abort_unless(CustomerAccess::isManager($user), 403, 'Admin or manager role required');

        $tStart = microtime(true);
        $testSql = $request->boolean('test_sql');

        // 1. Database & Laravel API check
        $dbT0 = microtime(true);
        $dbOk = false;
        $dbError = null;
        try {
            DB::select('SELECT 1');
            $dbOk = true;
        } catch (Throwable $e) {
            $dbError = mb_substr($e->getMessage(), 0, 220);
        }
        $dbMs = round((microtime(true) - $dbT0) * 1000, 1);

        // 2. Local ERP Bridge check (and optional live Bplus SQL Server check)
        $bridgeBase = rtrim((string) config('popstar.local_bridge_url', 'http://127.0.0.1:18900'), '/');
        $bridgeToken = trim((string) config('popstar.local_bridge_token', ''));
        $bridgeHeaders = $bridgeToken !== '' ? ['X-Bridge-Token' => $bridgeToken] : [];

        $brT0 = microtime(true);
        $bridge = [
            'ok' => false,
            'url' => $bridgeBase,
            'sql_configured' => false,
            'driver' => null,
            'message' => 'Bridge offline',
            'latency_ms' => 0,
            'sql_test' => null,
        ];
        try {
            $hRes = Http::withHeaders($bridgeHeaders)->timeout(3)->get($bridgeBase.'/health');
            $bridge['latency_ms'] = round((microtime(true) - $brT0) * 1000, 1);
            if ($hRes->successful()) {
                $hj = $hRes->json() ?? [];
                $bridge['ok'] = (bool) ($hj['ok'] ?? true);
                $bridge['sql_configured'] = (bool) ($hj['sql_configured'] ?? false);
                $bridge['driver'] = $hj['driver'] ?? null;
                $bridge['message'] = $hj['message'] ?? 'Bridge online';
            } else {
                $bridge['message'] = 'Bridge HTTP '.$hRes->status();
            }
        } catch (Throwable $e) {
            $bridge['latency_ms'] = round((microtime(true) - $brT0) * 1000, 1);
            $bridge['message'] = 'ไม่สามารถเชื่อมต่อ Local ERP Bridge (127.0.0.1:18900)';
        }

        if ($testSql && $bridge['ok']) {
            $sqlT0 = microtime(true);
            try {
                $sRes = Http::withHeaders($bridgeHeaders)->timeout(10)->post($bridgeBase.'/test-connection');
                $sj = $sRes->json() ?? [];
                $bridge['sql_test'] = [
                    'ok' => $sRes->successful() && (bool) ($sj['ok'] ?? false),
                    'latency_ms' => round((microtime(true) - $sqlT0) * 1000, 1),
                    'database' => $sj['server_info']['database'] ?? null,
                    'server_name' => $sj['server_info']['server_name'] ?? null,
                    'message' => $sj['message'] ?? ($sj['error'] ?? ('HTTP '.$sRes->status())),
                ];
            } catch (Throwable $e) {
                $bridge['sql_test'] = [
                    'ok' => false,
                    'latency_ms' => round((microtime(true) - $sqlT0) * 1000, 1),
                    'message' => mb_substr($e->getMessage(), 0, 220),
                ];
            }
        }

        // 3. LINE OA Messaging API check
        $lineSecret = LineConfig::channelSecret();
        $lineToken = LineConfig::channelAccessToken();
        $lineRaw = LineConfig::allRaw();
        $line = [
            'configured' => $lineSecret !== '' && $lineToken !== '',
            'ok' => false,
            'display_name' => null,
            'basic_id' => $lineRaw['line_bot_basic_id'] ?? null,
            'channel_id' => $lineRaw['line_channel_id'] ?? null,
            'webhook_endpoint' => null,
            'webhook_active' => false,
            'quota_limit' => null,
            'quota_used' => null,
            'webhook_events_count' => DB::table('line_webhook_events')->count(),
            'chat_threads_count' => DB::table('line_chat_threads')->count(),
            'latency_ms' => null,
            'error' => null,
        ];
        if ($lineToken !== '' && ! app()->runningUnitTests()) {
            $remote = Cache::remember(
                'popstar:line_remote_status:'.sha1($lineToken),
                self::LINE_STATUS_CACHE_SECONDS,
                static fn (): array => self::fetchLineRemoteStatus($lineToken)
            );
            $remote['basic_id'] ??= $line['basic_id'];
            $line = array_merge($line, $remote);
        }

        // 4. Mirror Freshness & Counts (cached via fast erpCacheVersion)
        $mirror = SalesRules::cacheRemember('sys_health_mirror', 300, static function (): array {
            $invMeta = DB::table('erp_sales_invoices')->selectRaw('COUNT(*) as total, MIN(sold_at) as min_date, MAX(sold_at) as max_date')->first();

            return [
                'active_customers' => CustomerAccess::applyActiveCustomerFilter(DB::table('erp_customers'), 'erp_customers', false)->count(),
                'invoices' => (int) ($invMeta->total ?? 0),
                'invoice_min_date' => $invMeta->min_date ?? null,
                'invoice_max_date' => $invMeta->max_date ?? null,
                'sales_items' => DB::table('erp_sales_items')->count(),
                'product_skus' => DB::table('erp_product_prices')->distinct('erp_product_code')->count('erp_product_code'),
            ];
        });

        $crmCounts = [
            'staff_users' => DB::table('staff_users')->count(),
            'member_accounts' => DB::table('member_accounts')->count(),
            'verified_members' => DB::table('member_erp_links')->where('verification_status', 'verified')->count(),
            'active_loyalty_rules' => DB::table('loyalty_rules')->where('is_active', true)->count(),
            'active_rewards' => DB::table('loyalty_rewards')->where('is_active', true)->count(),
        ];

        $recentRuns = DB::table('sync_runs')->orderByDesc('id')->limit(8)->get();
        $recentErrors = DB::table('sync_errors')->orderByDesc('id')->limit(6)->get();
        $syncCursor = DB::table('erp_sync_cursors')->where('kind', 'invoices')->first();

        $overallOk = $dbOk && $bridge['ok'];

        return response()->json([
            'checked_at' => now()->toIso8601String(),
            'overall_status' => $overallOk ? 'healthy' : ($dbOk ? 'degraded' : 'down'),
            'total_latency_ms' => round((microtime(true) - $tStart) * 1000, 1),
            'api' => [
                'ok' => $dbOk,
                'db_driver' => DB::connection()->getDriverName(),
                'php_version' => PHP_VERSION,
                'db_latency_ms' => $dbMs,
                'error' => $dbError,
                'push_api_token_configured' => strlen(trim((string) config('popstar.sync_api_token', ''))) >= 32,
                'health_endpoint' => '/api/health',
            ],
            'bridge' => $bridge,
            'line' => $line,
            'mirror' => $mirror,
            'crm' => $crmCounts,
            'sync_cursor' => $syncCursor,
            'recent_runs' => $recentRuns,
            'recent_errors' => $recentErrors,
        ]);
    }

    /**
     * Seconds the LINE platform status is reused: the page auto-refreshes every 15 s and
     * four sequential LINE API calls made every load cost over a second.
     */
    private const LINE_STATUS_CACHE_SECONDS = 60;

    /**
     * Bot info, webhook and message quota from the LINE Messaging API.
     *
     * @return array{ok: bool, display_name: ?string, basic_id: ?string, webhook_endpoint: ?string, webhook_active: bool, quota_limit: mixed, quota_used: mixed, latency_ms: ?float, error: ?string}
     */
    private static function fetchLineRemoteStatus(string $lineToken): array
    {
        $status = [
            'ok' => false,
            'display_name' => null,
            'basic_id' => null,
            'webhook_endpoint' => null,
            'webhook_active' => false,
            'quota_limit' => null,
            'quota_used' => null,
            'latency_ms' => null,
            'error' => null,
        ];
        $startedAt = microtime(true);
        try {
            $infoRes = Http::withToken($lineToken)->acceptJson()->timeout(5)->get('https://api.line.me/v2/bot/info');
            $status['latency_ms'] = round((microtime(true) - $startedAt) * 1000, 1);
            if (! $infoRes->successful()) {
                $status['error'] = 'LINE API HTTP '.$infoRes->status();

                return $status;
            }
            $info = $infoRes->json() ?? [];
            $status['ok'] = true;
            $status['display_name'] = $info['displayName'] ?? null;
            $status['basic_id'] = $info['basicId'] ?? null;

            $responses = Http::pool(static fn (Pool $pool): array => [
                $pool->as('webhook')->withToken($lineToken)->acceptJson()->timeout(4)->get('https://api.line.me/v2/bot/channel/webhook/endpoint'),
                $pool->as('quota')->withToken($lineToken)->acceptJson()->timeout(4)->get('https://api.line.me/v2/bot/message/quota'),
                $pool->as('usage')->withToken($lineToken)->acceptJson()->timeout(4)->get('https://api.line.me/v2/bot/message/quota/consumption'),
            ]);
            if ($responses['webhook'] instanceof Response && $responses['webhook']->successful()) {
                $status['webhook_endpoint'] = $responses['webhook']->json('endpoint');
                $status['webhook_active'] = (bool) $responses['webhook']->json('active', false);
            }
            if ($responses['quota'] instanceof Response && $responses['quota']->successful()) {
                $status['quota_limit'] = $responses['quota']->json('value');
            }
            if ($responses['usage'] instanceof Response && $responses['usage']->successful()) {
                $status['quota_used'] = $responses['usage']->json('totalUsage');
            }
        } catch (Throwable $e) {
            $status['error'] = mb_substr($e->getMessage(), 0, 200);
        }

        return $status;
    }
}
