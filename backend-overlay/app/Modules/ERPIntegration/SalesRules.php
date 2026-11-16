<?php

namespace App\Modules\ERPIntegration;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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
        $cat = is_string($explicitCategory) && trim($explicitCategory) !== ''
            ? strtolower(trim($explicitCategory))
            : null;
        $statusNorm = is_string($rawStatus) ? strtolower(trim($rawStatus)) : '';

        if ($cat === null) {
            if ($prop === 207) {
                $cat = 'reservation';
            } elseif ($prop === 308 || $statusNorm === 'returned') {
                $cat = 'return';
            } elseif ($prop === null || in_array($prop, self::ACTUAL_SALE_PROPERTIES, true)) {
                $cat = 'sale';
            } else {
                $cat = 'other';
            }
        }

        if ($statusNorm === 'cancelled' || (is_numeric($rawStatus) && (int) $rawStatus !== 0)) {
            $status = 'cancelled';
        } elseif ($cat === 'reservation' || $prop === 207) {
            $cat = 'reservation';
            $status = 'reserved';
        } elseif ($cat === 'return' || $prop === 308) {
            $cat = 'return';
            $status = 'returned';
        } elseif ($cat === 'sale') {
            $status = 'posted';
        } else {
            $status = $statusNorm !== '' ? $statusNorm : 'other';
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
            ->whereIn("{$alias}.doc_category", ['sale', 'return'])
            ->whereIn("{$alias}.status", ['posted', 'returned'])
            ->where(function (Builder $q) use ($alias): void {
                $q->whereNull("{$alias}.dt_properties")
                    ->orWhere("{$alias}.dt_properties", '!=', 207);
            });
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
     */
    public static function customerActualInvoiceSubquery(): Builder
    {
        $q = DB::table('erp_sales_invoices as inv')
            ->groupBy('inv.source_system', 'inv.erp_customer_code')
            ->select(
                'inv.source_system',
                'inv.erp_customer_code',
                DB::raw('COUNT(*) as invoice_count'),
                DB::raw('COALESCE(SUM(COALESCE(inv.before_vat_amount, inv.net_amount)), 0) as actual_before_vat'),
                DB::raw('COALESCE(SUM(COALESCE(inv.gross_amount, inv.net_amount)), 0) as actual_gross'),
                DB::raw('MAX(inv.sold_at) as last_purchase_at')
            );

        return self::applyActualSalesScope($q, 'inv');
    }

    /**
     * Aggregated excluded service/packaging item amounts (CC, CO, AS, PK) per customer on actual invoices.
     */
    public static function customerExcludedItemsSubquery(): Builder
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

        return self::applyActualSalesScope($q, 'inv');
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
        $actualQuery = self::applyActualSalesScope(DB::table('erp_sales_invoices as inv'), 'inv');
        if ($filterCallback) {
            $filterCallback($actualQuery, 'inv');
        }

        $actualHeader = $actualQuery->selectRaw('
            COUNT(*) as invoice_count,
            COALESCE(SUM(COALESCE(inv.before_vat_amount, inv.net_amount)), 0) as total_before_vat,
            COALESCE(SUM(COALESCE(inv.vat_amount, 0)), 0) as total_vat,
            COALESCE(SUM(COALESCE(inv.gross_amount, inv.net_amount)), 0) as total_gross
        ')->first();

        $returnsQuery = DB::table('erp_sales_invoices as inv')
            ->where('inv.doc_category', 'return')
            ->where('inv.status', 'returned');
        if ($filterCallback) {
            $filterCallback($returnsQuery, 'inv');
        }
        $returnsRow = $returnsQuery->selectRaw('
            COUNT(*) as return_count,
            COALESCE(SUM(COALESCE(inv.before_vat_amount, inv.net_amount)), 0) as return_before_vat
        ')->first();

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

        $reservationQuery = DB::table('erp_sales_invoices as inv')
            ->where(function (Builder $q): void {
                $q->where('inv.doc_category', 'reservation')
                    ->orWhere('inv.dt_properties', 207);
            })
            ->where('inv.status', '!=', 'cancelled');
        if ($filterCallback) {
            $filterCallback($reservationQuery, 'inv');
        }
        $reservationRow = $reservationQuery->selectRaw('
            COUNT(*) as reservation_count,
            COALESCE(SUM(COALESCE(inv.before_vat_amount, inv.net_amount)), 0) as reservation_before_vat,
            COALESCE(SUM(COALESCE(inv.gross_amount, inv.net_amount)), 0) as reservation_gross
        ')->first();

        $cancelledQuery = DB::table('erp_sales_invoices as inv')->where('inv.status', 'cancelled');
        if ($filterCallback) {
            $filterCallback($cancelledQuery, 'inv');
        }
        $cancelledCount = (int) $cancelledQuery->count();

        $totalBeforeVat = round((float) ($actualHeader->total_before_vat ?? 0), 2);
        $totalVat = round((float) ($actualHeader->total_vat ?? 0), 2);
        $totalGross = round((float) ($actualHeader->total_gross ?? 0), 2);
        $excludedBeforeVat = round((float) ($excludedRow->excluded_before_vat ?? 0), 2);
        $productKpiBeforeVat = round($totalBeforeVat - $excludedBeforeVat, 2);

        return [
            'invoice_count' => (int) ($actualHeader->invoice_count ?? 0),
            'primary_sales_before_vat' => $productKpiBeforeVat,
            'actual_all_before_vat' => $totalBeforeVat,
            'excluded_service_before_vat' => $excludedBeforeVat,
            'vat_total' => $totalVat,
            'gross_total' => $totalGross,
            'return_count' => (int) ($returnsRow->return_count ?? 0),
            'return_before_vat' => round((float) ($returnsRow->return_before_vat ?? 0), 2),
            'reservation_count' => (int) ($reservationRow->reservation_count ?? 0),
            'reservation_before_vat' => round((float) ($reservationRow->reservation_before_vat ?? 0), 2),
            'reservation_gross' => round((float) ($reservationRow->reservation_gross ?? 0), 2),
            'cancelled_count' => $cancelledCount,
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
        $invoices = $invQ->select(
            'inv.sold_at',
            'inv.doc_category',
            DB::raw('COALESCE(inv.before_vat_amount, inv.net_amount) as before_vat'),
            DB::raw('COALESCE(inv.vat_amount, 0) as vat'),
            DB::raw('COALESCE(inv.gross_amount, inv.net_amount) as gross')
        )->orderBy('inv.sold_at')->get();

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
        $excludedItems = $excQ->select(
            'inv.sold_at',
            DB::raw('COALESCE(it.before_vat_amount, it.net_amount) as before_vat')
        )->get();

        $buckets = [];
        foreach ($invoices as $row) {
            $month = substr((string) $row->sold_at, 0, 7);
            if ($month === '') {
                continue;
            }
            $buckets[$month] ??= [
                'month' => $month,
                'invoice_count' => 0,
                'primary_sales_before_vat' => 0.0,
                'excluded_service_before_vat' => 0.0,
                'vat_total' => 0.0,
                'gross_total' => 0.0,
                'return_before_vat' => 0.0,
            ];
            $before = (float) $row->before_vat;
            $buckets[$month]['invoice_count']++;
            $buckets[$month]['primary_sales_before_vat'] += $before;
            $buckets[$month]['vat_total'] += (float) $row->vat;
            $buckets[$month]['gross_total'] += (float) $row->gross;
            if ($row->doc_category === 'return') {
                $buckets[$month]['return_before_vat'] += $before;
            }
        }

        foreach ($excludedItems as $ex) {
            $month = substr((string) $ex->sold_at, 0, 7);
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
            'primary_sales_before_vat' => round($b['primary_sales_before_vat'], 2),
            'excluded_service_before_vat' => round($b['excluded_service_before_vat'], 2),
            'vat_total' => round($b['vat_total'], 2),
            'gross_total' => round($b['gross_total'], 2),
            'return_before_vat' => round($b['return_before_vat'], 2),
        ], $buckets));
    }
}
