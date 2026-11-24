<?php

namespace App\Modules\Loyalty;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Infrastructure controller for Loyalty Rules Configuration, Member Verification approval,
 * Idempotent Points Ledger + Transaction Reversal, and Rewards Catalog / Redemptions.
 */
final class LoyaltyController
{
    private function authorizeManager(): object
    {
        $user = Auth::guard('staff')->user();
        abort_unless($user && in_array($user->role, ['admin', 'manager', 'executive'], true), 403, 'Manager or admin access required');

        return $user;
    }

    /**
     * Pure helper for calculating points preview from a rule row without mutating DB.
     */
    public static function calculatePointsForAmount(float $beforeVatAmount, object $rule, string $docCategory = 'sale', string $status = 'posted'): int
    {
        if (! (bool) ($rule->is_active ?? false)) {
            return 0;
        }
        if (! in_array($docCategory, ['sale', 'return'], true)
            || ! in_array($status, ['posted', 'returned'], true)) {
            return 0;
        }
        if ((bool) ($rule->exclude_cancelled ?? true) && $status === 'cancelled') {
            return 0;
        }
        if ((bool) ($rule->exclude_reservation ?? true) && $docCategory === 'reservation') {
            return 0;
        }
        if ($docCategory === 'return' && ! (bool) ($rule->reverse_on_return ?? true)) {
            return 0;
        }

        $absAmount = abs($beforeVatAmount);
        $minInvoice = (float) ($rule->min_invoice_amount ?? 0);
        if ($absAmount < $minInvoice) {
            return 0;
        }

        $bahtPerPoint = max(0.01, (float) ($rule->baht_per_point ?? 100));
        $pointsPerUnit = max(1, (int) ($rule->points_per_unit ?? 1));
        $units = (int) floor($absAmount / $bahtPerPoint);
        $pts = $units * $pointsPerUnit;

        return $docCategory === 'return' ? -$pts : $pts;
    }

    /**
     * Append a reversal ledger entry inside a DB transaction, preventing double reversal.
     */
    public static function reverseEntryInTransaction(int $ledgerId, string $reason, ?int $staffId = null): array
    {
        $orig = DB::table('loyalty_points_ledger')->where('id', $ledgerId)->lockForUpdate()->first();
        if (! $orig) {
            return ['ok' => false, 'status' => 404, 'error' => 'Ledger entry not found'];
        }
        if ($orig->entry_type === 'reversal') {
            return ['ok' => false, 'status' => 422, 'error' => 'Cannot reverse a reversal entry'];
        }

        $alreadyReversed = DB::table('loyalty_points_ledger')
            ->where('reverses_ledger_id', $ledgerId)
            ->exists();
        if ($alreadyReversed) {
            return ['ok' => false, 'status' => 409, 'error' => 'Transaction has already been reversed'];
        }

        $now = now();
        $revId = DB::table('loyalty_points_ledger')->insertGetId([
            'member_account_id' => $orig->member_account_id,
            'event_key' => 'REV:'.$ledgerId.':'.Str::uuid(),
            'points_delta' => -((int) $orig->points_delta),
            'reason' => $reason,
            'entry_type' => 'reversal',
            'reference_type' => 'loyalty_points_ledger',
            'reference_id' => (string) $ledgerId,
            'reverses_ledger_id' => $ledgerId,
            'rule_id' => $orig->rule_id,
            'created_by_staff_id' => $staffId,
            'note' => 'Reversal of ledger #'.$ledgerId.' ('.$orig->event_key.')',
            'created_at' => $now,
        ]);

        return [
            'ok' => true,
            'status' => 201,
            'reversal_id' => $revId,
            'reversed_delta' => -((int) $orig->points_delta),
        ];
    }

    public function rules(): JsonResponse
    {
        $this->authorizeManager();
        $rows = DB::table('loyalty_rules')->orderBy('id')->get()->map(function ($row) {
            $row->exclude_categories = is_string($row->exclude_categories)
                ? json_decode($row->exclude_categories, true)
                : $row->exclude_categories;

            return $row;
        });

        return response()->json($rows);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $staff = $this->authorizeManager();
        $d = $request->validate([
            'rule_code' => ['required', 'string', 'max:64', 'unique:loyalty_rules,rule_code'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'baht_per_point' => ['required', 'numeric', 'min:0.01'],
            'points_per_unit' => ['required', 'integer', 'min:1'],
            'min_invoice_amount' => ['nullable', 'numeric', 'min:0'],
            'exclude_categories' => ['nullable', 'array'],
            'exclude_reservation' => ['nullable', 'boolean'],
            'exclude_cancelled' => ['nullable', 'boolean'],
            'reverse_on_return' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $now = now();
        $id = DB::table('loyalty_rules')->insertGetId([
            'rule_code' => trim($d['rule_code']),
            'name' => trim($d['name']),
            'description' => $d['description'] ?? null,
            'baht_per_point' => $d['baht_per_point'],
            'points_per_unit' => $d['points_per_unit'],
            'min_invoice_amount' => $d['min_invoice_amount'] ?? 0,
            'exclude_categories' => json_encode($d['exclude_categories'] ?? ['CC', 'CO', 'AS', 'PK']),
            'exclude_reservation' => $d['exclude_reservation'] ?? true,
            'exclude_cancelled' => $d['exclude_cancelled'] ?? true,
            'reverse_on_return' => $d['reverse_on_return'] ?? true,
            'is_active' => $d['is_active'] ?? false, // Safe default: disabled until company activates rules
            'configured_by' => $staff->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function updateRule(Request $request, int $id): JsonResponse
    {
        $staff = $this->authorizeManager();
        $rule = DB::table('loyalty_rules')->where('id', $id)->first();
        abort_unless($rule, 404);

        $d = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'baht_per_point' => ['sometimes', 'numeric', 'min:0.01'],
            'points_per_unit' => ['sometimes', 'integer', 'min:1'],
            'min_invoice_amount' => ['sometimes', 'numeric', 'min:0'],
            'exclude_categories' => ['sometimes', 'array'],
            'exclude_reservation' => ['sometimes', 'boolean'],
            'exclude_cancelled' => ['sometimes', 'boolean'],
            'reverse_on_return' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('exclude_categories', $d)) {
            $d['exclude_categories'] = json_encode($d['exclude_categories']);
        }
        $d['configured_by'] = $staff->id;
        $d['updated_at'] = now();

        DB::table('loyalty_rules')->where('id', $id)->update($d);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    public function verifications(Request $request): JsonResponse
    {
        $this->authorizeManager();
        $status = $request->query('status', 'pending');

        $rows = DB::table('member_verification_requests as r')
            ->join('member_accounts as m', 'm.id', '=', 'r.member_account_id')
            ->when($status !== 'all', fn ($q) => $q->where('r.status', $status))
            ->select(
                'r.id',
                'r.member_account_id',
                'm.name as member_name',
                'm.email as member_email',
                'm.phone as account_phone',
                'r.requested_customer_code',
                'r.requested_source_system',
                'r.crm_customer_master_id',
                'r.requested_member_code',
                'r.requested_phone',
                'r.evidence_note',
                'r.status',
                'r.reviewed_at',
                'r.created_at'
            )
            ->orderByDesc('r.id')
            ->limit(100)
            ->get();

        return response()->json($rows);
    }

    public function reviewVerification(Request $request, int $id): JsonResponse
    {
        $staff = $this->authorizeManager();
        $d = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'crm_customer_master_id' => ['nullable', 'integer', 'exists:crm_customer_masters,id'],
            'erp_customer_id' => ['nullable', 'integer', 'exists:erp_customers,id'],
            'erp_source_system' => ['nullable', 'string', 'max:32'],
            'erp_customer_code' => ['nullable', 'string', 'max:64'],
            'erp_member_code' => ['nullable', 'string', 'max:64'],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);
        $vr = DB::table('member_verification_requests')->where('id', $id)->first();
        abort_unless($vr, 404);
        if ($vr->status !== 'pending') {
            return response()->json(['error' => 'Verification request already reviewed'], 409);
        }
        $now = now();

        if ($d['decision'] === 'rejected') {
            DB::table('member_verification_requests')->where('id', $id)->update([
                'status' => 'rejected', 'reviewed_by' => $staff->id, 'reviewed_at' => $now,
                'review_note' => $d['review_note'] ?? null, 'updated_at' => $now,
            ]);

            return response()->json(['ok' => true, 'status' => 'rejected']);
        }

        $customer = null;
        if (! empty($d['erp_customer_id'])) {
            $customer = DB::table('erp_customers')->where('id', (int) $d['erp_customer_id'])->first();
        } elseif (! empty($d['erp_customer_code'])) {
            $source = $d['erp_source_system'] ?? $vr->requested_source_system;
            $query = DB::table('erp_customers')->where('erp_customer_code', trim($d['erp_customer_code']));
            if ($source) {
                $query->where('source_system', strtoupper(trim($source)));
            }
            $matches = $query->limit(2)->get();
            if ($matches->count() > 1) {
                return response()->json(['error' => 'ERP code exists in multiple sources; choose an exact source and customer.'], 409);
            }
            $customer = $matches->first();
        } elseif (! empty($vr->requested_customer_code)) {
            $query = DB::table('erp_customers')->where('erp_customer_code', trim($vr->requested_customer_code));
            if ($vr->requested_source_system) {
                $query->where('source_system', strtoupper(trim($vr->requested_source_system)));
            }
            $matches = $query->limit(2)->get();
            if ($matches->count() > 1) {
                return response()->json(['error' => 'Requested ERP code is ambiguous across sources; choose a customer explicitly.'], 409);
            }
            $customer = $matches->first();
        }

        $master = ! empty($d['crm_customer_master_id'])
            ? DB::table('crm_customer_masters')->where('id', (int) $d['crm_customer_master_id'])->first()
            : null;
        if (! $master && $customer) {
            $master = DB::table('crm_customer_masters')->where('erp_customer_id', $customer->id)->first();
            if (! $master) {
                $now = now();
                $masterId = DB::table('crm_customer_masters')->insertGetId([
                    'internal_customer_code' => sprintf('POP-ERP-%06d', $customer->id),
                    'erp_customer_id' => $customer->id,
                    'erp_source_system' => $customer->source_system,
                    'erp_customer_code' => $customer->erp_customer_code,
                    'link_status' => 'linked',
                    'verified_at' => $now,
                    'verified_by' => $staff->id,
                    'display_name' => $customer->customer_name,
                    'phone' => $customer->phone,
                    'address' => $customer->address ?? null,
                    'province' => $customer->province ?? null,
                    'assigned_salesperson_code' => $customer->salesperson_code ?? null,
                    'created_source' => 'erp_mirror',
                    'created_by' => $staff->id,
                    'updated_by' => $staff->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $master = DB::table('crm_customer_masters')->where('id', $masterId)->first();
            }
        }
        if (! $master) {
            return response()->json(['error' => 'Select an internal customer for staff ownership review. Phone or name alone never selects an account.'], 422);
        }
        if ($customer && (int) $master->erp_customer_id !== (int) $customer->id) {
            return response()->json(['error' => 'Selected ERP customer does not belong to the selected internal customer.'], 409);
        }
        if (! $customer && $master->erp_customer_id) {
            $customer = DB::table('erp_customers')->where('id', $master->erp_customer_id)->first();
        }

        $result = DB::transaction(function () use ($vr, $master, $customer, $staff, $d, $now) {
            $byMaster = DB::table('member_erp_links')->where('crm_customer_master_id', $master->id)->lockForUpdate()->first();
            $byErp = $customer
                ? DB::table('member_erp_links')->where('erp_customer_id', $customer->id)->lockForUpdate()->first()
                : null;
            if ($byMaster && $byErp && (int) $byMaster->id !== (int) $byErp->id) {
                abort(409, 'Customer and ERP identities have separate member links. Review those links before approval.');
            }
            $link = $byMaster ?? $byErp;
            if ($link && (int) $link->member_account_id !== (int) $vr->member_account_id) {
                abort(409, 'Customer is already verified to another member account.');
            }
            $otherCustomer = DB::table('member_erp_links')->where('member_account_id', $vr->member_account_id)
                ->when($link, fn ($q) => $q->where('id', '!=', $link->id))->first();
            if ($otherCustomer && (int) $otherCustomer->crm_customer_master_id !== (int) $master->id) {
                abort(409, 'Member account is already linked to a different customer.');
            }
            if ($link && $link->crm_customer_master_id && (int) $link->crm_customer_master_id !== (int) $master->id) {
                abort(409, 'ERP account is already tied to a different internal customer.');
            }

            $values = [
                'member_account_id' => $vr->member_account_id,
                'crm_customer_master_id' => $master->id,
                'erp_customer_id' => $customer?->id,
                'verified_at' => $now,
                'verification_status' => 'verified',
                'verified_by' => $staff->id,
                'verification_note' => $d['review_note'] ?? 'Ownership reviewed by staff.',
                'erp_member_code' => $d['erp_member_code'] ?? $vr->requested_member_code,
                'updated_at' => $now,
            ];
            if ($link) {
                DB::table('member_erp_links')->where('id', $link->id)->update($values);
                $linkId = $link->id;
            } else {
                $linkId = DB::table('member_erp_links')->insertGetId($values + ['created_at' => $now]);
            }

            DB::table('member_verification_requests')->where('id', $vr->id)->update([
                'crm_customer_master_id' => $master->id,
                'status' => 'approved', 'reviewed_by' => $staff->id, 'reviewed_at' => $now,
                'review_note' => $d['review_note'] ?? null, 'updated_at' => $now,
            ]);
            DB::table('crm_audit_logs')->insert([
                'entity_type' => 'crm_customer_master', 'entity_id' => $master->id,
                'action' => 'member_ownership_verified', 'actor_staff_id' => $staff->id,
                'changes' => json_encode([
                    'member_account_id' => $vr->member_account_id,
                    'member_link_id' => $linkId,
                    'erp_customer_id' => $customer?->id,
                    'verification_request_id' => $vr->id,
                    'status' => $customer ? 'verified' : 'verified_pending_erp',
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
            if ($customer) {
                InvoicePointsSynchronizer::syncCustomer($customer->source_system, $customer->erp_customer_code, $now);
            }

            return ['link_id' => $linkId, 'customer_master_id' => $master->id, 'erp_customer_id' => $customer?->id];
        });

        return response()->json([
            'ok' => true, 'status' => 'approved', 'customer_master_id' => $result['customer_master_id'],
            'erp_customer_id' => $result['erp_customer_id'],
            'member_status' => $result['erp_customer_id'] ? 'member_verified' : 'pending_erp',
        ]);
    }

    public function ledger(): JsonResponse
    {
        $this->authorizeManager();
        $rows = DB::table('loyalty_points_ledger as l')
            ->join('member_accounts as m', 'm.id', '=', 'l.member_account_id')
            ->select(
                'l.id',
                'l.member_account_id',
                'm.name as member_name',
                'm.email as member_email',
                'l.event_key',
                'l.points_delta',
                'l.reason',
                'l.entry_type',
                'l.reference_type',
                'l.reference_id',
                'l.reverses_ledger_id',
                'l.note',
                'l.created_at',
                DB::raw('EXISTS(SELECT 1 FROM loyalty_points_ledger rev WHERE rev.reverses_ledger_id = l.id) as is_reversed')
            )
            ->orderByDesc('l.id')
            ->limit(150)
            ->get();

        return response()->json($rows);
    }

    public function redemptions(): JsonResponse
    {
        $this->authorizeManager();
        $rows = DB::table('loyalty_redemptions as r')
            ->join('member_accounts as m', 'm.id', '=', 'r.member_account_id')
            ->join('loyalty_rewards as rw', 'rw.id', '=', 'r.reward_id')
            ->select(
                'r.id',
                'r.member_account_id',
                'm.name as member_name',
                'm.phone as member_phone',
                'r.reward_id',
                'rw.title as reward_title',
                'rw.reward_code',
                'r.points_spent',
                'r.status',
                'r.voucher_code',
                'r.fulfilled_at',
                'r.cancelled_at',
                'r.note',
                'r.created_at'
            )
            ->orderByDesc('r.id')
            ->limit(150)
            ->get();

        return response()->json($rows);
    }

    public function reverseLedger(Request $request): JsonResponse
    {
        $staff = $this->authorizeManager();
        $d = $request->validate([
            'ledger_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:120'],
        ]);

        $res = DB::transaction(fn () => self::reverseEntryInTransaction((int) $d['ledger_id'], $d['reason'], $staff->id));
        if (! $res['ok']) {
            return response()->json(['error' => $res['error']], $res['status']);
        }

        return response()->json($res, 201);
    }

    public function rewards(): JsonResponse
    {
        $this->authorizeManager();

        return response()->json(DB::table('loyalty_rewards')->orderBy('id')->get());
    }

    public function storeReward(Request $request): JsonResponse
    {
        $this->authorizeManager();
        $d = $request->validate([
            'reward_code' => ['required', 'string', 'max:64', 'unique:loyalty_rewards,reward_code'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'points_cost' => ['required', 'integer', 'min:1'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ]);

        $now = now();
        $id = DB::table('loyalty_rewards')->insertGetId([
            'reward_code' => trim($d['reward_code']),
            'title' => trim($d['title']),
            'description' => $d['description'] ?? null,
            'points_cost' => (int) $d['points_cost'],
            'stock_qty' => $d['stock_qty'] ?? null,
            'is_active' => $d['is_active'] ?? false,
            'valid_from' => $d['valid_from'] ?? null,
            'valid_until' => $d['valid_until'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function updateRedemptionStatus(Request $request, int $id): JsonResponse
    {
        $staff = $this->authorizeManager();
        $d = $request->validate([
            'status' => ['required', 'in:approved,fulfilled,cancelled'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $res = DB::transaction(function () use ($id, $d, $staff): array {
            $redemption = DB::table('loyalty_redemptions')->where('id', $id)->lockForUpdate()->first();
            if (! $redemption) {
                return ['ok' => false, 'status' => 404, 'error' => 'Redemption not found'];
            }
            if (in_array($redemption->status, ['fulfilled', 'cancelled'], true)) {
                return ['ok' => false, 'status' => 409, 'error' => 'Redemption already finalized as '.$redemption->status];
            }

            $now = now();
            if ($d['status'] === 'cancelled') {
                if ($redemption->ledger_entry_id) {
                    $rev = self::reverseEntryInTransaction((int) $redemption->ledger_entry_id, 'Redemption cancelled: '.$redemption->voucher_code, $staff->id);
                    if (! $rev['ok']) {
                        return $rev;
                    }
                }
                DB::table('loyalty_rewards')->where('id', $redemption->reward_id)->whereNotNull('stock_qty')->increment('stock_qty');
            }

            DB::table('loyalty_redemptions')->where('id', $id)->update([
                'status' => $d['status'],
                'reviewed_by' => $staff->id,
                'fulfilled_at' => $d['status'] === 'fulfilled' ? $now : $redemption->fulfilled_at,
                'cancelled_at' => $d['status'] === 'cancelled' ? $now : $redemption->cancelled_at,
                'note' => $d['note'] ?? $redemption->note,
                'updated_at' => $now,
            ]);

            return ['ok' => true, 'status' => 200, 'redemption_status' => $d['status']];
        });

        if (! $res['ok']) {
            return response()->json(['error' => $res['error']], $res['status']);
        }

        return response()->json(['ok' => true, 'status' => $res['redemption_status']]);
    }
}
