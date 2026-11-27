<?php

namespace App\Modules\Member;

use App\Modules\ERPIntegration\SalesRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class MemberController
{
    public function profile(): JsonResponse
    {
        $memberId = Auth::guard('member')->id();
        $member = DB::table('member_accounts')
            ->where('id', $memberId)
            ->select('id', 'name', 'email', 'phone', 'status')
            ->first();

        abort_unless($member, 404);

        $link = DB::table('member_erp_links as l')
            ->leftJoin('crm_customer_masters as cm', 'cm.id', '=', 'l.crm_customer_master_id')
            ->leftJoin('erp_customers as c', 'c.id', '=', 'l.erp_customer_id')
            ->leftJoin('erp_members as em', function ($join): void {
                $join->on('em.source_system', '=', 'c.source_system')
                    ->on('em.member_code', '=', 'l.erp_member_code');
            })
            ->where('l.member_account_id', $memberId)
            ->select(
                'l.id as link_id',
                'l.crm_customer_master_id',
                'l.verification_status',
                'l.verified_at',
                'l.erp_member_code',
                'cm.link_status as erp_link_status',
                'l.erp_customer_id',
                'c.source_system',
                'c.erp_customer_code',
                'c.customer_name as erp_customer_name',
                'c.province',
                'em.member_type_name',
                'em.erp_point_balance'
            )
            ->first();

        $verified = $link
            && $link->verification_status === 'verified'
            && $link->verified_at !== null
            && $link->erp_customer_id !== null;
        $purchaseSummary = null;
        if ($verified) {
            $sourceSystem = (string) $link->source_system;
            $customerCode = (string) $link->erp_customer_code;
            $purchaseSummary = SalesRules::summarizeInvoices(function ($query, string $alias) use ($sourceSystem, $customerCode): void {
                $query->where("{$alias}.source_system", $sourceSystem)
                    ->where("{$alias}.erp_customer_code", $customerCode);
            });
        }
        if ($link) {
            $link = [
                'link_id' => $link->link_id,
                'verification_status' => $link->verification_status,
                'verified_at' => $link->verified_at,
                'erp_status' => $link->erp_customer_id ? 'linked' : 'pending_erp',
                'erp_customer_code' => $verified ? $link->erp_customer_code : null,
                'erp_customer_name' => $verified ? $link->erp_customer_name : null,
                'province' => $verified ? $link->province : null,
                'member_type_name' => $verified ? $link->member_type_name : null,
                'erp_point_balance' => $verified ? $link->erp_point_balance : null,
            ];
        }

        $lineBinding = DB::table('member_line_accounts')
            ->where('member_account_id', $memberId)
            ->where('binding_status', 'verified')
            ->whereNull('revoked_at')
            ->select('binding_status', 'line_display_name', 'verified_at')
            ->first();

        $latestVerification = DB::table('member_verification_requests')
            ->where('member_account_id', $memberId)
            ->select('id', 'requested_customer_code', 'requested_source_system', 'crm_customer_master_id', 'status', 'reviewed_at', 'review_note', 'created_at')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'phone' => $member->phone,
                'status' => $member->status,
            ],
            'erp_link' => $link,
            'purchase_summary' => $purchaseSummary,
            'line_binding' => $lineBinding,
            'verification_request' => $latestVerification,
            'identity_status' => ! $link ? 'member_verification_pending' : (
                $link['verification_status'] !== 'verified' || ! $link['verified_at']
                    ? 'member_verification_pending'
                    : ($link['erp_status'] === 'pending_erp' ? 'pending_erp' : 'member_verified')
            ),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $member = Auth::guard('member')->user();
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
        $phone = isset($d['phone']) ? preg_replace('/\D+/', '', $d['phone']) : null;
        $phoneExists = $phone !== null && $phone !== '' && DB::table('member_accounts')->where('id', '!=', $member->id)
            ->whereNotNull('phone')->get(['phone'])->contains(fn ($row) => preg_replace('/\D+/', '', (string) $row->phone) === $phone);
        if ($phoneExists) {
            return response()->json(['errors' => ['phone' => ['This phone number is already registered.']]], 422);
        }

        DB::table('member_accounts')
            ->where('id', $member->id)
            ->update([
                'name' => $d['name'],
                'phone' => $phone === '' ? null : $phone,
                'updated_at' => now(),
            ]);

        return $this->profile();
    }

    public function invoices(): JsonResponse
    {
        $memberId = Auth::guard('member')->id();

        $access = DB::table('member_erp_links')
            ->where('member_account_id', $memberId)
            ->where('verification_status', 'verified')
            ->whereNotNull('verified_at')
            ->whereNotNull('erp_customer_id')
            ->exists();
        if (! $access) {
            return response()->json(['error' => 'Purchase history is available after ownership is verified and the ERP account is linked.'], 403);
        }

        $query = DB::table('erp_sales_invoices as i')
            ->join('erp_customers as c', function ($join): void {
                $join->on('c.source_system', '=', 'i.source_system')
                    ->on('c.erp_customer_code', '=', 'i.erp_customer_code');
            })
            ->join('member_erp_links as l', 'l.erp_customer_id', '=', 'c.id')
            ->where('l.member_account_id', $memberId)
            ->where('l.verification_status', 'verified')
            ->whereNotNull('l.verified_at');

        SalesRules::applyActualSalesScope($query, 'i');

        $rows = $query->select(
            'i.source_system',
            'i.branch_code',
            'i.doc_type',
            'i.dt_properties',
            'i.doc_category',
            'i.invoice_no',
            'i.sold_at',
            DB::raw('COALESCE(i.before_vat_amount, i.net_amount) as before_vat_amount'),
            DB::raw('COALESCE(i.vat_amount, 0) as vat_amount'),
            DB::raw('COALESCE(i.gross_amount, i.net_amount) as gross_amount'),
            'i.net_amount',
            'i.status'
        )
            ->orderByDesc('i.sold_at')
            ->limit(100)
            ->get();

        $invoiceNos = $rows->pluck('invoice_no')->unique()->values()->all();
        $itemsByInv = [];
        if ($invoiceNos !== []) {
            $sources = $rows->pluck('source_system')->unique()->values()->all();
            $allItems = DB::table('erp_sales_items')
                ->whereIn('source_system', $sources)
                ->whereIn('invoice_no', $invoiceNos)
                ->select('source_system', 'branch_code', 'doc_type', 'invoice_no', 'line_no', 'erp_product_code', 'product_name', 'qty', 'unit_name')
                ->orderBy('line_no')
                ->get();
            foreach ($allItems as $it) {
                $k = $it->source_system.'|'.$it->branch_code.'|'.$it->doc_type.'|'.$it->invoice_no;
                $itemsByInv[$k][] = (object) [
                    'line_no' => $it->line_no,
                    'erp_product_code' => $it->erp_product_code,
                    'product_name' => $it->product_name,
                    'qty' => $it->qty,
                    'unit_name' => $it->unit_name,
                ];
            }
        }
        foreach ($rows as $invoice) {
            $k = $invoice->source_system.'|'.$invoice->branch_code.'|'.$invoice->doc_type.'|'.$invoice->invoice_no;
            $invoice->items = $itemsByInv[$k] ?? [];
        }

        return response()->json($rows);
    }

    public function points(): JsonResponse
    {
        $memberId = Auth::guard('member')->id();
        $access = DB::table('member_erp_links')
            ->where('member_account_id', $memberId)
            ->where('verification_status', 'verified')
            ->whereNotNull('verified_at')
            ->whereNotNull('erp_customer_id')
            ->exists();
        if (! $access) {
            return response()->json(['error' => 'Points are available after ownership is verified and the ERP account is linked.'], 403);
        }
        $balance = DB::table('loyalty_points_ledger')->where('member_account_id', $memberId)->sum('points_delta');
        $ledger = DB::table('loyalty_points_ledger')
            ->where('member_account_id', $memberId)
            ->select('id', 'event_key', 'points_delta', 'reason', 'note', 'created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $erpLink = DB::table('member_erp_links as l')
            ->join('erp_customers as c', 'c.id', '=', 'l.erp_customer_id')
            ->leftJoin('erp_members as em', function ($join): void {
                $join->on('em.source_system', '=', 'c.source_system')
                    ->on('em.member_code', '=', 'l.erp_member_code');
            })
            ->where('l.member_account_id', $memberId)
            ->where('l.verification_status', 'verified')
            ->whereNotNull('l.verified_at')
            ->select(
                'l.erp_member_code',
                'em.erp_point_balance',
                'em.erp_redeemed_total',
                'em.member_type_name',
                'em.last_point_at'
            )
            ->first();

        return response()->json([
            'balance' => (int) $balance,
            'erp_point_balance' => $erpLink?->erp_point_balance !== null ? (int) $erpLink->erp_point_balance : null,
            'erp_member' => $erpLink,
            'ledger' => $ledger,
            'note' => 'Only approved ledger entries; no automatic points awarded',
        ]);
    }

    public function createLineLinkToken(): JsonResponse
    {
        $memberId = Auth::guard('member')->id();
        $rawCode = strtoupper(Str::random(10));
        $hash = hash('sha256', $rawCode);
        $now = now();
        $expiresAt = $now->copy()->addMinutes(15);

        DB::transaction(function () use ($memberId, $hash, $now, $expiresAt): void {
            DB::table('line_link_tokens')
                ->where('member_account_id', $memberId)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now]);

            DB::table('line_link_tokens')->insert([
                'member_account_id' => $memberId,
                'token_hash' => $hash,
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ]);
        });

        return response()->json([
            'ok' => true,
            'link_code' => $rawCode,
            'command' => 'LINK:'.$rawCode,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 201);
    }

    public function revokeLineBinding(): JsonResponse
    {
        $memberId = Auth::guard('member')->id();
        $updated = DB::table('member_line_accounts')
            ->where('member_account_id', $memberId)
            ->whereNull('revoked_at')
            ->update([
                'binding_status' => 'revoked',
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        if (Schema::hasColumn('member_accounts', 'line_user_id')) {
            $cleared = DB::table('member_accounts')
                ->where('id', $memberId)
                ->whereNotNull('line_user_id')
                ->update([
                    'line_user_id' => null,
                    'updated_at' => now(),
                ]);
            $updated += $cleared;
        }

        return response()->json(['ok' => true, 'revoked' => $updated > 0]);
    }

    public function submitVerificationRequest(Request $request): JsonResponse
    {
        $memberId = Auth::guard('member')->id();
        $d = $request->validate([
            'requested_customer_code' => ['nullable', 'string', 'max:64'],
            'requested_source_system' => ['nullable', 'string', 'max:32'],
            'crm_customer_master_id' => ['nullable', 'integer', 'exists:crm_customer_masters,id'],
            'requested_member_code' => ['nullable', 'string', 'max:64'],
            'requested_phone' => ['nullable', 'string', 'max:40'],
            'evidence_note' => ['nullable', 'string', 'max:500'],
        ]);

        if (empty($d['requested_customer_code']) && empty($d['crm_customer_master_id'])
            && empty($d['requested_phone']) && empty($d['evidence_note'])) {
            return response()->json(['error' => 'Provide a customer code, contact phone, or evidence note for staff review.'], 422);
        }

        $hasPending = DB::table('member_verification_requests')
            ->where('member_account_id', $memberId)
            ->where('status', 'pending')
            ->exists();
        if ($hasPending) {
            return response()->json(['error' => 'A verification request is already pending review'], 409);
        }

        $now = now();
        $id = DB::table('member_verification_requests')->insertGetId([
            'member_account_id' => $memberId,
            'requested_customer_code' => isset($d['requested_customer_code']) ? trim($d['requested_customer_code']) : null,
            'requested_source_system' => isset($d['requested_source_system']) ? strtoupper(trim($d['requested_source_system'])) : null,
            'crm_customer_master_id' => $d['crm_customer_master_id'] ?? null,
            'requested_member_code' => isset($d['requested_member_code']) ? trim($d['requested_member_code']) : null,
            'requested_phone' => $d['requested_phone'] ?? null,
            'evidence_note' => $d['evidence_note'] ?? null,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json(['ok' => true, 'id' => $id, 'status' => 'pending'], 201);
    }

    public function rewards(): JsonResponse
    {
        $now = now();
        $rows = DB::table('loyalty_rewards')
            ->where('is_active', true)
            ->where(function ($q) use ($now): void {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now): void {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now);
            })
            ->select('id', 'reward_code', 'title', 'description', 'points_cost', 'stock_qty', 'valid_until')
            ->orderBy('points_cost')
            ->get();

        return response()->json($rows);
    }

    public function redeemReward(int $rewardId): JsonResponse
    {
        $memberId = Auth::guard('member')->id();

        $verified = DB::table('member_erp_links')
            ->where('member_account_id', $memberId)
            ->where('verification_status', 'verified')
            ->whereNotNull('verified_at')
            ->whereNotNull('erp_customer_id')
            ->exists();
        if (! $verified) {
            return response()->json(['error' => 'Member ERP verification required before redeeming rewards'], 403);
        }

        $res = DB::transaction(function () use ($memberId, $rewardId): array {
            $now = now();
            // Serialize all balance-changing redemptions for this member, including
            // concurrent requests for different rewards.
            $member = DB::table('member_accounts')->where('id', $memberId)->lockForUpdate()->first();
            if (! $member || $member->status !== 'active') {
                return ['ok' => false, 'status' => 403, 'error' => 'Active member account required'];
            }

            $reward = DB::table('loyalty_rewards')->where('id', $rewardId)->lockForUpdate()->first();
            if (! $reward || ! $reward->is_active) {
                return ['ok' => false, 'status' => 404, 'error' => 'Reward not available'];
            }
            if (($reward->valid_from && $reward->valid_from > $now) || ($reward->valid_until && $reward->valid_until < $now)) {
                return ['ok' => false, 'status' => 422, 'error' => 'Reward outside validity period'];
            }
            if ($reward->stock_qty !== null && (int) $reward->stock_qty <= 0) {
                return ['ok' => false, 'status' => 409, 'error' => 'Reward out of stock'];
            }

            $balance = (int) DB::table('loyalty_points_ledger')
                ->where('member_account_id', $memberId)
                ->sum('points_delta');
            $cost = (int) $reward->points_cost;
            if ($balance < $cost) {
                return ['ok' => false, 'status' => 422, 'error' => 'Insufficient points balance'];
            }

            $voucherCode = 'VCH-'.strtoupper(Str::random(8));
            $ledgerId = DB::table('loyalty_points_ledger')->insertGetId([
                'member_account_id' => $memberId,
                'event_key' => 'REDEEM:'.$voucherCode,
                'points_delta' => -$cost,
                'reason' => 'Redeem reward: '.$reward->reward_code,
                'entry_type' => 'redeem',
                'reference_type' => 'loyalty_rewards',
                'reference_id' => (string) $reward->id,
                'note' => 'Voucher '.$voucherCode,
                'created_at' => $now,
            ]);

            if ($reward->stock_qty !== null) {
                DB::table('loyalty_rewards')->where('id', $reward->id)->decrement('stock_qty');
            }

            $redemptionId = DB::table('loyalty_redemptions')->insertGetId([
                'member_account_id' => $memberId,
                'reward_id' => $reward->id,
                'ledger_entry_id' => $ledgerId,
                'points_spent' => $cost,
                'status' => 'pending',
                'voucher_code' => $voucherCode,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'ok' => true,
                'status' => 201,
                'redemption_id' => $redemptionId,
                'voucher_code' => $voucherCode,
                'points_spent' => $cost,
                'remaining_balance' => $balance - $cost,
            ];
        });

        if (! $res['ok']) {
            return response()->json(['error' => $res['error']], $res['status']);
        }

        return response()->json($res, 201);
    }

    public function redemptions(): JsonResponse
    {
        $memberId = Auth::guard('member')->id();
        $rows = DB::table('loyalty_redemptions as rd')
            ->join('loyalty_rewards as rw', 'rw.id', '=', 'rd.reward_id')
            ->where('rd.member_account_id', $memberId)
            ->select(
                'rd.id',
                'rw.reward_code',
                'rw.title as reward_title',
                'rd.points_spent',
                'rd.status',
                'rd.voucher_code',
                'rd.fulfilled_at',
                'rd.cancelled_at',
                'rd.created_at'
            )
            ->orderByDesc('rd.id')
            ->limit(50)
            ->get();

        return response()->json($rows);
    }
}
