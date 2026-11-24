<?php

namespace App\Modules\ERPIntegration;

use App\Modules\CRM\CustomerAccess;
use App\Modules\Loyalty\InvoicePointsSynchronizer;
use Illuminate\Support\Facades\DB;

/**
 * Shared idempotent upserter for both /api/sync/v1/* push endpoints and Local Bridge pull sync.
 */
final class ErpMirrorUpserter
{
    public static function upsertCustomer(string $source, array $rec, mixed $now): ?bool
    {
        $code = trim((string) ($rec['erp_customer_code'] ?? ''));
        if ($code === '') {
            return null;
        }

        $exists = DB::table('erp_customers')
            ->where('source_system', $source)
            ->where('erp_customer_code', $code)
            ->exists();

        if (! $exists && CustomerAccess::isClosedCustomerRecord(
            isset($rec['ar_enable']) ? (string) $rec['ar_enable'] : null,
            isset($rec['customer_name']) ? (string) $rec['customer_name'] : null
        )) {
            return null;
        }

        DB::table('erp_customers')->updateOrInsert(
            ['source_system' => $source, 'erp_customer_code' => $code],
            array_merge(
                $exists ? [] : ['created_at' => $now],
                [
                    'customer_name' => (string) ($rec['customer_name'] ?? $code),
                    'phone' => $rec['phone'] ?? null,
                    'province' => $rec['province'] ?? null,
                    'address' => $rec['address'] ?? null,
                    'ar_enable' => $rec['ar_enable'] ?? null,
                    'salesperson_code' => $rec['salesperson_code'] ?? null,
                    'salesperson_name' => $rec['salesperson_name'] ?? null,
                    'route_code' => $rec['route_code'] ?? null,
                    'route_name' => $rec['route_name'] ?? null,
                    'credit_limit' => isset($rec['credit_limit']) && $rec['credit_limit'] !== '' ? (string) $rec['credit_limit'] : null,
                    'credit_term_days' => isset($rec['credit_term_days']) && $rec['credit_term_days'] !== '' ? (int) $rec['credit_term_days'] : null,
                    'tax_id' => $rec['tax_id'] ?? null,
                    'updated_at' => $now,
                ]
            )
        );

        return $exists;
    }

    public static function upsertProduct(string $source, array $rec, mixed $now): bool
    {
        $code = trim((string) ($rec['erp_product_code'] ?? ''));
        if ($code === '') {
            return false;
        }

        $categoryCode = isset($rec['category_code']) ? trim((string) $rec['category_code']) : null;
        $categoryCode = $categoryCode === '' ? null : $categoryCode;
        $isExcluded = array_key_exists('is_excluded_kpi', $rec)
            ? filter_var($rec['is_excluded_kpi'], FILTER_VALIDATE_BOOLEAN)
            : SalesRules::isExcludedCategory($categoryCode);

        $exists = DB::table('erp_products')
            ->where('source_system', $source)
            ->where('erp_product_code', $code)
            ->exists();

        DB::table('erp_products')->updateOrInsert(
            ['source_system' => $source, 'erp_product_code' => $code],
            array_merge(
                $exists ? [] : ['created_at' => $now],
                [
                    'product_name' => (string) ($rec['product_name'] ?? $code),
                    'unit' => $rec['unit'] ?? null,
                    'category_code' => $categoryCode,
                    'category_name' => $rec['category_name'] ?? null,
                    'is_excluded_kpi' => $isExcluded,
                    'updated_at' => $now,
                ]
            )
        );

        return $exists;
    }

    public static function upsertInvoiceWithItems(string $source, array $rec, mixed $now): bool
    {
        $branch = trim((string) ($rec['branch_code'] ?? '001'));
        $docType = trim((string) ($rec['doc_type'] ?? 'SALE'));
        $invNo = trim((string) ($rec['invoice_no'] ?? ''));
        if ($invNo === '') {
            return false;
        }

        $classified = SalesRules::classifyDocument(
            $rec['dt_properties'] ?? null,
            $docType,
            $rec['status'] ?? ($rec['di_active'] ?? null),
            $rec['doc_category'] ?? null
        );
        $amounts = SalesRules::normalizeAmounts(
            $classified['dt_properties'],
            $classified['doc_category'],
            $rec
        );

        $key = [
            'source_system' => $source,
            'branch_code' => $branch,
            'doc_type' => $docType,
            'invoice_no' => $invNo,
        ];
        $exists = DB::table('erp_sales_invoices')->where($key)->exists();

        DB::table('erp_sales_invoices')->updateOrInsert(
            $key,
            array_merge(
                $exists ? [] : ['created_at' => $now],
                [
                    'erp_customer_code' => (string) ($rec['erp_customer_code'] ?? ''),
                    'salesperson_code' => $rec['salesperson_code'] ?? null,
                    'salesperson_name' => $rec['salesperson_name'] ?? null,
                    'sold_at' => $rec['sold_at'],
                    'dt_properties' => $classified['dt_properties'],
                    'doc_category' => $classified['doc_category'],
                    'before_vat_amount' => number_format($amounts['before_vat_amount'], 2, '.', ''),
                    'vat_amount' => number_format($amounts['vat_amount'], 2, '.', ''),
                    'gross_amount' => number_format($amounts['gross_amount'], 2, '.', ''),
                    'net_amount' => number_format($amounts['net_amount'], 2, '.', ''),
                    'status' => $classified['status'],
                    'updated_at' => $now,
                ]
            )
        );

        $items = $rec['items'] ?? [];
        if (filter_var($rec['items_complete'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $lineNumbers = array_values(array_filter(array_map(
                static fn (array $item): int => (int) ($item['line_no'] ?? 0),
                $items
            ), static fn (int $lineNo): bool => $lineNo > 0));

            $staleLines = DB::table('erp_sales_items')
                ->where('source_system', $source)
                ->where('branch_code', $branch)
                ->where('doc_type', $docType)
                ->where('invoice_no', $invNo);

            if ($lineNumbers !== []) {
                $staleLines->whereNotIn('line_no', $lineNumbers);
            }
            $staleLines->delete();
        }

        foreach ($items as $item) {
            self::upsertSalesItem($source, $branch, $docType, $invNo, $classified, $item, $now);
        }

        InvoicePointsSynchronizer::syncByBusinessKey($source, $key, $now);

        return $exists;
    }

    private static function upsertSalesItem(
        string $source,
        string $branch,
        string $docType,
        string $invNo,
        array $classified,
        array $item,
        mixed $now
    ): void {
        $productCode = (string) ($item['erp_product_code'] ?? '');
        $iccatCode = isset($item['iccat_code']) ? trim((string) $item['iccat_code']) : null;
        $iccatCode = $iccatCode === '' ? null : $iccatCode;
        $iccatName = $item['iccat_name'] ?? null;

        if ($iccatCode === null && $productCode !== '') {
            $prod = DB::table('erp_products')
                ->where('source_system', $source)
                ->where('erp_product_code', $productCode)
                ->select('category_code', 'category_name', 'is_excluded_kpi')
                ->first();
            if ($prod) {
                $iccatCode = $prod->category_code;
                $iccatName = $iccatName ?? $prod->category_name;
            }
        }

        $isExcluded = array_key_exists('is_excluded_kpi', $item)
            ? filter_var($item['is_excluded_kpi'], FILTER_VALIDATE_BOOLEAN)
            : SalesRules::isExcludedCategory($iccatCode);

        $itemAmounts = SalesRules::normalizeAmounts(
            $classified['dt_properties'],
            $classified['doc_category'],
            $item
        );

        $itemKey = [
            'source_system' => $source,
            'branch_code' => $branch,
            'doc_type' => $docType,
            'invoice_no' => $invNo,
            'line_no' => (int) ($item['line_no'] ?? 1),
        ];
        $itemExists = DB::table('erp_sales_items')->where($itemKey)->exists();

        DB::table('erp_sales_items')->updateOrInsert(
            $itemKey,
            array_merge(
                $itemExists ? [] : ['created_at' => $now],
                [
                    'erp_product_code' => $productCode,
                    'product_name' => (string) ($item['product_name'] ?? ''),
                    'iccat_code' => $iccatCode,
                    'iccat_name' => $iccatName,
                    'is_excluded_kpi' => $isExcluded,
                    'qty' => (string) ($item['qty'] ?? '0'),
                    'unit_name' => $item['unit_name'] ?? null,
                    'unit_price' => (string) ($item['unit_price'] ?? '0'),
                    'before_vat_amount' => number_format($itemAmounts['before_vat_amount'], 2, '.', ''),
                    'vat_amount' => number_format($itemAmounts['vat_amount'], 2, '.', ''),
                    'gross_amount' => number_format($itemAmounts['gross_amount'], 2, '.', ''),
                    'net_amount' => number_format($itemAmounts['net_amount'], 2, '.', ''),
                    'refer_trd_key' => $item['refer_trd_key'] ?? null,
                    'refer_doc_ref' => $item['refer_doc_ref'] ?? null,
                    'updated_at' => $now,
                ]
            )
        );
    }

    public static function upsertSalesperson(string $source, array $rec, mixed $now): bool
    {
        $code = trim((string) ($rec['salesperson_code'] ?? ''));
        if ($code === '') {
            return false;
        }

        $key = ['source_system' => $source, 'salesperson_code' => $code];
        $exists = DB::table('erp_salespersons')->where($key)->exists();

        DB::table('erp_salespersons')->updateOrInsert(
            $key,
            array_merge(
                $exists ? [] : ['created_at' => $now],
                [
                    'salesperson_name' => (string) ($rec['salesperson_name'] ?? $code),
                    'is_active' => array_key_exists('is_active', $rec) ? (bool) $rec['is_active'] : true,
                    'line_code' => $rec['line_code'] ?? null,
                    'line_name' => $rec['line_name'] ?? null,
                    'team_code' => $rec['team_code'] ?? null,
                    'team_name' => $rec['team_name'] ?? null,
                    'area_code' => $rec['area_code'] ?? null,
                    'area_name' => $rec['area_name'] ?? null,
                    'remark' => $rec['remark'] ?? null,
                    'updated_at' => $now,
                ]
            )
        );

        return $exists;
    }

    public static function upsertMember(string $source, array $rec, mixed $now): bool
    {
        $code = trim((string) ($rec['member_code'] ?? ''));
        if ($code === '') {
            return false;
        }

        $key = ['source_system' => $source, 'member_code' => $code];
        $exists = DB::table('erp_members')->where($key)->exists();

        DB::table('erp_members')->updateOrInsert(
            $key,
            array_merge(
                $exists ? [] : ['created_at' => $now],
                [
                    'title' => $rec['title'] ?? null,
                    'full_name' => (string) ($rec['full_name'] ?? $code),
                    'phone' => $rec['phone'] ?? null,
                    'mobile_phone' => $rec['mobile_phone'] ?? null,
                    'email' => $rec['email'] ?? null,
                    'address' => $rec['address'] ?? null,
                    'member_type_code' => $rec['member_type_code'] ?? null,
                    'member_type_name' => $rec['member_type_name'] ?? null,
                    'member_since' => $rec['member_since'] ?? null,
                    'member_expire' => $rec['member_expire'] ?? null,
                    'erp_point_balance' => (int) ($rec['erp_point_balance'] ?? 0),
                    'erp_redeemed_total' => (int) ($rec['erp_redeemed_total'] ?? 0),
                    'erp_spent_total' => (string) ($rec['erp_spent_total'] ?? '0'),
                    'last_point_at' => $rec['last_point_at'] ?? null,
                    'updated_at' => $now,
                ]
            )
        );

        return $exists;
    }
}
