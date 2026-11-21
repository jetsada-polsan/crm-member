<?php

namespace App\Modules\LINE;

use App\Modules\CRM\CustomerAccess;
use App\Modules\ERPIntegration\SalesRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * CRM Web Inbox for LINE Official Account (LINE OA) customer conversations.
 * Supports live incoming/outgoing LINE Messaging API chat, direct Bplus AR Debtor
 * binding, Customer 360 quick summary, and local simulation testing before tokens are set.
 */
final class LineChatController
{
    public function threads(Request $request): JsonResponse
    {
        $user = Auth::guard('staff')->user();
        abort_unless($user, 401);

        $search = trim((string) $request->query('q', ''));
        $filter = trim((string) $request->query('filter', 'all')); // all | open | resolved | unlinked | unread
        $likeOp = SalesRules::likeOperator();

        $q = DB::table('line_chat_threads as t')
            ->leftJoin('erp_customers as c', 'c.id', '=', 't.erp_customer_id')
            ->leftJoin('member_accounts as m', 'm.id', '=', 't.member_account_id')
            ->leftJoin('staff_users as st', 'st.id', '=', 't.assigned_staff_id');

        if ($filter === 'open') {
            $q->where('t.status', 'open');
        } elseif ($filter === 'resolved') {
            $q->where('t.status', 'resolved');
        } elseif ($filter === 'unlinked') {
            $q->whereNull('t.erp_customer_id');
        } elseif ($filter === 'unread') {
            $q->where('t.unread_count', '>', 0);
        }

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $q->where(function ($w) use ($likeOp, $like): void {
                $w->where('t.display_name', $likeOp, $like)
                    ->orWhere('t.line_user_id', $likeOp, $like)
                    ->orWhere('t.last_message_preview', $likeOp, $like)
                    ->orWhere('c.erp_customer_code', $likeOp, $like)
                    ->orWhere('c.customer_name', $likeOp, $like)
                    ->orWhere('c.phone', $likeOp, $like)
                    ->orWhere('m.name', $likeOp, $like);
            });
        }

        $threads = $q->select(
            't.id',
            't.line_user_id',
            't.display_name',
            't.picture_url',
            't.erp_customer_id',
            't.member_account_id',
            't.assigned_staff_id',
            't.status',
            't.unread_count',
            't.last_message_preview',
            't.last_message_at',
            't.notes',
            't.updated_at',
            'c.erp_customer_code',
            'c.customer_name as erp_customer_name',
            'c.phone as erp_phone',
            'c.province as erp_province',
            'c.salesperson_name as erp_salesperson_name',
            'm.name as member_name',
            'm.phone as member_phone',
            'st.name as assigned_staff_name',
            DB::raw('(SELECT COALESCE(SUM(lpl.points_delta), 0) FROM loyalty_points_ledger lpl WHERE lpl.member_account_id = t.member_account_id) as points_balance')
        )
            ->orderByDesc('t.last_message_at')
            ->orderByDesc('t.id')
            ->limit(150)
            ->get();

        $summary = DB::table('line_chat_threads')->selectRaw("
            COUNT(*) as total_threads,
            COALESCE(SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END), 0) as open_threads,
            COALESCE(SUM(unread_count), 0) as total_unread,
            COALESCE(SUM(CASE WHEN erp_customer_id IS NULL THEN 1 ELSE 0 END), 0) as unlinked_threads
        ")->first();

        $lineSecret = LineConfig::channelSecret();
        $lineToken = LineConfig::channelAccessToken();
        $lineRaw = LineConfig::allRaw();

        return response()->json([
            'line_status' => [
                'configured' => $lineSecret !== '' && $lineToken !== '',
                'access_token_configured' => $lineToken !== '',
                'secret_configured' => $lineSecret !== '',
                'secret_masked' => LineConfig::maskSecret($lineSecret),
                'access_token_masked' => LineConfig::maskSecret($lineToken),
                'line_channel_id' => $lineRaw['line_channel_id'] ?? '',
                'bot_basic_id' => $lineRaw['line_bot_basic_id'] ?? '',
                'line_liff_id' => $lineRaw['line_liff_id'] ?? '',
                'line_welcome_message' => LineConfig::welcomeMessage(),
                'webhook_path' => '/api/line/webhook',
            ],
            'summary' => [
                'total_threads' => (int) ($summary->total_threads ?? 0),
                'open_threads' => (int) ($summary->open_threads ?? 0),
                'total_unread' => (int) ($summary->total_unread ?? 0),
                'unlinked_threads' => (int) ($summary->unlinked_threads ?? 0),
            ],
            'threads' => $threads,
        ]);
    }

    public function messages(int $threadId): JsonResponse
    {
        $user = Auth::guard('staff')->user();
        abort_unless($user, 401);

        $thread = DB::table('line_chat_threads as t')
            ->leftJoin('erp_customers as c', 'c.id', '=', 't.erp_customer_id')
            ->leftJoin('crm_customer_masters as cm', 'cm.erp_customer_id', '=', 'c.id')
            ->leftJoin('member_accounts as m', 'm.id', '=', 't.member_account_id')
            ->leftJoin('staff_users as st', 'st.id', '=', 't.assigned_staff_id')
            ->where('t.id', $threadId)
            ->select(
                't.*',
                'c.source_system as erp_source_system',
                'c.erp_customer_code',
                'c.customer_name as erp_customer_name',
                'c.phone as erp_phone',
                'c.province as erp_province',
                'c.address as erp_address',
                'c.credit_limit as erp_credit_limit',
                'c.salesperson_code as erp_salesperson_code',
                'c.salesperson_name as erp_salesperson_name',
                'cm.id as customer_master_id',
                'cm.internal_customer_code',
                'm.name as member_name',
                'm.phone as member_phone',
                'm.email as member_email',
                'st.name as assigned_staff_name'
            )
            ->first();

        abort_unless($thread, 404, 'Chat thread not found');

        if ((int) $thread->unread_count > 0 && ! CustomerAccess::isReadOnlyExecutive($user)) {
            DB::table('line_chat_threads')->where('id', $threadId)->update([
                'unread_count' => 0,
                'updated_at' => now(),
            ]);
            $thread->unread_count = 0;
        }

        $messages = DB::table('line_chat_messages as msg')
            ->leftJoin('staff_users as s', 's.id', '=', 'msg.sender_staff_id')
            ->where('msg.thread_id', $threadId)
            ->select(
                'msg.id',
                'msg.thread_id',
                'msg.line_message_id',
                'msg.direction',
                'msg.sender_staff_id',
                's.name as sender_staff_name',
                'msg.message_type',
                'msg.body',
                'msg.delivery_status',
                'msg.delivery_error',
                'msg.created_at'
            )
            ->orderBy('msg.id')
            ->limit(250)
            ->get();

        $customerSnapshot = null;
        if ($thread->erp_customer_id && $thread->erp_customer_code) {
            $source = $thread->erp_source_system ?: 'BPLUS';
            $code = (string) $thread->erp_customer_code;
            $invAgg = SalesRules::customerActualInvoiceSubquery([$code])
                ->where('inv.source_system', $source)
                ->first();
            $recentInvoices = SalesRules::applyActualSalesScope(
                DB::table('erp_sales_invoices')
                    ->where('source_system', $source)
                    ->where('erp_customer_code', $code)
            )
                ->select('doc_type', 'invoice_no', 'sold_at', 'doc_category', DB::raw('COALESCE(before_vat_amount, net_amount) as before_vat_amount'))
                ->orderByDesc('sold_at')
                ->limit(5)
                ->get();

            $pointsBalance = $thread->member_account_id
                ? (int) DB::table('loyalty_points_ledger')->where('member_account_id', $thread->member_account_id)->sum('points_delta')
                : 0;

            $customerSnapshot = [
                'erp_customer_id' => (int) $thread->erp_customer_id,
                'customer_master_id' => $thread->customer_master_id ? (int) $thread->customer_master_id : null,
                'internal_customer_code' => $thread->internal_customer_code,
                'erp_customer_code' => $code,
                'customer_name' => $thread->erp_customer_name,
                'phone' => $thread->erp_phone,
                'province' => $thread->erp_province,
                'address' => $thread->erp_address,
                'credit_limit' => $thread->erp_credit_limit,
                'salesperson_name' => $thread->erp_salesperson_name,
                'sale_invoice_count' => (int) ($invAgg->sale_invoice_count ?? 0),
                'net_sales_before_vat' => (float) ($invAgg->actual_before_vat ?? 0),
                'last_purchase_at' => $invAgg->last_purchase_at ?? null,
                'points_balance' => $pointsBalance,
                'recent_invoices' => $recentInvoices,
            ];
        }

        return response()->json([
            'thread' => $thread,
            'messages' => $messages,
            'customer_snapshot' => $customerSnapshot,
        ]);
    }

    public function reply(Request $request, int $threadId): JsonResponse
    {
        $user = Auth::guard('staff')->user();
        abort_unless($user, 401);
        abort_if(CustomerAccess::isReadOnlyExecutive($user), 403, 'Executive role is read-only');

        $d = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $body = trim($d['body']);
        abort_if($body === '', 422, 'กรุณาพิมพ์ข้อความก่อนส่ง');

        $thread = DB::table('line_chat_threads')->where('id', $threadId)->first();
        abort_unless($thread, 404, 'Chat thread not found');

        $accessToken = LineConfig::channelAccessToken();
        $isSimulatedUser = str_starts_with((string) $thread->line_user_id, 'DEMO_');
        $deliveryStatus = 'simulated';
        $deliveryError = null;

        if ($accessToken !== '' && ! $isSimulatedUser) {
            try {
                $res = Http::withToken($accessToken)
                    ->acceptJson()
                    ->timeout(8)
                    ->post('https://api.line.me/v2/bot/message/push', [
                        'to' => $thread->line_user_id,
                        'messages' => [[
                            'type' => 'text',
                            'text' => $body,
                        ]],
                    ]);
                if ($res->successful()) {
                    $deliveryStatus = 'sent';
                } else {
                    $deliveryStatus = 'failed';
                    $deliveryError = 'LINE HTTP '.$res->status().': '.mb_substr((string) $res->body(), 0, 260);
                }
            } catch (Throwable $e) {
                $deliveryStatus = 'failed';
                $deliveryError = mb_substr($e->getMessage(), 0, 260);
            }
        }

        $now = now();
        $msgId = DB::table('line_chat_messages')->insertGetId([
            'thread_id' => $threadId,
            'direction' => 'outgoing',
            'sender_staff_id' => $user->id,
            'message_type' => 'text',
            'body' => $body,
            'delivery_status' => $deliveryStatus,
            'delivery_error' => $deliveryError,
            'created_at' => $now,
        ]);

        DB::table('line_chat_threads')->where('id', $threadId)->update([
            'assigned_staff_id' => $thread->assigned_staff_id ?: $user->id,
            'unread_count' => 0,
            'last_message_preview' => mb_substr('เรา: '.$body, 0, 280),
            'last_message_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'ok' => $deliveryStatus !== 'failed',
            'id' => $msgId,
            'delivery_status' => $deliveryStatus,
            'delivery_error' => $deliveryError,
        ], 201);
    }

    public function linkCustomer(Request $request, int $threadId): JsonResponse
    {
        $user = Auth::guard('staff')->user();
        abort_unless($user, 401);
        abort_if(CustomerAccess::isReadOnlyExecutive($user), 403, 'Executive role is read-only');

        $d = $request->validate([
            'erp_customer_id' => ['nullable', 'integer', 'exists:erp_customers,id'],
        ]);

        $thread = DB::table('line_chat_threads')->where('id', $threadId)->first();
        abort_unless($thread, 404, 'Chat thread not found');

        $erpId = ! empty($d['erp_customer_id']) ? (int) $d['erp_customer_id'] : null;
        $now = now();

        $memberId = null;
        $customerLabel = null;

        if ($erpId !== null) {
            $customer = DB::table('erp_customers')->where('id', $erpId)->first();
            abort_unless($customer, 404, 'ERP customer not found');
            $customerLabel = trim(($customer->erp_customer_code ?? '').' · '.($customer->customer_name ?? ''));

            // If this ERP customer already has a verified member account, unify LINE <-> Member <-> ERP
            $memberLink = DB::table('member_erp_links')
                ->where('erp_customer_id', $erpId)
                ->where('verification_status', 'verified')
                ->first();
            if ($memberLink) {
                $memberId = (int) $memberLink->member_account_id;
                $existsLine = DB::table('member_line_accounts')->where('line_user_id', $thread->line_user_id)->exists();
                DB::table('member_line_accounts')->updateOrInsert(
                    ['line_user_id' => $thread->line_user_id],
                    array_merge($existsLine ? [] : ['created_at' => $now], [
                        'member_account_id' => $memberId,
                        'line_display_name' => $thread->display_name,
                        'binding_status' => 'verified',
                        'verified_at' => $now,
                        'revoked_at' => null,
                        'last_webhook_at' => $now,
                        'updated_at' => $now,
                    ])
                );
            }
        }

        DB::table('line_chat_threads')->where('id', $threadId)->update([
            'erp_customer_id' => $erpId,
            'member_account_id' => $memberId ?? ($erpId === null ? null : $thread->member_account_id),
            'updated_at' => $now,
        ]);

        DB::table('line_chat_messages')->insert([
            'thread_id' => $threadId,
            'direction' => 'system',
            'sender_staff_id' => $user->id,
            'message_type' => 'text',
            'body' => $erpId !== null
                ? "ผูกแชทนี้กับลูกหนี้ Bplus: {$customerLabel} โดย {$user->name}"
                : "ยกเลิกการผูกแชทกับลูกหนี้ Bplus โดย {$user->name}",
            'delivery_status' => 'received',
            'created_at' => $now,
        ]);

        DB::table('crm_audit_logs')->insert([
            'entity_type' => 'line_chat_thread',
            'entity_id' => $threadId,
            'action' => $erpId !== null ? 'linked_erp_customer' : 'unlinked_erp_customer',
            'actor_staff_id' => $user->id,
            'changes' => json_encode([
                'line_user_id' => $thread->line_user_id,
                'erp_customer_id' => $erpId,
                'member_account_id' => $memberId,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
        ]);

        return response()->json([
            'ok' => true,
            'erp_customer_id' => $erpId,
            'member_account_id' => $memberId,
        ]);
    }

    public function updateThread(Request $request, int $threadId): JsonResponse
    {
        $user = Auth::guard('staff')->user();
        abort_unless($user, 401);
        abort_if(CustomerAccess::isReadOnlyExecutive($user), 403, 'Executive role is read-only');

        $d = $request->validate([
            'status' => ['sometimes', 'in:open,resolved'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'assigned_staff_id' => ['sometimes', 'nullable', 'integer', 'exists:staff_users,id'],
        ]);

        $thread = DB::table('line_chat_threads')->where('id', $threadId)->first();
        abort_unless($thread, 404, 'Chat thread not found');

        $updates = ['updated_at' => now()];
        if (array_key_exists('status', $d)) {
            $updates['status'] = $d['status'];
        }
        if (array_key_exists('notes', $d)) {
            $updates['notes'] = $d['notes'];
        }
        if (array_key_exists('assigned_staff_id', $d)) {
            $updates['assigned_staff_id'] = $d['assigned_staff_id'];
        }

        DB::table('line_chat_threads')->where('id', $threadId)->update($updates);

        return response()->json(['ok' => true]);
    }

    public function simulateIncoming(Request $request): JsonResponse
    {
        $user = Auth::guard('staff')->user();
        abort_unless($user && in_array($user->role, ['admin', 'manager'], true), 403, 'Admin or manager role required');

        $d = $request->validate([
            'display_name' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:1500'],
            'line_user_id' => ['nullable', 'string', 'max:120'],
            'erp_customer_id' => ['nullable', 'integer', 'exists:erp_customers,id'],
        ]);

        $now = now();
        $lineUserId = isset($d['line_user_id']) && trim((string) $d['line_user_id']) !== ''
            ? trim((string) $d['line_user_id'])
            : ('DEMO_'.substr(md5(strtolower(trim($d['display_name']))), 0, 12));

        $erpId = ! empty($d['erp_customer_id']) ? (int) $d['erp_customer_id'] : null;
        $thread = DB::table('line_chat_threads')->where('line_user_id', $lineUserId)->first();
        if (! $thread) {
            $threadId = DB::table('line_chat_threads')->insertGetId([
                'line_user_id' => $lineUserId,
                'display_name' => trim($d['display_name']),
                'erp_customer_id' => $erpId,
                'status' => 'open',
                'unread_count' => 1,
                'last_message_preview' => mb_substr(trim($d['body']), 0, 280),
                'last_message_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $threadId = (int) $thread->id;
            DB::table('line_chat_threads')->where('id', $threadId)->update([
                'display_name' => trim($d['display_name']),
                'erp_customer_id' => $erpId ?? $thread->erp_customer_id,
                'status' => 'open',
                'unread_count' => (int) $thread->unread_count + 1,
                'last_message_preview' => mb_substr(trim($d['body']), 0, 280),
                'last_message_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $msgId = DB::table('line_chat_messages')->insertGetId([
            'thread_id' => $threadId,
            'direction' => 'incoming',
            'message_type' => 'text',
            'body' => trim($d['body']),
            'delivery_status' => 'received',
            'created_at' => $now,
        ]);

        return response()->json([
            'ok' => true,
            'thread_id' => $threadId,
            'message_id' => $msgId,
        ], 201);
    }
}
