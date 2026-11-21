<?php

namespace App\Modules\Loyalty;

use Illuminate\Support\Facades\DB;

/**
 * Idempotently reconciles loyalty points against the current ERP invoice state.
 * It appends an adjustment row instead of rewriting an existing ledger entry.
 */
final class InvoicePointsSynchronizer
{
    public static function syncByBusinessKey(string $source, array $key, mixed $now): void
    {
        $invoice = DB::table('erp_sales_invoices')->where([
            'source_system' => $source,
            'branch_code' => $key['branch_code'],
            'doc_type' => $key['doc_type'],
            'invoice_no' => $key['invoice_no'],
        ])->first();
        if (! $invoice) {
            return;
        }

        $link = DB::table('member_erp_links as l')
            ->join('erp_customers as c', 'c.id', '=', 'l.erp_customer_id')
            ->where('c.source_system', $invoice->source_system)
            ->where('c.erp_customer_code', $invoice->erp_customer_code)
            ->where('l.verification_status', 'verified')
            ->whereNotNull('l.verified_at')
            ->select('l.member_account_id')
            ->first();
        if (! $link) {
            return;
        }

        $rule = DB::table('loyalty_rules')->where('is_active', true)->orderByDesc('id')->first();
        if (! $rule) {
            return;
        }

        $target = LoyaltyController::calculatePointsForAmount(
            (float) ($invoice->before_vat_amount ?? $invoice->net_amount),
            $rule,
            (string) $invoice->doc_category,
            (string) $invoice->status
        );
        $referenceId = hash('sha256', implode('|', [
            $invoice->source_system, $invoice->branch_code, $invoice->doc_type, $invoice->invoice_no,
        ]));
        $credited = (int) DB::table('loyalty_points_ledger')
            ->where('member_account_id', $link->member_account_id)
            ->where('entry_type', 'erp_invoice')
            ->where('reference_type', 'erp_sales_invoice')
            ->where('reference_id', $referenceId)
            ->sum('points_delta');
        $delta = $target - $credited;
        if ($delta === 0) {
            return;
        }

        $lastEntryId = DB::table('loyalty_points_ledger')
            ->where('member_account_id', $link->member_account_id)
            ->where('entry_type', 'erp_invoice')
            ->where('reference_type', 'erp_sales_invoice')
            ->where('reference_id', $referenceId)
            ->max('id') ?? 0;
        $eventKey = 'ERP-INVOICE:'.$referenceId.':'.hash('sha256', $lastEntryId.'|'.$credited.'|'.$target.'|'.$rule->id);
        DB::table('loyalty_points_ledger')->insertOrIgnore([
            'member_account_id' => $link->member_account_id,
            'event_key' => $eventKey,
            'points_delta' => $delta,
            'reason' => 'ERP invoice points reconciliation',
            'entry_type' => 'erp_invoice',
            'reference_type' => 'erp_sales_invoice',
            'reference_id' => $referenceId,
            'rule_id' => $rule->id,
            'created_at' => $now,
        ]);
    }

    public static function syncCustomer(string $source, string $erpCustomerCode, mixed $now): void
    {
        $link = DB::table('member_erp_links as l')
            ->join('erp_customers as c', 'c.id', '=', 'l.erp_customer_id')
            ->where('c.source_system', $source)
            ->where('c.erp_customer_code', $erpCustomerCode)
            ->where('l.verification_status', 'verified')
            ->whereNotNull('l.verified_at')
            ->select('l.member_account_id')
            ->first();
        if (! $link) {
            return;
        }

        $rule = DB::table('loyalty_rules')->where('is_active', true)->orderByDesc('id')->first();
        if (! $rule) {
            return;
        }

        $invoices = DB::table('erp_sales_invoices')
            ->where('source_system', $source)
            ->where('erp_customer_code', $erpCustomerCode)
            ->select('source_system', 'branch_code', 'doc_type', 'invoice_no', 'before_vat_amount', 'net_amount', 'doc_category', 'status')
            ->orderBy('sold_at')
            ->get();
        if ($invoices->isEmpty()) {
            return;
        }

        $existing = DB::table('loyalty_points_ledger')
            ->where('member_account_id', $link->member_account_id)
            ->where('entry_type', 'erp_invoice')
            ->where('reference_type', 'erp_sales_invoice')
            ->groupBy('reference_id')
            ->select('reference_id', DB::raw('SUM(points_delta) as credited'), DB::raw('MAX(id) as last_id'))
            ->get()
            ->keyBy('reference_id');

        $batch = [];
        foreach ($invoices as $invoice) {
            $target = LoyaltyController::calculatePointsForAmount(
                (float) ($invoice->before_vat_amount ?? $invoice->net_amount),
                $rule,
                (string) $invoice->doc_category,
                (string) $invoice->status
            );
            $referenceId = hash('sha256', implode('|', [
                $invoice->source_system, $invoice->branch_code, $invoice->doc_type, $invoice->invoice_no,
            ]));
            $prev = $existing->get($referenceId);
            $credited = $prev ? (int) $prev->credited : 0;
            $delta = $target - $credited;
            if ($delta === 0) {
                continue;
            }
            $lastEntryId = $prev ? (int) $prev->last_id : 0;
            $eventKey = 'ERP-INVOICE:'.$referenceId.':'.hash('sha256', $lastEntryId.'|'.$credited.'|'.$target.'|'.$rule->id);
            $batch[] = [
                'member_account_id' => $link->member_account_id,
                'event_key' => $eventKey,
                'points_delta' => $delta,
                'reason' => 'ERP invoice points reconciliation',
                'entry_type' => 'erp_invoice',
                'reference_type' => 'erp_sales_invoice',
                'reference_id' => $referenceId,
                'rule_id' => $rule->id,
                'created_at' => $now,
            ];
        }

        if ($batch !== []) {
            foreach (array_chunk($batch, 250) as $chunk) {
                DB::table('loyalty_points_ledger')->insertOrIgnore($chunk);
            }
        }
    }
}
