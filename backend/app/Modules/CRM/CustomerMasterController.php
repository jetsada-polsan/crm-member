<?php

namespace App\Modules\CRM;

use App\Modules\ERPIntegration\SalesRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Unified Customer Master Directory keeping Internal Customer, ERP Mirror,
 * CRM Leads, and Member Portal accounts cleanly separated by verified links.
 */
final class CustomerMasterController
{
    private function user()
    {
        return Auth::guard('staff')->user();
    }

    private function isManager(): bool
    {
        return in_array($this->user()->role, ['admin', 'manager'], true);
    }

    private static function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max, 'UTF-8');
    }

    /**
     * Synchronize a crm_customer_masters record for a CRM Lead without mutating erp_customers.
     */
    public static function syncLeadMaster(int $leadId, ?int $actorStaffId = null): ?object
    {
        $lead = DB::table('crm_leads')->where('id', $leadId)->first();
        if (! $lead) {
            return null;
        }

        $now = now();
        $existing = DB::table('crm_customer_masters')->where('crm_lead_id', $lead->id)->first();
        // The Lead's old converted_erp_customer_id field is never authority to
        // create a link. Only the explicit manager link workflow below may do so.
        $erp = $existing?->erp_customer_id
            ? DB::table('erp_customers')->where('id', $existing->erp_customer_id)->first()
            : null;
        $code = $existing?->internal_customer_code ?? sprintf('POP-LEAD-%06d', $lead->id);

        $payload = [
            'internal_customer_code' => $code,
            'erp_customer_id' => $erp?->id,
            'erp_source_system' => self::clip($erp?->source_system, 32),
            'erp_customer_code' => self::clip($erp?->erp_customer_code, 64),
            'link_status' => $erp ? 'linked' : (in_array($existing?->link_status, ['erp_creation_requested', 'erp_match_pending'], true)
                ? $existing->link_status : ($lead->approval_status === 'rejected' ? 'rejected' : 'unlinked')),
            'verified_at' => $erp ? ($existing?->verified_at ?? $lead->approved_at ?? $now) : null,
            'verified_by' => $erp ? ($existing?->verified_by ?? $lead->approved_by ?? $actorStaffId) : null,
            'display_name' => self::clip($lead->shop_name, 255) ?? 'ร้านค้า',
            'phone' => self::clip($lead->phone, 160),
            'address' => self::clip($lead->address ?? null, 500),
            'shop_type' => self::clip($lead->shop_type, 120),
            'contact_name' => self::clip($lead->contact_name ?? null, 160),
            'province' => self::clip($lead->province ?? null, 160),
            'district' => self::clip($lead->district, 160),
            'assigned_salesperson_code' => self::clip($lead->assigned_salesperson_code ?? null, 80),
            'updated_by' => $actorStaffId ?? $lead->updated_by ?? $lead->owner_staff_id,
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('crm_customer_masters')->where('id', $existing->id)->update($payload);
        } else {
            DB::table('crm_customer_masters')->insert([
                ...$payload,
                'crm_lead_id' => $lead->id,
                'created_source' => 'crm_lead',
                'created_by' => $actorStaffId ?? $lead->created_by ?? $lead->owner_staff_id,
                'created_at' => $lead->created_at ?? $now,
            ]);
        }

        return DB::table('crm_customer_masters')->where('crm_lead_id', $lead->id)->first();
    }

    /**
     * Unified Customer Master list with strict identity separation and RBAC filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();
        $isManager = $this->isManager();
        $likeOp = SalesRules::likeOperator();

        $search = trim((string) $request->query('q', ''));
        $filter = trim((string) $request->query('filter', 'all'));
        $province = trim((string) $request->query('province', ''));
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));

        // Lazily register any recently synced ERP customers into crm_customer_masters in fast batches
        $now = now();
        while (true) {
            $missingErp = DB::table('erp_customers as c')
                ->leftJoin('crm_customer_masters as m', 'm.erp_customer_id', '=', 'c.id')
                ->whereNull('m.id')
                ->orderBy('c.id')
                ->limit(500)
                ->select([
                    'c.id',
                    'c.source_system',
                    'c.erp_customer_code',
                    'c.customer_name',
                    'c.phone',
                    'c.province',
                    'c.salesperson_code',
                    'c.created_at',
                ])
                ->get();

            if ($missingErp->isEmpty()) {
                break;
            }

            $batch = [];
            foreach ($missingErp as $c) {
                $batch[] = [
                    'internal_customer_code' => sprintf('POP-ERP-%06d', $c->id),
                    'erp_customer_id' => $c->id,
                    'erp_source_system' => self::clip($c->source_system, 32),
                    'erp_customer_code' => self::clip($c->erp_customer_code, 64),
                    'link_status' => 'linked',
                    'verified_at' => $c->created_at ?? $now,
                    'display_name' => self::clip($c->customer_name, 255) ?? 'ลูกค้า ERP',
                    'phone' => self::clip($c->phone, 160),
                    'address' => self::clip($c->address ?? null, 500),
                    'province' => self::clip($c->province, 160),
                    'assigned_salesperson_code' => self::clip($c->salesperson_code, 80),
                    'created_source' => 'erp_mirror',
                    'created_at' => $c->created_at ?? $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('crm_customer_masters')->insertOrIgnore($batch);

            if ($missingErp->count() < 500) {
                break;
            }
        }

        $invAgg = SalesRules::customerActualInvoiceSubquery();
        $excAgg = SalesRules::customerExcludedItemsSubquery();

        $q = DB::table('crm_customer_masters as m')
            ->whereNull('m.merged_into_customer_master_id')
            ->leftJoin('erp_customers as ec', 'ec.id', '=', 'm.erp_customer_id')
            ->leftJoin('crm_leads as l', 'l.id', '=', 'm.crm_lead_id')
            ->leftJoin('member_erp_links as ml', function ($join): void {
                $join->on('ml.crm_customer_master_id', '=', 'm.id')
                    ->orOn('ml.erp_customer_id', '=', 'ec.id');
            })
            ->leftJoin('member_accounts as ma', 'ma.id', '=', 'ml.member_account_id')
            ->leftJoinSub($invAgg, 'inv', function ($join): void {
                $join->on('inv.source_system', '=', 'ec.source_system')
                    ->on('inv.erp_customer_code', '=', 'ec.erp_customer_code');
            })
            ->leftJoinSub($excAgg, 'exc', function ($join): void {
                $join->on('exc.source_system', '=', 'ec.source_system')
                    ->on('exc.erp_customer_code', '=', 'ec.erp_customer_code');
            });

        if (! $isManager) {
            $salesCode = $user->salesperson_code ?? '__unassigned__';
            $q->where(function ($w) use ($user, $salesCode): void {
                $w->where('ec.salesperson_code', $salesCode)
                    ->orWhere('l.owner_staff_id', $user->id)
                    ->orWhere('l.assigned_salesperson_code', $salesCode);
            });
        }

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $q->where(function ($w) use ($likeOp, $like): void {
                $w->where('m.internal_customer_code', $likeOp, $like)
                    ->orWhere('m.display_name', $likeOp, $like)
                    ->orWhere('m.phone', $likeOp, $like)
                    ->orWhere('ec.erp_customer_code', $likeOp, $like)
                    ->orWhere('ec.customer_name', $likeOp, $like)
                    ->orWhere('l.shop_name', $likeOp, $like);
            });
        }

        if ($province !== '') {
            $q->where(function ($w) use ($province): void {
                $w->where('ec.province', $province)->orWhere('m.province', $province);
            });
        }

        if ($filter === 'erp_linked') {
            $q->whereNotNull('m.erp_customer_id');
        } elseif ($filter === 'lead_only') {
            $q->whereNull('m.erp_customer_id')->whereNotNull('m.crm_lead_id');
        } elseif ($filter === 'member_verified') {
            $q->where('ml.verification_status', 'verified');
        } elseif ($filter === 'member_pending') {
            $q->where('ml.verification_status', 'pending');
        }

        $total = (clone $q)->count('m.id');
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $rows = $q->select(
            'm.id as customer_master_id',
            'm.internal_customer_code',
            'm.crm_lead_id',
            'm.erp_customer_id',
            'm.link_status',
            'm.verified_at as erp_link_verified_at',
            'm.display_name',
            'm.phone',
            'm.address',
            'm.shop_type',
            'm.contact_name',
            DB::raw('COALESCE(NULLIF(ec.province, \'\'), m.province) as province'),
            'm.district',
            'm.created_source',
            'ec.erp_customer_code',
            'ec.customer_name as erp_customer_name',
            'ec.salesperson_code as erp_salesperson_code',
            'ec.ar_enable',
            'l.pipeline_stage',
            'l.approval_status',
            'l.is_minimal_draft',
            'l.next_follow_up_date',
            'ml.id as member_link_id',
            'ml.verification_status as member_verification_status',
            'ml.verified_at as member_verified_at',
            'ml.erp_member_code',
            'ma.id as member_account_id',
            'ma.name as member_name',
            'ma.phone as member_phone',
            DB::raw('COALESCE(inv.invoice_count, 0) as invoice_count'),
            DB::raw('COALESCE(inv.sale_invoice_count, 0) as sale_invoice_count'),
            DB::raw('COALESCE(inv.return_count, 0) as return_count'),
            DB::raw('COALESCE(inv.gross_purchase_before_vat, 0) as gross_purchase_before_vat'),
            DB::raw('ABS(COALESCE(inv.return_before_vat, 0)) as return_amount_before_vat'),
            DB::raw('COALESCE(inv.actual_before_vat, 0) as net_purchase_before_vat'),
            DB::raw('inv.last_purchase_at as last_purchase_at'),
            DB::raw('COALESCE(inv.actual_before_vat, 0) as total_sales_net'),
            DB::raw('COALESCE(inv.actual_before_vat, 0) - COALESCE(exc.excluded_before_vat, 0) as product_sales_kpi_before_vat'),
            DB::raw('(SELECT COALESCE(SUM(lpl.points_delta), 0) FROM loyalty_points_ledger lpl WHERE lpl.member_account_id = ma.id) as member_points_balance')
        )
            ->orderByDesc('m.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Full separated identity view for a single Customer Master record.
     */
    public function show(int $masterId): JsonResponse
    {
        $requestedMaster = DB::table('crm_customer_masters')->where('id', $masterId)->first();
        $master = $requestedMaster?->merged_into_customer_master_id
            ? DB::table('crm_customer_masters')->where('id', $requestedMaster->merged_into_customer_master_id)->first()
            : $requestedMaster;
        abort_unless($master, 404);

        $user = $this->user();
        $isManager = $this->isManager();
        $erp = $master->erp_customer_id
            ? DB::table('erp_customers')->where('id', $master->erp_customer_id)->first()
            : null;
        $lead = $master->crm_lead_id
            ? DB::table('crm_leads')->where('id', $master->crm_lead_id)->first()
            : null;

        if (! $isManager) {
            $salesCode = $user->salesperson_code ?? '__unassigned__';
            $allowed = ($erp && $erp->salesperson_code === $salesCode)
                || ($lead && ((int) $lead->owner_staff_id === (int) $user->id || $lead->assigned_salesperson_code === $salesCode));
            abort_unless($allowed, 403);
        }

        $memberLink = null;
        if ($erp || $master) {
            $memberLink = DB::table('member_erp_links as l')
                ->join('member_accounts as m', 'm.id', '=', 'l.member_account_id')
                ->where(function ($q) use ($erp, $master): void {
                    $q->where('l.crm_customer_master_id', $master->id);
                    if ($erp) {
                        $q->orWhere('l.erp_customer_id', $erp->id);
                    }
                })
                ->select(
                    'l.id',
                    'l.verification_status',
                    'l.verified_at',
                    'l.erp_member_code',
                    'l.verification_note',
                    'm.id as member_account_id',
                    'm.name as member_name',
                    'm.email as member_email',
                    'm.phone as member_phone',
                    DB::raw("CASE WHEN l.verification_status = 'verified' AND l.verified_at IS NOT NULL AND l.erp_customer_id IS NOT NULL THEN (SELECT COALESCE(SUM(lpl.points_delta), 0) FROM loyalty_points_ledger lpl WHERE lpl.member_account_id = m.id) ELSE NULL END as loyalty_points_balance")
                )
                ->first();
        }

        $locations = DB::table('crm_customer_locations')
            ->where(function ($w) use ($erp, $lead): void {
                if ($erp) {
                    $w->where('erp_customer_id', $erp->id);
                }
                if ($lead) {
                    $w->orWhere('crm_lead_id', $lead->id);
                }
            })
            ->orderByDesc('id')->get();
        $location = $locations->first();

        $visits = DB::table('crm_customer_visits as v')
            ->leftJoin('staff_users as s', 's.id', '=', 'v.staff_user_id')
            ->where(function ($w) use ($erp, $lead, $master): void {
                $w->where('v.customer_master_id', $master->id);
                if ($erp) {
                    $w->orWhere('v.erp_customer_id', $erp->id);
                }
                if ($lead) {
                    $w->orWhere('v.crm_lead_id', $lead->id);
                }
            })
            ->select(
                'v.id',
                'v.visited_at',
                'v.device_recorded_at',
                'v.server_recorded_at',
                'v.gps_permission_status',
                'v.result',
                'v.supplier_name',
                'v.product_types',
                'v.customer_issues',
                'v.customer_feedback',
                'v.follow_up_action',
                'v.checkin_latitude',
                'v.checkin_longitude',
                's.name as staff_name',
                DB::raw('(SELECT COUNT(*) FROM crm_visit_photos vp WHERE vp.visit_id = v.id) as photo_count')
            )
            ->orderByDesc('v.visited_at')
            ->limit(50)
            ->get();

        $salesSummary = null;
        $invoiceHistory = collect();
        if ($erp) {
            $salesSummary = SalesRules::summarizeInvoices(function ($query, string $alias) use ($erp): void {
                $query->where("{$alias}.source_system", $erp->source_system)
                    ->where("{$alias}.erp_customer_code", $erp->erp_customer_code);
            });
            $invoiceQuery = DB::table('erp_sales_invoices as i')
                ->where('i.source_system', $erp->source_system)
                ->where('i.erp_customer_code', $erp->erp_customer_code);
            SalesRules::applyActualSalesScope($invoiceQuery, 'i');
            $invoiceHistory = $invoiceQuery->select(
                'i.id', 'i.branch_code', 'i.doc_type', 'i.dt_properties', 'i.doc_category', 'i.invoice_no',
                'i.sold_at', 'i.before_vat_amount', 'i.gross_amount', 'i.status'
            )->orderByDesc('i.sold_at')->limit(25)->get();
            foreach ($invoiceHistory as $invoice) {
                $invoice->items = DB::table('erp_sales_items')
                    ->where('source_system', $erp->source_system)
                    ->where('branch_code', $invoice->branch_code)
                    ->where('doc_type', $invoice->doc_type)
                    ->where('invoice_no', $invoice->invoice_no)
                    ->select('line_no', 'product_name', 'erp_product_code', 'qty', 'unit_name')
                    ->orderBy('line_no')->get();
            }
        }

        $followUpTasks = DB::table('crm_follow_up_tasks as t')
            ->leftJoin('crm_customer_visits as v', 'v.id', '=', 't.visit_id')
            ->where(function ($q) use ($master, $lead): void {
                $q->where('v.customer_master_id', $master->id);
                if ($lead) {
                    $q->orWhere('t.crm_lead_id', $lead->id);
                }
            })
            ->select('t.id', 't.visit_id', 't.crm_lead_id', 't.title', 't.due_date', 't.status', 't.created_at')
            ->orderByDesc('t.id')->limit(100)->get();

        return response()->json([
            'internal_customer' => $master,
            'requested_internal_customer_id' => (int) $masterId,
            'identity_aliases' => DB::table('crm_customer_masters')->where('merged_into_customer_master_id', $master->id)
                ->select('id', 'internal_customer_code', 'display_name', 'created_at')->orderBy('id')->get(),
            'erp_customer' => $erp,
            'crm_lead' => $lead,
            'member_link' => $memberLink,
            'crm_location' => $location,
            'crm_locations' => $locations,
            'visits' => $visits,
            'follow_up_tasks' => $followUpTasks,
            'sales_summary' => $salesSummary,
            'invoices' => $invoiceHistory,
            'separation_guarantees' => [
                'erp_mirror_is_readonly' => true,
                'erp_sync_preserves_crm_gps_photos_visits' => true,
                'phone_only_never_verifies_member_link' => true,
            ],
        ]);
    }

    /**
     * Explicit Admin/Manager linking between an internal CRM lead and an existing ERP customer mirror.
     */
    public function linkLeadToErp(Request $request, int $leadId): JsonResponse
    {
        abort_unless($this->isManager(), 403);
        $d = $request->validate([
            'erp_customer_id' => ['nullable', 'integer', 'required_without:erp_customer_code', Rule::exists('erp_customers', 'id')],
            'erp_source_system' => ['nullable', 'string', 'max:32', 'required_with:erp_customer_code'],
            'erp_customer_code' => ['nullable', 'string', 'max:64', 'required_without:erp_customer_id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $userId = $this->user()->id;
        $erpCustomerId = isset($d['erp_customer_id']) ? (int) $d['erp_customer_id'] : (int) DB::table('erp_customers')
            ->where('source_system', strtoupper(trim($d['erp_source_system'])))
            ->where('erp_customer_code', trim($d['erp_customer_code']))
            ->value('id');
        abort_unless($erpCustomerId > 0, 404, 'ERP customer code was not found for that source. Sync the ERP mirror first.');

        $master = DB::transaction(function () use ($leadId, $erpCustomerId, $d, $userId) {
            $lead = DB::table('crm_leads')->where('id', $leadId)->lockForUpdate()->first();
            abort_unless($lead, 404);
            $erp = DB::table('erp_customers')->where('id', $erpCustomerId)->lockForUpdate()->first();
            abort_unless($erp, 404);
            $master = DB::table('crm_customer_masters')->where('crm_lead_id', $leadId)->lockForUpdate()->first();
            if (! $master) {
                $master = self::syncLeadMaster($leadId, $userId);
                $master = DB::table('crm_customer_masters')->where('id', $master->id)->lockForUpdate()->first();
            }

            if ($master->erp_customer_id && (int) $master->erp_customer_id !== $erpCustomerId) {
                abort(409, 'Unlink the current ERP account before relinking.');
            }
            $duplicateMaster = DB::table('crm_customer_masters')->where('erp_customer_id', $erpCustomerId)
                ->where('id', '!=', $master->id)->lockForUpdate()->first();
            if ($duplicateMaster && $duplicateMaster->crm_lead_id && (int) $duplicateMaster->crm_lead_id !== $leadId) {
                abort(409, 'ERP customer is already linked to another CRM customer.');
            }

            $masterMemberLink = DB::table('member_erp_links')->where('crm_customer_master_id', $master->id)->lockForUpdate()->first();
            $erpMemberLink = DB::table('member_erp_links')->where('erp_customer_id', $erpCustomerId)->lockForUpdate()->first();
            if ($masterMemberLink && $erpMemberLink && (int) $masterMemberLink->id !== (int) $erpMemberLink->id) {
                abort(409, 'The CRM and ERP identities have separate member links. Review them before linking.');
            }
            if ($erpMemberLink && $erpMemberLink->crm_customer_master_id
                && (int) $erpMemberLink->crm_customer_master_id !== (int) $master->id
                && (int) $erpMemberLink->crm_customer_master_id !== (int) ($duplicateMaster->id ?? 0)) {
                abort(409, 'ERP customer is already connected to a different internal customer.');
            }

            $now = now();
            if ($duplicateMaster) {
                if ($duplicateMaster->merged_into_customer_master_id) {
                    abort(409, 'ERP customer belongs to a merged identity alias. Resolve its active customer link first.');
                }
                // Keep both immutable Internal IDs. The former ERP-only identity becomes a redirecting alias.
                DB::table('crm_customer_visits')->where('customer_master_id', $duplicateMaster->id)
                    ->update(['customer_master_id' => $master->id, 'updated_at' => $now]);
                DB::table('member_verification_requests')->where('crm_customer_master_id', $duplicateMaster->id)
                    ->update(['crm_customer_master_id' => $master->id, 'updated_at' => $now]);
                if ($erpMemberLink && (int) $erpMemberLink->crm_customer_master_id === (int) $duplicateMaster->id) {
                    DB::table('member_erp_links')->where('id', $erpMemberLink->id)->update([
                        'crm_customer_master_id' => $master->id, 'updated_at' => $now,
                    ]);
                }
                DB::table('crm_customer_masters')->where('id', $duplicateMaster->id)->update([
                    'erp_customer_id' => null,
                    'erp_source_system' => null,
                    'erp_customer_code' => null,
                    'crm_lead_id' => null,
                    'link_status' => 'merged',
                    'merged_into_customer_master_id' => $master->id,
                    'updated_by' => $userId,
                    'updated_at' => $now,
                ]);
            }

            DB::table('crm_customer_masters')->where('id', $master->id)->update([
                'erp_customer_id' => $erp->id,
                'erp_source_system' => self::clip($erp->source_system, 32),
                'erp_customer_code' => self::clip($erp->erp_customer_code, 64),
                'link_status' => 'linked',
                'verified_at' => $master->verified_at ?? $now,
                'verified_by' => $master->verified_by ?? $userId,
                'updated_by' => $userId,
                'updated_at' => $now,
            ]);
            DB::table('crm_leads')->where('id', $leadId)->update([
                'converted_erp_customer_id' => $erp->id,
                'updated_by' => $userId,
                'updated_at' => $now,
            ]);

            $memberLink = DB::table('member_erp_links')->where('crm_customer_master_id', $master->id)->lockForUpdate()->first();
            if ($memberLink && ! $memberLink->erp_customer_id) {
                DB::table('member_erp_links')->where('id', $memberLink->id)->update(['erp_customer_id' => $erp->id, 'updated_at' => $now]);
            } elseif ($erpMemberLink && (int) $erpMemberLink->crm_customer_master_id !== (int) $master->id) {
                DB::table('member_erp_links')->where('id', $erpMemberLink->id)->update(['crm_customer_master_id' => $master->id, 'updated_at' => $now]);
            }

            $verifiedMemberLink = DB::table('member_erp_links')->where('crm_customer_master_id', $master->id)
                ->where('verification_status', 'verified')->whereNotNull('verified_at')->first();
            if ($verifiedMemberLink) {
                \App\Modules\Loyalty\InvoicePointsSynchronizer::syncCustomer($erp->source_system, $erp->erp_customer_code, $now);
            }

            DB::table('crm_audit_logs')->insert([
                'entity_type' => 'crm_customer_master',
                'entity_id' => (int) $master->id,
                'action' => $master->erp_customer_id ? 'erp_relinked' : 'erp_linked',
                'actor_staff_id' => $userId,
                'changes' => json_encode([
                    'crm_lead_id' => $leadId,
                    'previous_erp_customer_id' => $master->erp_customer_id,
                    'erp_customer_id' => $erp->id,
                    'erp_source_system' => $erp->source_system,
                    'erp_customer_code' => $erp->erp_customer_code,
                    'merged_master_id' => $duplicateMaster->id ?? null,
                    'note' => $d['note'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
            DB::table('customer_match_candidates')->where('crm_lead_id', $leadId)->where('erp_customer_id', $erp->id)->update([
                'status' => 'accepted', 'reviewed_by' => $userId, 'reviewed_at' => $now,
                'review_note' => $d['note'] ?? null, 'updated_at' => $now,
            ]);
            DB::table('customer_match_candidates')->where('crm_lead_id', $leadId)->where('erp_customer_id', '!=', $erp->id)->where('status', 'pending')->update([
                'status' => 'superseded', 'reviewed_by' => $userId, 'reviewed_at' => $now, 'updated_at' => $now,
            ]);

            return DB::table('crm_customer_masters')->where('id', $master->id)->first();
        });

        return response()->json(['ok' => true, 'customer_master' => $master]);
    }

    public function requestErpCreation(Request $request, int $leadId): JsonResponse
    {
        $lead = DB::table('crm_leads')->where('id', $leadId)->first();
        abort_unless($lead, 404);
        $user = $this->user();
        if (! $this->isManager()) {
            abort_unless((int) $lead->owner_staff_id === (int) $user->id
                || ($user->salesperson_code && $lead->assigned_salesperson_code === $user->salesperson_code), 403);
        }
        $d = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        if ($lead->converted_erp_customer_id) {
            return response()->json(['error' => 'CRM customer is already linked to ERP'], 409);
        }
        $currentStatus = DB::table('crm_customer_masters')->where('crm_lead_id', $leadId)->value('link_status');
        if ($currentStatus === 'erp_creation_requested') {
            return response()->json(['ok' => true, 'status' => $currentStatus, 'already_requested' => true]);
        }
        if ($currentStatus === 'erp_match_pending') {
            return response()->json(['error' => 'ERP match candidates are already pending review'], 409);
        }
        $now = now();
        DB::transaction(function () use ($leadId, $d, $user, $now): void {
            DB::table('crm_customer_masters')->where('crm_lead_id', $leadId)->update([
                'link_status' => 'erp_creation_requested', 'updated_by' => $user->id, 'updated_at' => $now,
            ]);
            DB::table('crm_audit_logs')->insert([
                'entity_type' => 'crm_lead', 'entity_id' => $leadId, 'action' => 'erp_creation_requested',
                'actor_staff_id' => $user->id,
                'changes' => json_encode(['note' => $d['note'] ?? null], JSON_UNESCAPED_UNICODE), 'created_at' => $now,
            ]);
        });
        return response()->json(['ok' => true, 'status' => 'erp_creation_requested']);
    }

    public function erpCreationRequests(): JsonResponse
    {
        abort_unless($this->isManager(), 403);
        $rows = DB::table('crm_leads as l')
            ->leftJoin('crm_customer_masters as m', 'm.crm_lead_id', '=', 'l.id')
            ->leftJoin('staff_users as s', 's.id', '=', 'l.owner_staff_id')
            ->where('m.link_status', 'erp_creation_requested')->whereNull('m.erp_customer_id')
            ->select('l.id as crm_lead_id', 'm.id as customer_master_id', 'm.internal_customer_code', 'l.shop_name', 'l.phone', 'l.address', 'l.shop_type', 'l.province', 'l.district', 'l.created_at', 's.name as requested_by')
            ->orderBy('l.created_at')->limit(500)->get();
        return response()->json($rows);
    }

    public function refreshMatchCandidates(): JsonResponse
    {
        abort_unless($this->isManager(), 403);
        $now = now();
        DB::table('customer_match_candidates')->where('status', 'pending')->update(['status' => 'stale', 'updated_at' => $now]);
        $leads = DB::table('crm_leads as l')
            ->join('crm_customer_masters as m', 'm.crm_lead_id', '=', 'l.id')
            ->whereNull('l.converted_erp_customer_id')->whereNull('m.erp_customer_id')
            ->select('l.id', 'l.shop_name', 'l.phone', 'l.address', 'l.province', 'l.district')
            ->orderBy('l.id')->limit(1000)->get();
        $erps = DB::table('erp_customers as e')
            ->leftJoin('crm_customer_masters as m', 'm.erp_customer_id', '=', 'e.id')
            ->where(function ($q): void { $q->whereNull('m.crm_lead_id')->orWhereNull('m.id'); })
            ->select('e.id', 'e.source_system', 'e.erp_customer_code', 'e.customer_name', 'e.phone', 'e.address', 'e.province')
            ->orderBy('e.id')->limit(10000)->get();
        $erpById = [];
        $erpNameGramIndex = [];
        $erpPhoneIndex = [];
        foreach ($erps as $erp) {
            $erpById[$erp->id] = $erp;
            $phone = self::normalizedPhone($erp->phone);
            if ($phone !== '' && strlen($phone) >= 8) {
                $erpPhoneIndex[$phone][] = $erp->id;
            }
            foreach (self::textGrams($erp->customer_name) as $gram) {
                $erpNameGramIndex[$gram][] = $erp->id;
            }
        }
        $count = 0;
        foreach ($leads as $lead) {
            $candidateIds = [];
            $leadPhone = self::normalizedPhone($lead->phone);
            if ($leadPhone !== '' && strlen($leadPhone) >= 8) {
                foreach ($erpPhoneIndex[$leadPhone] ?? [] as $erpId) {
                    $candidateIds[$erpId] = true;
                }
            }
            foreach (self::textGrams($lead->shop_name) as $gram) {
                foreach ($erpNameGramIndex[$gram] ?? [] as $erpId) {
                    $candidateIds[$erpId] = true;
                }
            }
            foreach (array_keys($candidateIds) as $erpId) {
                $erp = $erpById[$erpId];
                $nameScore = self::textSimilarity($lead->shop_name, $erp->customer_name);
                $addressScore = self::textSimilarity($lead->address, $erp->address);
                $phoneMatch = $leadPhone !== '' && strlen($leadPhone) >= 8 && $leadPhone === self::normalizedPhone($erp->phone);
                $sameProvince = self::normalizedText($lead->province) !== '' && self::normalizedText($lead->province) === self::normalizedText($erp->province);
                $score = ($phoneMatch ? 60 : 0) + (int) round($nameScore * 45) + (int) round($addressScore * 20) + ($sameProvince ? 8 : 0);
                if ($score < 30) {
                    continue;
                }
                $reasons = [
                    'phone_match' => $phoneMatch, 'name_similarity' => round($nameScore, 3),
                    'address_similarity' => round($addressScore, 3), 'same_province' => $sameProvince,
                    'requires_admin_confirmation' => true,
                ];
                $existing = DB::table('customer_match_candidates')->where('crm_lead_id', $lead->id)->where('erp_customer_id', $erp->id)->first();
                if ($existing && ! in_array($existing->status, ['pending', 'stale'], true)) {
                    continue;
                }
                DB::table('customer_match_candidates')->updateOrInsert(
                    ['crm_lead_id' => $lead->id, 'erp_customer_id' => $erp->id],
                    ['score' => min(100, $score), 'match_reasons' => json_encode($reasons),
                        'status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null,
                        'updated_at' => $now, 'created_at' => $existing->created_at ?? $now]
                );
                DB::table('crm_customer_masters')->where('crm_lead_id', $lead->id)->whereNull('erp_customer_id')->update([
                    'link_status' => 'erp_match_pending', 'updated_at' => $now,
                ]);
                $count++;
            }
        }
        return response()->json(['ok' => true, 'candidates_refreshed' => $count, 'requires_admin_confirmation' => true]);
    }

    public function matchCandidates(Request $request): JsonResponse
    {
        abort_unless($this->isManager(), 403);
        $status = (string) $request->query('status', 'pending');
        $status = in_array($status, ['pending', 'stale', 'accepted', 'dismissed', 'superseded'], true) ? $status : 'pending';
        $rows = DB::table('customer_match_candidates as mc')
            ->join('crm_leads as l', 'l.id', '=', 'mc.crm_lead_id')
            ->join('erp_customers as e', 'e.id', '=', 'mc.erp_customer_id')
            ->leftJoin('staff_users as s', 's.id', '=', 'mc.reviewed_by')
            ->where('mc.status', $status)
            ->select('mc.id', 'mc.crm_lead_id', 'mc.erp_customer_id', 'mc.score', 'mc.match_reasons', 'mc.status', 'mc.review_note', 'mc.reviewed_at',
                'l.shop_name as crm_shop_name', 'l.phone as crm_phone', 'l.address as crm_address',
                'e.source_system', 'e.erp_customer_code', 'e.customer_name as erp_customer_name', 'e.phone as erp_phone', 'e.address as erp_address',
                's.name as reviewed_by_name')
            ->orderByDesc('mc.score')->orderBy('mc.id')->limit(500)->get();
        foreach ($rows as $row) {
            if (is_string($row->match_reasons)) {
                $row->match_reasons = json_decode($row->match_reasons, true) ?: [];
            }
        }
        return response()->json($rows);
    }

    public function confirmMatchCandidate(Request $request, int $candidateId): JsonResponse
    {
        abort_unless($this->isManager(), 403);
        $candidate = DB::table('customer_match_candidates')->where('id', $candidateId)->first();
        abort_unless($candidate, 404);
        abort_unless($candidate->status === 'pending', 409, 'Candidate is no longer pending review.');
        $request->merge(['erp_customer_id' => $candidate->erp_customer_id]);
        return $this->linkLeadToErp($request, (int) $candidate->crm_lead_id);
    }

    public function dismissMatchCandidate(Request $request, int $candidateId): JsonResponse
    {
        abort_unless($this->isManager(), 403);
        $d = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $candidate = DB::table('customer_match_candidates')->where('id', $candidateId)->first();
        abort_unless($candidate, 404);
        if ($candidate->status !== 'pending') {
            return response()->json(['error' => 'Candidate is no longer pending review'], 409);
        }
        $now = now();
        DB::transaction(function () use ($candidate, $d, $now): void {
            DB::table('customer_match_candidates')->where('id', $candidate->id)->update([
                'status' => 'dismissed', 'reviewed_by' => $this->user()->id, 'reviewed_at' => $now,
                'review_note' => $d['note'] ?? null, 'updated_at' => $now,
            ]);
            $masterId = DB::table('crm_customer_masters')->where('crm_lead_id', $candidate->crm_lead_id)->value('id');
            DB::table('crm_audit_logs')->insert([
                'entity_type' => 'crm_customer_master', 'entity_id' => $masterId,
                'action' => 'erp_match_candidate_dismissed', 'actor_staff_id' => $this->user()->id,
                'changes' => json_encode(['candidate_id' => $candidate->id, 'erp_customer_id' => $candidate->erp_customer_id, 'note' => $d['note'] ?? null], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        });
        return response()->json(['ok' => true, 'status' => 'dismissed']);
    }

    public function unlinkErp(Request $request, int $masterId): JsonResponse
    {
        abort_unless($this->isManager(), 403);
        $d = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $master = DB::table('crm_customer_masters')->where('id', $masterId)->first();
        abort_unless($master, 404);
        if (! $master->erp_customer_id) {
            return response()->json(['ok' => true, 'status' => $master->link_status]);
        }
        DB::transaction(function () use ($master, $d): void {
            $now = now();
            DB::table('crm_customer_masters')->where('id', $master->id)->update([
                'erp_customer_id' => null, 'erp_source_system' => null, 'erp_customer_code' => null,
                'link_status' => 'erp_match_pending', 'verified_at' => null, 'verified_by' => null, 'updated_at' => $now,
            ]);
            if ($master->crm_lead_id) {
                DB::table('crm_leads')->where('id', $master->crm_lead_id)->update([
                    'converted_erp_customer_id' => null, 'updated_at' => $now,
                ]);
            }
            DB::table('member_erp_links')->where('crm_customer_master_id', $master->id)->update([
                'erp_customer_id' => null, 'erp_member_code' => null, 'verification_status' => 'pending',
                'verified_at' => null, 'verified_by' => null,
                'verification_note' => 'ERP link changed; ownership must be reviewed again.', 'updated_at' => $now,
            ]);
            DB::table('crm_audit_logs')->insert([
                'entity_type' => 'crm_customer_master', 'entity_id' => $master->id, 'action' => 'erp_unlinked',
                'actor_staff_id' => $this->user()->id,
                'changes' => json_encode([
                    'previous_erp_customer_id' => $master->erp_customer_id,
                    'previous_erp_source_system' => $master->erp_source_system,
                    'previous_erp_customer_code' => $master->erp_customer_code,
                    'note' => $d['note'],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        });
        return response()->json(['ok' => true, 'status' => 'erp_match_pending']);
    }

    public function auditHistory(int $masterId): JsonResponse
    {
        abort_unless($this->isManager(), 403);
        $master = DB::table('crm_customer_masters')->where('id', $masterId)->first();
        abort_unless($master, 404);
        $identityIds = DB::table('crm_customer_masters')->where('merged_into_customer_master_id', $master->id)->pluck('id')->all();
        $identityIds[] = $master->id;
        $rows = DB::table('crm_audit_logs as a')->leftJoin('staff_users as s', 's.id', '=', 'a.actor_staff_id')
            ->where(function ($q) use ($master, $identityIds): void {
                $q->where(function ($inner) use ($master, $identityIds): void { $inner->where('a.entity_type', 'crm_customer_master')->whereIn('a.entity_id', $identityIds); });
                if ($master->crm_lead_id) {
                    $q->orWhere(function ($inner) use ($master): void { $inner->where('a.entity_type', 'crm_lead')->where('a.entity_id', $master->crm_lead_id); });
                }
            })
            ->select('a.id', 'a.entity_type', 'a.entity_id', 'a.action', 'a.changes', 'a.created_at', 's.name as actor_name')
            ->orderByDesc('a.id')->limit(250)->get();
        return response()->json($rows);
    }

    private static function normalizedPhone(?string $value): string
    {
        return preg_replace('/\\D+/', '', (string) $value) ?? '';
    }

    private static function normalizedText(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value), 'UTF-8');
        return preg_replace('/[\\p{Z}\\p{P}\\p{S}\\s]+/u', '', $value) ?? '';
    }

    private static function textSimilarity(?string $left, ?string $right): float
    {
        $left = self::normalizedText($left);
        $right = self::normalizedText($right);
        if ($left === '' || $right === '') {
            return 0.0;
        }
        if ($left === $right) {
            return 1.0;
        }
        $leftChars = mb_str_split($left, 1, 'UTF-8');
        $rightChars = mb_str_split($right, 1, 'UTF-8');
        $leftPairs = count($leftChars) < 2 ? $leftChars : array_unique(array_map(fn ($i) => implode('', array_slice($leftChars, $i, 2)), range(0, count($leftChars) - 2)));
        $rightPairs = count($rightChars) < 2 ? $rightChars : array_unique(array_map(fn ($i) => implode('', array_slice($rightChars, $i, 2)), range(0, count($rightChars) - 2)));
        if ($leftPairs === [] || $rightPairs === []) {
            return 0.0;
        }
        return count(array_intersect($leftPairs, $rightPairs)) / count(array_unique(array_merge($leftPairs, $rightPairs)));
    }

    /** @return list<string> */
    private static function textGrams(?string $value): array
    {
        $chars = mb_str_split(self::normalizedText($value), 1, 'UTF-8');
        if ($chars === []) {
            return [];
        }
        if (count($chars) < 2) {
            return $chars;
        }
        $grams = [];
        for ($i = 0, $length = count($chars) - 1; $i < $length; $i++) {
            $grams[] = $chars[$i].$chars[$i + 1];
        }
        return array_values(array_unique($grams));
    }
}
