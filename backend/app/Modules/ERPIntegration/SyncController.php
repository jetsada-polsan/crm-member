<?php

namespace App\Modules\ERPIntegration;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SyncController
{
    public function customers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'batch_id' => ['required', 'uuid'],
            'records' => ['required', 'array', 'min:1', 'max:250'],
            'records.*.erp_customer_code' => ['required', 'string', 'max:64'],
            'records.*.customer_name' => ['required', 'string', 'max:255'],
            'records.*.phone' => ['nullable', 'string', 'max:40'],
            'records.*.province' => ['nullable', 'string', 'max:120'],
            'records.*.address' => ['nullable', 'string', 'max:500'],
            'records.*.ar_enable' => ['nullable', 'string', 'max:8'],
            'records.*.salesperson_code' => ['nullable', 'string', 'max:64'],
            'records.*.salesperson_name' => ['nullable', 'string', 'max:120'],
            'records.*.route_code' => ['nullable', 'string', 'max:32'],
            'records.*.route_name' => ['nullable', 'string', 'max:120'],
            'records.*.credit_limit' => ['nullable', 'numeric'],
            'records.*.credit_term_days' => ['nullable', 'integer'],
            'records.*.tax_id' => ['nullable', 'string', 'max:32'],
        ]);

        return $this->applyBatch('customers', $data, function ($record, $source, $now) {
            ErpMirrorUpserter::upsertCustomer($source, $record, $now);
        });
    }

    public function products(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'batch_id' => ['required', 'uuid'],
            'records' => ['required', 'array', 'min:1', 'max:250'],
            'records.*.erp_product_code' => ['required', 'string', 'max:64'],
            'records.*.product_name' => ['required', 'string', 'max:255'],
            'records.*.unit' => ['nullable', 'string', 'max:30'],
            'records.*.category_code' => ['nullable', 'string', 'max:32'],
            'records.*.category_name' => ['nullable', 'string', 'max:120'],
            'records.*.is_excluded_kpi' => ['nullable', 'boolean'],
        ]);

        return $this->applyBatch('products', $data, function ($record, $source, $now) {
            ErpMirrorUpserter::upsertProduct($source, $record, $now);
        });
    }

    public function invoices(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'batch_id' => ['required', 'uuid'],
            'records' => ['required', 'array', 'min:1', 'max:250'],
            'records.*.branch_code' => ['required', 'string', 'max:32'],
            'records.*.doc_type' => ['required', 'string', 'max:32'],
            'records.*.dt_properties' => ['nullable', 'integer'],
            'records.*.doc_category' => ['nullable', Rule::in(['sale', 'return', 'reservation', 'other'])],
            'records.*.invoice_no' => ['required', 'string', 'max:80'],
            'records.*.erp_customer_code' => ['required', 'string', 'max:64'],
            'records.*.salesperson_code' => ['nullable', 'string', 'max:32'],
            'records.*.salesperson_name' => ['nullable', 'string', 'max:120'],
            'records.*.sold_at' => ['required', 'date'],
            'records.*.before_vat_amount' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'records.*.vat_amount' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'records.*.gross_amount' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'records.*.net_amount' => ['required_without_all:records.*.before_vat_amount,records.*.gross_amount', 'nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'records.*.status' => ['required', Rule::in(['posted', 'cancelled', 'returned', 'reserved', 'draft'])],
            'records.*.items' => ['nullable', 'array', 'max:2000'],
            'records.*.items_complete' => ['nullable', 'boolean'],
            'records.*.items.*.line_no' => ['required_with:records.*.items', 'integer', 'min:1'],
            'records.*.items.*.erp_product_code' => ['required_with:records.*.items', 'string', 'max:64'],
            'records.*.items.*.product_name' => ['required_with:records.*.items', 'string', 'max:255'],
            'records.*.items.*.iccat_code' => ['nullable', 'string', 'max:32'],
            'records.*.items.*.iccat_name' => ['nullable', 'string', 'max:120'],
            'records.*.items.*.is_excluded_kpi' => ['nullable', 'boolean'],
            'records.*.items.*.qty' => ['required_with:records.*.items', 'numeric'],
            'records.*.items.*.unit_name' => ['nullable', 'string', 'max:60'],
            'records.*.items.*.unit_price' => ['nullable', 'numeric'],
            'records.*.items.*.before_vat_amount' => ['nullable', 'numeric'],
            'records.*.items.*.vat_amount' => ['nullable', 'numeric'],
            'records.*.items.*.gross_amount' => ['nullable', 'numeric'],
            'records.*.items.*.net_amount' => ['nullable', 'numeric'],
            'records.*.items.*.refer_trd_key' => ['nullable', 'string', 'max:64'],
            'records.*.items.*.refer_doc_ref' => ['nullable', 'string', 'max:80'],
        ]);

        foreach ($data['records'] as $index => $record) {
            if (filter_var($record['items_complete'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && ! array_key_exists('items', $record)) {
                throw ValidationException::withMessages([
                    "records.{$index}.items" => 'A complete invoice snapshot must include its items array.',
                ]);
            }
        }

        return $this->applyBatch('invoices', $data, function ($record, $source, $now) {
            ErpMirrorUpserter::upsertInvoiceWithItems($source, $record, $now);
        });
    }

    public function salespersons(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'batch_id' => ['required', 'uuid'],
            'records' => ['required', 'array', 'min:1', 'max:250'],
            'records.*.salesperson_code' => ['required', 'string', 'max:32'],
            'records.*.salesperson_name' => ['required', 'string', 'max:120'],
            'records.*.is_active' => ['nullable', 'boolean'],
            'records.*.line_code' => ['nullable', 'string', 'max:32'],
            'records.*.line_name' => ['nullable', 'string', 'max:120'],
            'records.*.team_code' => ['nullable', 'string', 'max:32'],
            'records.*.team_name' => ['nullable', 'string', 'max:120'],
            'records.*.area_code' => ['nullable', 'string', 'max:32'],
            'records.*.area_name' => ['nullable', 'string', 'max:120'],
            'records.*.remark' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->applyBatch('salespersons', $data, function ($record, $source, $now) {
            ErpMirrorUpserter::upsertSalesperson($source, $record, $now);
        });
    }

    public function members(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'batch_id' => ['required', 'uuid'],
            'records' => ['required', 'array', 'min:1', 'max:250'],
            'records.*.member_code' => ['required', 'string', 'max:64'],
            'records.*.full_name' => ['required', 'string', 'max:255'],
            'records.*.title' => ['nullable', 'string', 'max:40'],
            'records.*.phone' => ['nullable', 'string', 'max:64'],
            'records.*.mobile_phone' => ['nullable', 'string', 'max:64'],
            'records.*.email' => ['nullable', 'string', 'max:180'],
            'records.*.address' => ['nullable', 'string', 'max:500'],
            'records.*.member_type_code' => ['nullable', 'string', 'max:32'],
            'records.*.member_type_name' => ['nullable', 'string', 'max:120'],
            'records.*.member_since' => ['nullable', 'date'],
            'records.*.member_expire' => ['nullable', 'date'],
            'records.*.erp_point_balance' => ['nullable', 'integer'],
            'records.*.erp_redeemed_total' => ['nullable', 'integer'],
            'records.*.erp_spent_total' => ['nullable', 'numeric'],
            'records.*.last_point_at' => ['nullable', 'date'],
        ]);

        return $this->applyBatch('members', $data, function ($record, $source, $now) {
            ErpMirrorUpserter::upsertMember($source, $record, $now);
        });
    }

    private function applyBatch(string $kind, array $data, callable $apply): JsonResponse
    {
        $bodyHash = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $already = false;
        DB::transaction(function () use ($kind, $data, $apply, $bodyHash, &$already) {
            $batch = DB::table('sync_batches')->where('source_system', $data['source'])->where('kind', $kind)
                ->where('batch_id', $data['batch_id'])->lockForUpdate()->first();
            if ($batch) {
                if (! hash_equals($batch->payload_sha256, $bodyHash)) {
                    abort(409, 'Batch ID already used for different payload');
                }
                $already = true;

                return;
            }
            $now = now();
            foreach ($data['records'] as $record) {
                $apply($record, $data['source'], $now);
            }
            DB::table('sync_batches')->insert([
                'batch_id' => $data['batch_id'],
                'source_system' => $data['source'],
                'kind' => $kind,
                'payload_sha256' => $bodyHash,
                'record_count' => count($data['records']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 3);

        return response()->json([
            'ok' => true,
            'kind' => $kind,
            'received' => count($data['records']),
            'batch_id' => $data['batch_id'],
            'already_processed' => $already,
        ]);
    }
}
