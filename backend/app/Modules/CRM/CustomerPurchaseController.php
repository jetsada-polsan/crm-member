<?php

namespace App\Modules\CRM;

use App\Modules\ERPIntegration\SalesRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Purchase history of one ERP customer for the field-sales app:
 * paged bills, the lines of a single bill, and buying insights (regular / lapsed products).
 * Only actual sales scope documents (posted sales + returned credit notes) are exposed.
 */
final class CustomerPurchaseController
{
    private const LAPSED_AFTER_DAYS = 60;

    private const REGULAR_MIN_BILLS = 3;

    public function invoices(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'months' => ['nullable', 'integer', 'in:0,3,6,12,24'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $customer = $this->findCustomer($id);
        $months = (int) ($validated['months'] ?? 0);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);

        $query = $this->customerInvoices($customer, 'inv');
        if ($months > 0) {
            $query->where('inv.sold_at', '>=', $this->dataUntil()->subMonths($months)->startOfDay());
        }

        $total = (clone $query)->count();
        $rows = $query
            ->select(
                'inv.id',
                'inv.branch_code',
                'inv.doc_type',
                'inv.invoice_no',
                'inv.sold_at',
                'inv.doc_category',
                'inv.status',
                'inv.before_vat_amount',
                'inv.vat_amount',
                'inv.gross_amount',
                'inv.net_amount',
                'inv.salesperson_name'
            )
            ->selectSub($this->itemCountSubquery(), 'item_count')
            ->orderByDesc('inv.sold_at')
            ->orderByDesc('inv.id')
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function invoiceDetail(int $id, int $invoiceId): JsonResponse
    {
        $customer = $this->findCustomer($id);

        $invoice = $this->customerInvoices($customer, 'inv')
            ->where('inv.id', $invoiceId)
            ->select('inv.*')
            ->first();

        abort_unless($invoice, 404, 'Invoice not found for this customer');

        $items = DB::table('erp_sales_items')
            ->where('source_system', $invoice->source_system)
            ->where('branch_code', $invoice->branch_code)
            ->where('doc_type', $invoice->doc_type)
            ->where('invoice_no', $invoice->invoice_no)
            ->orderBy('line_no')
            ->get([
                'line_no',
                'erp_product_code',
                'product_name',
                'iccat_name',
                'is_excluded_kpi',
                'qty',
                'unit_name',
                'unit_price',
                'before_vat_amount',
                'vat_amount',
                'gross_amount',
                'net_amount',
                'refer_doc_ref',
            ]);

        return response()->json([
            'invoice' => $invoice,
            'items' => $items,
        ]);
    }

    public function insights(int $id): JsonResponse
    {
        $customer = $this->findCustomer($id);
        $dataUntil = $this->dataUntil();
        $now = CarbonImmutable::now();

        $lastPurchaseAt = $this->customerInvoices($customer, 'inv')
            ->where('inv.doc_category', 'sale')
            ->max('inv.sold_at');

        $recentStart = $dataUntil->subDays(90)->startOfDay();
        $previousStart = $dataUntil->subDays(180)->startOfDay();

        return response()->json([
            'data_until' => $dataUntil->toIso8601String(),
            'last_purchase_at' => $lastPurchaseAt,
            'days_since_last_purchase' => $lastPurchaseAt
                ? (int) CarbonImmutable::parse($lastPurchaseAt)->startOfDay()->diffInDays($now->startOfDay())
                : null,
            'recent_90_days' => $this->periodTotals($customer, $recentStart, $dataUntil->endOfDay()),
            'previous_90_days' => $this->periodTotals($customer, $previousStart, $recentStart),
            'bills_last_12_months' => $this->customerInvoices($customer, 'inv')
                ->where('inv.doc_category', 'sale')
                ->where('inv.sold_at', '>=', $dataUntil->subMonths(12)->startOfDay())
                ->count(),
            'top_products' => $this->topProducts($customer, $dataUntil),
            'lapsed_products' => $this->lapsedProducts($customer, $dataUntil),
        ]);
    }

    private function findCustomer(int $id): object
    {
        $customer = CustomerAccess::query('c')
            ->where('c.id', $id)
            ->select('c.id', 'c.source_system', 'c.erp_customer_code', 'c.customer_name')
            ->first();

        abort_unless($customer, 404, 'Customer not found or not accessible');

        return $customer;
    }

    private function customerInvoices(object $customer, string $alias): Builder
    {
        $query = DB::table('erp_sales_invoices as '.$alias)
            ->where($alias.'.source_system', $customer->source_system)
            ->where($alias.'.erp_customer_code', $customer->erp_customer_code);

        SalesRules::applyActualSalesScope($query, $alias);

        return $query;
    }

    private function itemCountSubquery(): Builder
    {
        return DB::table('erp_sales_items as cnt')
            ->selectRaw('COUNT(*)')
            ->whereColumn('cnt.source_system', 'inv.source_system')
            ->whereColumn('cnt.branch_code', 'inv.branch_code')
            ->whereColumn('cnt.doc_type', 'inv.doc_type')
            ->whereColumn('cnt.invoice_no', 'inv.invoice_no');
    }

    /**
     * Latest bill date in the whole mirror, so comparisons stay meaningful when the ERP sync is behind.
     */
    private function dataUntil(): CarbonImmutable
    {
        $latest = DB::table('erp_sales_invoices')
            ->whereIn('doc_category', ['sale', 'return'])
            ->max('sold_at');

        $now = CarbonImmutable::now();
        if (! $latest) {
            return $now;
        }

        $latestAt = CarbonImmutable::parse($latest);

        return $latestAt->lessThan($now) ? $latestAt : $now;
    }

    /**
     * @return array{net_before_vat: string, bills: int}
     */
    private function periodTotals(object $customer, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->customerInvoices($customer, 'inv')
            ->where('inv.sold_at', '>=', $from)
            ->where('inv.sold_at', '<', $to)
            ->selectRaw('COALESCE(SUM(COALESCE(inv.before_vat_amount, inv.net_amount)), 0) as net_before_vat')
            ->selectRaw("COALESCE(SUM(CASE WHEN inv.doc_category = 'sale' THEN 1 ELSE 0 END), 0) as bills")
            ->first();

        return [
            'net_before_vat' => (string) ($row->net_before_vat ?? '0'),
            'bills' => (int) ($row->bills ?? 0),
        ];
    }

    private function productAggregate(object $customer, CarbonImmutable $since): Builder
    {
        $query = DB::table('erp_sales_items as it')
            ->join('erp_sales_invoices as inv', function ($join): void {
                $join->on('inv.source_system', '=', 'it.source_system')
                    ->on('inv.branch_code', '=', 'it.branch_code')
                    ->on('inv.doc_type', '=', 'it.doc_type')
                    ->on('inv.invoice_no', '=', 'it.invoice_no');
            })
            ->where('inv.source_system', $customer->source_system)
            ->where('inv.erp_customer_code', $customer->erp_customer_code);
        SalesRules::applyActualSalesScope($query, 'inv');

        return $query
            ->where('inv.doc_category', 'sale')
            ->where('it.is_excluded_kpi', false)
            ->whereNotNull('it.erp_product_code')
            ->where('inv.sold_at', '>=', $since)
            ->groupBy('it.erp_product_code')
            ->select('it.erp_product_code')
            ->selectRaw('MAX(it.product_name) as product_name')
            ->selectRaw('MAX(it.unit_name) as unit_name')
            ->selectRaw('COUNT(DISTINCT inv.id) as bill_count')
            ->selectRaw('COALESCE(SUM(it.qty), 0) as total_qty')
            ->selectRaw('MAX(inv.sold_at) as last_bought_at');
    }

    private function topProducts(object $customer, CarbonImmutable $dataUntil): array
    {
        return $this->productAggregate($customer, $dataUntil->subMonths(12)->startOfDay())
            ->orderByDesc('bill_count')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get()
            ->all();
    }

    /**
     * Products bought regularly in the last 12 months but not in the last LAPSED_AFTER_DAYS days.
     */
    private function lapsedProducts(object $customer, CarbonImmutable $dataUntil): array
    {
        $cutoff = $dataUntil->subDays(self::LAPSED_AFTER_DAYS);

        return $this->productAggregate($customer, $dataUntil->subMonths(12)->startOfDay())
            ->havingRaw('COUNT(DISTINCT inv.id) >= ?', [self::REGULAR_MIN_BILLS])
            ->havingRaw('MAX(inv.sold_at) < ?', [$cutoff])
            ->orderByDesc('bill_count')
            ->limit(5)
            ->get()
            ->all();
    }
}
