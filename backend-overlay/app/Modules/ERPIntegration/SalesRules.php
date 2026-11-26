<?php

namespace App\Modules\ERPIntegration;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function Illuminate\Support\defer;

/**
 * Centralizes Bplus ERP sales calculation, document classification,
 * credit-note reversal, reservation separation, and service/packaging KPI exclusion rules.
 */
final class SalesRules
{
    public const EXCLUDED_ICCAT_CODES = ['CC', 'CO', 'AS', 'PK'];

    public const ACTUAL_SALE_PROPERTIES = [302, 307, 337];

    public const RETURN_PROPERTIES = [308];

    public const RESERVATION_PROPERTIES = [207];

    public const ACTUAL_DOCUMENT_PROPERTIES = [302, 307, 308, 337];

    private static ?string $requestErpVersion = null;

    /**
     * Fast (<0.2ms) cache version key derived from PK max IDs + explicit invalidation counter.
     * Automatically detects external PostgreSQL inserts (e.g. CLI sync scripts) on the next request.
     */
    public static function erpCacheVersion(): string
    {
        if (self::$requestErpVersion !== null) {
            return self::$requestErpVersion;
        }
        $bump = (int) Cache::get('popstar:erp_cache_ver', 1);
        $maxInv = (int) (DB::table('erp_sales_invoices')->max('id') ?? 0);
        $maxCust = (int) (DB::table('erp_customers')->max('id') ?? 0);

        return self::$requestErpVersion = "{$bump}:{$maxInv}:{$maxCust}";
    }

    public static function invalidateCache(): void
    {
        self::$requestErpVersion = null;
        if (! app()->runningUnitTests()) {
            $next = ((int) Cache::get('popstar:erp_cache_ver', 1)) + 1;
            Cache::forever('popstar:erp_cache_ver', $next);
        }
    }

    /**
     * Longest time a stale analytics result may still be served while it is refreshed.
     */
    private const STALE_SERVE_MAX_SECONDS = 86400;

    /**
     * Cache heavy read-only ERP mirror aggregations (automatically bypassed during unit tests).
     *
     * Uses stale-while-revalidate: every ERP sync batch bumps the version, but the
     * previous result keeps being served immediately while a single locked refresh
     * recomputes it after the response. This avoids every user paying (and
     * concurrently repeating) the full aggregation after each 15-minute sync push.
     * Pass $allowStale = false for data that drives access control.
     */
    public static function cacheRemember(string $key, int $ttlSeconds, callable $compute, bool $allowStale = true): mixed
    {
        if (app()->runningUnitTests()) {
            return $compute();
        }
        $fullKey = 'popstar:v5:'.$key;
        $version = self::erpCacheVersion();
        $entry = Cache::get($fullKey);

        if (is_array($entry) && array_key_exists('data', $entry)) {
            $age = time() - (int) ($entry['at'] ?? 0);
            if (($entry['version'] ?? null) === $version && $age < $ttlSeconds) {
                return $entry['data'];
            }
            if ($allowStale && $age < self::STALE_SERVE_MAX_SECONDS) {
                defer(static function () use ($fullKey, $ttlSeconds, $compute): void {
                    Cache::lock($fullKey.':refresh', 600)->get(static function () use ($fullKey, $ttlSeconds, $compute): void {
                        self::$requestErpVersion = null;
                        $current = Cache::get($fullKey);
                        $version = self::erpCacheVersion();
                        if (is_array($current) && ($current['version'] ?? null) === $version && time() - (int) ($current['at'] ?? 0) < $ttlSeconds) {
                            return;
                        }
                        self::storeCacheEntry($fullKey, $version, $compute());
                    });
                }, 'popstar-cache-refresh:'.$fullKey);

                return $entry['data'];
            }
        }

        $data = $compute();
        self::storeCacheEntry($fullKey, $version, $data);

        return $data;
    }

    private static function storeCacheEntry(string $fullKey, string $version, mixed $data): void
    {
        Cache::put($fullKey, ['version' => $version, 'at' => time(), 'data' => $data], self::STALE_SERVE_MAX_SECONDS);
    }

    /**
     * Determine whether an ICCAT category belongs to non-core service/packaging items.
     */
    public static function isExcludedCategory(?string $iccatCode): bool
    {
        if ($iccatCode === null) {
            return false;
        }

        return in_array(strtoupper(trim($iccatCode)), self::EXCLUDED_ICCAT_CODES, true);
    }

    /**
     * Classify a Bplus document by DT_PROPERTIES / DI_ACTIVE / doc_category into canonical category and status.
     *
     * @return array{dt_properties: ?int, doc_category: string, status: string}
     */
    public static function classifyDocument(
        mixed $dtProperties = null,
        ?string $docType = null,
        mixed $rawStatus = null,
        ?string $explicitCategory = null
    ): array {
        $prop = is_numeric($dtProperties) ? (int) $dtProperties : null;
        $statusNorm = is_string($rawStatus) ? strtolower(trim($rawStatus)) : '';
        // DT_PROPERTIES is authoritative: never infer a sale from a missing or
        // unrecognized code, even if an upstream caller supplies a category.
        $cat = match (true) {
            $prop === 207 => 'reservation',
            $prop === 308 => 'return',
            in_array($prop, self::ACTUAL_SALE_PROPERTIES, true) => 'sale',
            default => 'other',
        };

        if ($statusNorm === 'cancelled') {
            $status = 'cancelled';
        } elseif ($cat === 'reservation' && $statusNorm === 'reserved') {
            $status = 'reserved';
        } elseif ($cat === 'return' && $statusNorm === 'returned') {
            $status = 'returned';
        } elseif ($cat === 'sale' && $statusNorm === 'posted') {
            $status = 'posted';
        } else {
            $status = 'other';
        }

        return [
            'dt_properties' => $prop,
            'doc_category' => $cat,
            'status' => $status,
        ];
    }

    /**
     * Normalize pre-VAT, VAT, and gross amounts preserving negative signs on returns (308).
     *
     * @param  array<string, mixed>  $row
     * @return array{before_vat_amount: float, vat_amount: float, gross_amount: float, net_amount: float}
     */
    public static function normalizeAmounts(?int $dtProperties, string $docCategory, array $row): array
    {
        $isReturn = $dtProperties === 308 || $docCategory === 'return';

        $hasBeforeVat = array_key_exists('before_vat_amount', $row) && $row['before_vat_amount'] !== null && $row['before_vat_amount'] !== '';
        $hasVat = array_key_exists('vat_amount', $row) && $row['vat_amount'] !== null && $row['vat_amount'] !== '';
        $hasGross = array_key_exists('gross_amount', $row) && $row['gross_amount'] !== null && $row['gross_amount'] !== '';
        $hasNet = array_key_exists('net_amount', $row) && $row['net_amount'] !== null && $row['net_amount'] !== '';

        $vat = $hasVat ? (float) $row['vat_amount'] : 0.0;
        if ($hasBeforeVat) {
            $beforeVat = (float) $row['before_vat_amount'];
            $gross = $hasGross ? (float) $row['gross_amount'] : ($beforeVat + $vat);
        } elseif ($hasGross) {
            $gross = (float) $row['gross_amount'];
            $beforeVat = $hasVat ? ($gross - $vat) : ($hasNet ? (float) $row['net_amount'] : $gross);
        } elseif ($hasNet) {
            $beforeVat = (float) $row['net_amount'];
            $gross = $beforeVat + $vat;
        } else {
            $beforeVat = 0.0;
            $gross = 0.0;
        }

        if ($isReturn) {
            $beforeVat = $beforeVat > 0 ? -$beforeVat : $beforeVat;
            $vat = $vat > 0 ? -$vat : $vat;
            $gross = $gross > 0 ? -$gross : $gross;
        }

        return [
            'before_vat_amount' => round($beforeVat, 2),
            'vat_amount' => round($vat, 2),
            'gross_amount' => round($gross, 2),
            'net_amount' => round($beforeVat, 2),
        ];
    }

    /**
     * Restrict an erp_sales_invoices query to active actual sales and returns,
     * never reservations (207) or cancelled documents.
     */
    public static function applyActualSalesScope(Builder $query, string $alias = 'erp_sales_invoices'): Builder
    {
        return $query
            ->where("{$alias}.sold_at", '>=', '2026-01-01 00:00:00')
            ->whereIn("{$alias}.doc_category", ['sale', 'return'])
            ->whereIn("{$alias}.status", ['posted', 'returned'])
            ->whereIn("{$alias}.dt_properties", self::ACTUAL_DOCUMENT_PROPERTIES);
    }

    /**
     * Portable case-insensitive LIKE operator for PostgreSQL and SQLite tests.
     */
    public static function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    /**
     * Aggregated actual-sales header metrics per (source_system, erp_customer_code).
     *
     * @param  list<string>|null  $customerCodes  Optional subset of erp_customer_code values to avoid full-table grouping.
     */
    public static function customerActualInvoiceSubquery(?array $customerCodes = null): Builder
    {
        $q = DB::table('erp_sales_invoices as inv')
            ->groupBy('inv.source_system', 'inv.erp_customer_code')
            ->select(
                'inv.source_system',
                'inv.erp_customer_code',
                DB::raw('COUNT(*) as invoice_count'),
                DB::raw("SUM(CASE WHEN inv.doc_category = 'sale' THEN 1 ELSE 0 END) as sale_invoice_count"),
                DB::raw("SUM(CASE WHEN inv.doc_category = 'return' THEN 1 ELSE 0 END) as return_count"),
                DB::raw("COALESCE(SUM(CASE WHEN inv.doc_category = 'sale' THEN COALESCE(inv.before_vat_amount, inv.net_amount) ELSE 0 END), 0) as gross_purchase_before_vat"),
                DB::raw("COALESCE(SUM(CASE WHEN inv.doc_category = 'return' THEN COALESCE(inv.before_vat_amount, inv.net_amount) ELSE 0 END), 0) as return_before_vat"),
                DB::raw('COALESCE(SUM(COALESCE(inv.before_vat_amount, inv.net_amount)), 0) as actual_before_vat'),
                DB::raw("COALESCE(SUM(CASE WHEN inv.doc_category = 'sale' THEN COALESCE(inv.gross_amount, inv.net_amount) ELSE 0 END), 0) as gross_purchase_gross"),
                DB::raw("COALESCE(SUM(CASE WHEN inv.doc_category = 'return' THEN COALESCE(inv.gross_amount, inv.net_amount) ELSE 0 END), 0) as return_gross"),
                DB::raw('COALESCE(SUM(COALESCE(inv.gross_amount, inv.net_amount)), 0) as actual_gross'),
                DB::raw("MAX(CASE WHEN inv.doc_category = 'sale' THEN inv.sold_at ELSE NULL END) as last_purchase_at")
            );

        if ($customerCodes !== null) {
            if ($customerCodes === []) {
                $q->whereRaw('1 = 0');
            } else {
                $q->whereIn('inv.erp_customer_code', $customerCodes);
            }
        }

        return self::applyActualSalesScope($q, 'inv');
    }

    /**
     * Aggregated excluded service/packaging item amounts (CC, CO, AS, PK) per customer on actual invoices.
     *
     * @param  list<string>|null  $customerCodes  Optional subset of erp_customer_code values to avoid full-table grouping.
     */
    public static function customerExcludedItemsSubquery(?array $customerCodes = null): Builder
    {
        $q = DB::table('erp_sales_items as it')
            ->join('erp_sales_invoices as inv', function ($join): void {
                $join->on('inv.source_system', '=', 'it.source_system')
                    ->on('inv.branch_code', '=', 'it.branch_code')
                    ->on('inv.doc_type', '=', 'it.doc_type')
                    ->on('inv.invoice_no', '=', 'it.invoice_no');
            })
            ->where('it.is_excluded_kpi', true)
            ->groupBy('inv.source_system', 'inv.erp_customer_code')
            ->select(
                'inv.source_system',
                'inv.erp_customer_code',
                DB::raw('COALESCE(SUM(COALESCE(it.before_vat_amount, it.net_amount)), 0) as excluded_before_vat')
            );

        if ($customerCodes !== null) {
            if ($customerCodes === []) {
                $q->whereRaw('1 = 0');
            } else {
                $q->whereIn('inv.erp_customer_code', $customerCodes);
            }
        }

        return self::applyActualSalesScope($q, 'inv');
    }

    /**
     * Excluded item amounts keyed "source_system|erp_customer_code" for the given customers.
     *
     * Reads one cached map of every customer, refreshed after each ERP sync: computing it per
     * page cost ~200 ms because the largest customers have thousands of invoices to probe.
     *
     * @param  list<string>  $customerCodes
     * @return array<string, float>
     */
    public static function customerExcludedTotals(array $customerCodes): array
    {
        if ($customerCodes === []) {
            return [];
        }

        $all = self::cacheRemember('customer_excluded_totals', 3600, static fn (): array => self::customerExcludedItemsSubquery()
            ->get()
            ->mapWithKeys(static fn (object $row): array => [
                $row->source_system.'|'.$row->erp_customer_code => (float) ($row->excluded_before_vat ?? 0),
            ])
            ->all());
        $wanted = array_flip($customerCodes);

        return array_filter(
            $all,
            static fn (string $key): bool => isset($wanted[substr($key, (int) strpos($key, '|') + 1)]),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Compute aggregate KPI breakdown using Before VAT as primary KPI,
     * separated VAT/Gross verification totals, reservation totals (207),
     * cancelled document counts, and excluded service/packaging deductions.
     *
     * @return array<string, float|int>
     */
    public static function summarizeInvoices(?callable $filterCallback = null): array
    {
        $propsList = implode(', ', self::ACTUAL_DOCUMENT_PROPERTIES);
        $actualCond = "inv.sold_at >= '2026-01-01 00:00:00' AND inv.doc_category IN ('sale', 'return') AND inv.status IN ('posted', 'returned') AND inv.dt_properties IN ({$propsList})";
        $returnCond = "inv.doc_category = 'return' AND inv.status = 'returned'";
        $resCond = "(inv.doc_category = 'reservation' OR inv.dt_properties = 207) AND inv.status = 'reserved'";

        // Single-pass conditional aggregation over erp_sales_invoices (replaces 4 separate full-table scans)
        $headerQuery = DB::table('erp_sales_invoices as inv');
        if ($filterCallback) {
            $filterCallback($headerQuery, 'inv');
        }
        $agg = $headerQuery->selectRaw("
            COALESCE(SUM(CASE WHEN {$actualCond} THEN 1 ELSE 0 END), 0) as invoice_count,
            COALESCE(SUM(CASE WHEN {$actualCond} AND inv.doc_category = 'sale' THEN 1 ELSE 0 END), 0) as sale_invoice_count,
            COALESCE(SUM(CASE WHEN {$actualCond} AND inv.doc_category = 'sale' THEN COALESCE(inv.before_vat_amount, inv.net_amount) ELSE 0 END), 0) as gross_purchase_before_vat,
            COALESCE(SUM(CASE WHEN {$actualCond} AND inv.doc_category = 'sale' THEN COALESCE(inv.gross_amount, inv.net_amount) ELSE 0 END), 0) as gross_purchase_gross,
            COALESCE(SUM(CASE WHEN {$actualCond} THEN COALESCE(inv.before_vat_amount, inv.net_amount) ELSE 0 END), 0) as total_before_vat,
            COALESCE(SUM(CASE WHEN {$actualCond} THEN COALESCE(inv.vat_amount, 0) ELSE 0 END), 0) as total_vat,
            COALESCE(SUM(CASE WHEN {$actualCond} THEN COALESCE(inv.gross_amount, inv.net_amount) ELSE 0 END), 0) as total_gross,
            MAX(CASE WHEN {$actualCond} AND inv.doc_category = 'sale' THEN inv.sold_at ELSE NULL END) as last_purchase_at,
            COALESCE(SUM(CASE WHEN {$returnCond} THEN 1 ELSE 0 END), 0) as return_count,
            COALESCE(SUM(CASE WHEN {$returnCond} THEN COALESCE(inv.before_vat_amount, inv.net_amount) ELSE 0 END), 0) as return_before_vat,
            COALESCE(SUM(CASE WHEN {$returnCond} THEN COALESCE(inv.gross_amount, inv.net_amount) ELSE 0 END), 0) as return_gross,
            COALESCE(SUM(CASE WHEN {$resCond} THEN 1 ELSE 0 END), 0) as reservation_count,
            COALESCE(SUM(CASE WHEN {$resCond} THEN COALESCE(inv.before_vat_amount, inv.net_amount) ELSE 0 END), 0) as reservation_before_vat,
            COALESCE(SUM(CASE WHEN {$resCond} THEN COALESCE(inv.gross_amount, inv.net_amount) ELSE 0 END), 0) as reservation_gross,
            COALESCE(SUM(CASE WHEN inv.status = 'cancelled' THEN 1 ELSE 0 END), 0) as cancelled_count
        ")->first();

        $excludedQuery = DB::table('erp_sales_items as it')
            ->join('erp_sales_invoices as inv', function ($join): void {
                $join->on('inv.source_system', '=', 'it.source_system')
                    ->on('inv.branch_code', '=', 'it.branch_code')
                    ->on('inv.doc_type', '=', 'it.doc_type')
                    ->on('inv.invoice_no', '=', 'it.invoice_no');
            })
            ->where('it.is_excluded_kpi', true);
        self::applyActualSalesScope($excludedQuery, 'inv');
        if ($filterCallback) {
            $filterCallback($excludedQuery, 'inv');
        }
        $excludedRow = $excludedQuery->selectRaw('
            COALESCE(SUM(COALESCE(it.before_vat_amount, it.net_amount)), 0) as excluded_before_vat,
            COALESCE(SUM(COALESCE(it.vat_amount, 0)), 0) as excluded_vat,
            COALESCE(SUM(COALESCE(it.gross_amount, it.net_amount)), 0) as excluded_gross
        ')->first();

        $totalBeforeVat = round((float) ($agg->total_before_vat ?? 0), 2);
        $totalVat = round((float) ($agg->total_vat ?? 0), 2);
        $totalGross = round((float) ($agg->total_gross ?? 0), 2);
        $excludedBeforeVat = round((float) ($excludedRow->excluded_before_vat ?? 0), 2);
        $productKpiBeforeVat = round($totalBeforeVat - $excludedBeforeVat, 2);

        return [
            'invoice_count' => (int) ($agg->invoice_count ?? 0),
            'sale_invoice_count' => (int) ($agg->sale_invoice_count ?? 0),
            'posted_sales_count' => (int) ($agg->sale_invoice_count ?? 0),
            'primary_sales_before_vat' => $productKpiBeforeVat,
            'gross_purchase_before_vat' => round((float) ($agg->gross_purchase_before_vat ?? 0), 2),
            'return_amount_before_vat' => round(abs((float) ($agg->return_before_vat ?? 0)), 2),
            'net_purchase_before_vat' => $totalBeforeVat,
            // Stable aliases consumed by dashboard/reconciliation views.
            // These are actual sales minus active returns, including all item categories.
            'net_sales_before_vat' => $totalBeforeVat,
            'gross_purchase_gross' => round((float) ($agg->gross_purchase_gross ?? 0), 2),
            'return_amount_gross' => round(abs((float) ($agg->return_gross ?? 0)), 2),
            'net_purchase_gross' => $totalGross,
            'vat_amount' => $totalVat,
            'gross_amount' => $totalGross,
            'last_purchase_at' => $agg->last_purchase_at ?? null,
            'actual_all_before_vat' => $totalBeforeVat,
            'excluded_service_before_vat' => $excludedBeforeVat,
            'vat_total' => $totalVat,
            'gross_total' => $totalGross,
            'return_count' => (int) ($agg->return_count ?? 0),
            'return_before_vat' => round((float) ($agg->return_before_vat ?? 0), 2),
            'return_308_before_vat' => round(abs((float) ($agg->return_before_vat ?? 0)), 2),
            'reservation_count' => (int) ($agg->reservation_count ?? 0),
            'reservation_before_vat' => round((float) ($agg->reservation_before_vat ?? 0), 2),
            'reservation_gross' => round((float) ($agg->reservation_gross ?? 0), 2),
            'reservation_207_count' => (int) ($agg->reservation_count ?? 0),
            'reservation_207_before_vat' => round((float) ($agg->reservation_before_vat ?? 0), 2),
            'cancelled_count' => (int) ($agg->cancelled_count ?? 0),
        ];
    }

    /**
     * Build portable monthly sales breakdown applying all B3 rules (before-VAT primary KPI, VAT, gross, excluded deducted).
     *
     * @return list<array<string, mixed>>
     */
    public static function monthlySalesSeries(?callable $filterCallback = null): array
    {
        $invQ = self::applyActualSalesScope(DB::table('erp_sales_invoices as inv'), 'inv');
        if ($filterCallback) {
            $filterCallback($invQ, 'inv');
        }
        $monthExpr = 'SUBSTR(CAST(inv.sold_at AS TEXT), 1, 7)';
        $invoices = $invQ->selectRaw("{$monthExpr} as month")
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw("SUM(CASE WHEN inv.doc_category = 'sale' THEN 1 ELSE 0 END) as sale_invoice_count")
            ->selectRaw("SUM(CASE WHEN inv.doc_category = 'return' THEN 1 ELSE 0 END) as return_count")
            ->selectRaw('COALESCE(SUM(COALESCE(inv.before_vat_amount, inv.net_amount)), 0) as before_vat')
            ->selectRaw('COALESCE(SUM(COALESCE(inv.vat_amount, 0)), 0) as vat')
            ->selectRaw('COALESCE(SUM(COALESCE(inv.gross_amount, inv.net_amount)), 0) as gross')
            ->selectRaw("COALESCE(SUM(CASE WHEN inv.doc_category = 'return' THEN COALESCE(inv.before_vat_amount, inv.net_amount) ELSE 0 END), 0) as return_before_vat")
            ->groupByRaw($monthExpr)->orderBy('month')->get();

        $excQ = DB::table('erp_sales_items as it')
            ->join('erp_sales_invoices as inv', function ($join): void {
                $join->on('inv.source_system', '=', 'it.source_system')
                    ->on('inv.branch_code', '=', 'it.branch_code')
                    ->on('inv.doc_type', '=', 'it.doc_type')
                    ->on('inv.invoice_no', '=', 'it.invoice_no');
            })
            ->where('it.is_excluded_kpi', true);
        self::applyActualSalesScope($excQ, 'inv');
        if ($filterCallback) {
            $filterCallback($excQ, 'inv');
        }
        $excludedItems = $excQ->selectRaw("{$monthExpr} as month")
            ->selectRaw('COALESCE(SUM(COALESCE(it.before_vat_amount, it.net_amount)), 0) as before_vat')
            ->groupByRaw($monthExpr)->get();

        $buckets = [];
        foreach ($invoices as $row) {
            $month = (string) $row->month;
            if ($month === '') {
                continue;
            }
            $buckets[$month] ??= [
                'month' => $month,
                'invoice_count' => 0,
                'sale_invoice_count' => 0,
                'return_count' => 0,
                'net_sales_before_vat' => 0.0,
                'primary_sales_before_vat' => 0.0,
                'excluded_service_before_vat' => 0.0,
                'vat_total' => 0.0,
                'gross_total' => 0.0,
                'return_before_vat' => 0.0,
            ];
            $before = (float) $row->before_vat;
            $buckets[$month]['invoice_count'] += (int) $row->invoice_count;
            $buckets[$month]['net_sales_before_vat'] += $before;
            $buckets[$month]['primary_sales_before_vat'] += $before;
            $buckets[$month]['vat_total'] += (float) $row->vat;
            $buckets[$month]['gross_total'] += (float) $row->gross;
            $buckets[$month]['return_count'] += (int) $row->return_count;
            $buckets[$month]['sale_invoice_count'] += (int) $row->sale_invoice_count;
            $buckets[$month]['return_before_vat'] += (float) $row->return_before_vat;
        }

        foreach ($excludedItems as $ex) {
            $month = (string) $ex->month;
            if ($month === '' || ! isset($buckets[$month])) {
                continue;
            }
            $exVal = (float) $ex->before_vat;
            $buckets[$month]['excluded_service_before_vat'] += $exVal;
            $buckets[$month]['primary_sales_before_vat'] -= $exVal;
        }

        ksort($buckets);

        return array_values(array_map(static fn (array $b): array => [
            'month' => $b['month'],
            'invoice_count' => $b['invoice_count'],
            'sale_invoice_count' => $b['sale_invoice_count'],
            'return_count' => $b['return_count'],
            'net_sales_before_vat' => round($b['net_sales_before_vat'], 2),
            'primary_sales_before_vat' => round($b['primary_sales_before_vat'], 2),
            'excluded_service_before_vat' => round($b['excluded_service_before_vat'], 2),
            'vat_total' => round($b['vat_total'], 2),
            'gross_total' => round($b['gross_total'], 2),
            'return_before_vat' => round($b['return_before_vat'], 2),
        ], $buckets));
    }
}
