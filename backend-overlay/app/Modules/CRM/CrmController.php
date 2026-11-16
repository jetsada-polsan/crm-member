<?php

namespace App\Modules\CRM;

use App\Modules\ERPIntegration\SalesRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class CrmController
{
    private function accessibleCustomers(string $alias = 'erp_customers')
    {
        $user = Auth::guard('staff')->user();
        $q = DB::table('erp_customers as '.$alias);
        if (! in_array($user->role, ['admin', 'manager'], true)) {
            // If salesperson_code is NULL, this intentionally returns no data.
            $q->where($alias.'.salesperson_code', $user->salesperson_code ?? '__unassigned__');
        }

        return $q;
    }

    private function salesScopeFilter(object $user, bool $isManager): ?callable
    {
        if ($isManager) {
            return null;
        }

        return function ($query, string $invAlias) use ($user): void {
            $query->join('erp_customers as sc', function ($join) use ($invAlias): void {
                $join->on('sc.source_system', '=', "{$invAlias}.source_system")
                    ->on('sc.erp_customer_code', '=', "{$invAlias}.erp_customer_code");
            })->where('sc.salesperson_code', $user->salesperson_code ?? '__unassigned__');
        };
    }

    public function salespersons(): JsonResponse
    {
        $rows = $this->accessibleCustomers('c')
            ->leftJoin('erp_salespersons as sp', function ($join): void {
                $join->on('sp.source_system', '=', 'c.source_system')
                    ->on('sp.salesperson_code', '=', 'c.salesperson_code');
            })
            ->selectRaw("COALESCE(NULLIF(c.salesperson_code, ''), '__UNASSIGNED__') as salesperson_code")
            ->selectRaw("MAX(COALESCE(NULLIF(sp.salesperson_name, ''), NULLIF(c.salesperson_name, ''))) as salesperson_name")
            ->selectRaw('COUNT(*) as customer_count')
            ->selectRaw('MAX(sp.line_name) as line_name')
            ->selectRaw('MAX(sp.team_name) as team_name')
            ->groupByRaw("COALESCE(NULLIF(c.salesperson_code, ''), '__UNASSIGNED__')")
            ->orderBy('salesperson_code')
            ->get();

        return response()->json($rows);
    }

    public function customers(Request $request): JsonResponse
    {
        $q = $this->accessibleCustomers('c');

        $salesperson = trim((string) $request->query('salesperson_code', ''));
        if ($salesperson !== '') {
            if ($salesperson === '__UNASSIGNED__') {
                $q->where(function ($w) {
                    $w->whereNull('c.salesperson_code')->orWhere('c.salesperson_code', '');
                });
            } else {
                $q->where('c.salesperson_code', $salesperson);
            }
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $likeOp = SalesRules::likeOperator();
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $q->where(function ($w) use ($likeOp, $like) {
                $w->where('c.erp_customer_code', $likeOp, $like)
                    ->orWhere('c.customer_name', $likeOp, $like)
                    ->orWhere('c.phone', $likeOp, $like)
                    ->orWhere('c.province', $likeOp, $like);
            });
        }

        $province = trim((string) $request->query('province', ''));
        if ($province !== '') {
            $q->where('c.province', $province);
        }

        $invAgg = SalesRules::customerActualInvoiceSubquery();
        $excAgg = SalesRules::customerExcludedItemsSubquery();

        $q->leftJoinSub($invAgg, 'inv_agg', function ($join): void {
            $join->on('inv_agg.source_system', '=', 'c.source_system')
                ->on('inv_agg.erp_customer_code', '=', 'c.erp_customer_code');
        })
            ->leftJoinSub($excAgg, 'exc_agg', function ($join): void {
                $join->on('exc_agg.source_system', '=', 'c.source_system')
                    ->on('exc_agg.erp_customer_code', '=', 'c.erp_customer_code');
            });

        $statusFilter = trim((string) $request->query('status', 'all'));
        if ($statusFilter === 'purchasing') {
            $q->whereRaw('COALESCE(inv_agg.invoice_count, 0) > 0');
        } elseif ($statusFilter === 'no_purchase') {
            $q->whereRaw('COALESCE(inv_agg.invoice_count, 0) = 0');
        }

        $q->select(
            'c.id',
            'c.source_system',
            'c.erp_customer_code',
            'c.customer_name',
            'c.phone',
            'c.province',
            'c.address',
            'c.ar_enable',
            'c.route_code',
            'c.route_name',
            'c.credit_limit',
            'c.credit_term_days',
            'c.salesperson_code',
            'c.salesperson_name',
            'c.updated_at',
            DB::raw('COALESCE(inv_agg.invoice_count, 0) as invoice_count'),
            DB::raw('COALESCE(inv_agg.actual_before_vat, 0) - COALESCE(exc_agg.excluded_before_vat, 0) as total_sales_net'),
            DB::raw('COALESCE(inv_agg.actual_gross, 0) as total_sales_gross'),
            DB::raw('inv_agg.last_purchase_at as last_purchase_at'),
            DB::raw('(SELECT cm.id FROM crm_customer_masters cm WHERE cm.erp_customer_id = c.id LIMIT 1) as customer_master_id'),
            DB::raw('(SELECT cm.internal_customer_code FROM crm_customer_masters cm WHERE cm.erp_customer_id = c.id LIMIT 1) as internal_customer_code'),
            DB::raw('(SELECT loc.latitude FROM crm_customer_locations loc WHERE loc.erp_customer_id = c.id ORDER BY loc.id DESC LIMIT 1) as latitude'),
            DB::raw('(SELECT loc.longitude FROM crm_customer_locations loc WHERE loc.erp_customer_id = c.id ORDER BY loc.id DESC LIMIT 1) as longitude')
        );

        $sort = trim((string) $request->query('sort', 'name_asc'));
        if ($sort === 'sales_desc') {
            $q->orderByDesc(DB::raw('COALESCE(inv_agg.actual_before_vat, 0) - COALESCE(exc_agg.excluded_before_vat, 0)'))
                ->orderBy('c.customer_name');
        } elseif ($sort === 'recent_purchase') {
            $q->orderByDesc('inv_agg.last_purchase_at')->orderBy('c.customer_name');
        } elseif ($sort === 'code_asc') {
            $q->orderBy('c.erp_customer_code');
        } else {
            $q->orderBy('c.customer_name');
        }

        if ($request->boolean('paginate')) {
            $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
            $page = max(1, (int) $request->query('page', 1));
            $total = (clone $q)->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);
            $items = $q->offset(($page - 1) * $perPage)->limit($perPage)->get();

            return response()->json([
                'data' => $items,
                'meta' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ]);
        }

        return response()->json($q->limit(250)->get());
    }

    public function customerDetail(int $id): JsonResponse
    {
        $customer = $this->accessibleCustomers('c')
            ->where('c.id', $id)
            ->select('c.*')
            ->first();

        abort_unless($customer, 404, 'Customer not found or not accessible');

        $invoices = DB::table('erp_sales_invoices')
            ->where('source_system', $customer->source_system)
            ->where('erp_customer_code', $customer->erp_customer_code)
            ->orderByDesc('sold_at')
            ->limit(100)
            ->get();

        $items = DB::table('erp_sales_items as it')
            ->join('erp_sales_invoices as inv', function ($join) {
                $join->on('inv.source_system', '=', 'it.source_system')
                    ->on('inv.branch_code', '=', 'it.branch_code')
                    ->on('inv.doc_type', '=', 'it.doc_type')
                    ->on('inv.invoice_no', '=', 'it.invoice_no');
            })
            ->where('inv.source_system', $customer->source_system)
            ->where('inv.erp_customer_code', $customer->erp_customer_code)
            ->select(
                'it.branch_code',
                'it.doc_type',
                'inv.dt_properties',
                'inv.doc_category',
                'inv.status as invoice_status',
                'it.invoice_no',
                'it.line_no',
                'it.erp_product_code',
                'it.product_name',
                'it.iccat_code',
                'it.iccat_name',
                'it.is_excluded_kpi',
                'it.qty',
                'it.unit_name',
                'it.unit_price',
                'it.before_vat_amount',
                'it.vat_amount',
                'it.gross_amount',
                'it.net_amount',
                'it.refer_doc_ref',
                'inv.sold_at'
            )
            ->orderByDesc('inv.sold_at')
            ->orderBy('it.line_no')
            ->limit(200)
            ->get();

        $salesSummary = SalesRules::summarizeInvoices(function ($query, string $alias) use ($customer): void {
            $query->where("{$alias}.source_system", $customer->source_system)
                ->where("{$alias}.erp_customer_code", $customer->erp_customer_code);
        });

        return response()->json([
            'customer' => $customer,
            'total_posted_net' => (string) $salesSummary['primary_sales_before_vat'],
            'sales_summary' => $salesSummary,
            'invoices' => $invoices,
            'items' => $items,
        ]);
    }

    public function dashboard(): JsonResponse
    {
        $customerQuery = $this->accessibleCustomers('erp_customers');
        $user = Auth::guard('staff')->user();
        $isManager = in_array($user->role, ['admin', 'manager'], true);
        $salesFilter = $this->salesScopeFilter($user, $isManager);

        $salesSummary = SalesRules::summarizeInvoices($salesFilter);
        $monthlyChart = SalesRules::monthlySalesSeries($salesFilter);

        // Acquisition KPIs (strictly scoped for sales rep vs manager/admin)
        $leadBase = DB::table('crm_leads as l');
        if (! $isManager) {
            $leadBase->where('l.owner_staff_id', $user->id);
        }

        $todayStart = now()->startOfDay();
        $weekStart = now()->startOfWeek();
        $monthStart = now()->startOfMonth();

        $totalLeads = (clone $leadBase)->count();
        $leadsToday = (clone $leadBase)->where('l.created_at', '>=', $todayStart)->count();
        $leadsThisWeek = (clone $leadBase)->where('l.created_at', '>=', $weekStart)->count();
        $leadsThisMonth = (clone $leadBase)->where('l.created_at', '>=', $monthStart)->count();
        $pendingApproval = (clone $leadBase)->where('l.approval_status', 'pending')->count();
        $approvedLeads = (clone $leadBase)->where('l.approval_status', 'approved')->count();
        $minimalDrafts = (clone $leadBase)->where('l.is_minimal_draft', true)->count();
        $convertedLeads = (clone $leadBase)->whereNotNull('l.converted_erp_customer_id')->count();

        $convertedWithPurchase = (clone $leadBase)
            ->join('erp_customers as ec', 'ec.id', '=', 'l.converted_erp_customer_id')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('erp_sales_invoices as inv')
                    ->whereColumn('inv.source_system', 'ec.source_system')
                    ->whereColumn('inv.erp_customer_code', 'ec.erp_customer_code');
                SalesRules::applyActualSalesScope($sub, 'inv');
            })
            ->count();

        $byStage = (clone $leadBase)
            ->select('l.pipeline_stage', DB::raw('COUNT(*) as total'))
            ->groupBy('l.pipeline_stage')
            ->pluck('total', 'pipeline_stage');

        $byStaff = DB::table('crm_leads as l')
            ->join('staff_users as s', 's.id', '=', 'l.owner_staff_id')
            ->when(! $isManager, fn ($q) => $q->where('l.owner_staff_id', $user->id))
            ->groupBy('s.id', 's.name', 's.salesperson_code')
            ->select(
                's.id as staff_id',
                's.name as staff_name',
                's.salesperson_code',
                DB::raw('COUNT(*) as total_leads'),
                DB::raw('SUM(CASE WHEN l.converted_erp_customer_id IS NOT NULL THEN 1 ELSE 0 END) as converted_leads')
            )
            ->selectRaw('SUM(CASE WHEN l.created_at >= ? THEN 1 ELSE 0 END) as leads_today', [$todayStart->toDateTimeString()])
            ->orderByDesc('total_leads')
            ->get();

        // Customer segmentation
        $totalCustomers = (clone $customerQuery)->count();
        $activeCustomers = (clone $customerQuery)
            ->where(function ($w): void {
                $w->where('ar_enable', 'Y')->orWhereNull('ar_enable');
            })
            ->count();
        $purchasingCustomers = (clone $customerQuery)
            ->whereExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from('erp_sales_invoices as inv')
                    ->whereColumn('inv.source_system', 'erp_customers.source_system')
                    ->whereColumn('inv.erp_customer_code', 'erp_customers.erp_customer_code');
                SalesRules::applyActualSalesScope($sub, 'inv');
            })
            ->count();
        $topProvinces = (clone $customerQuery)
            ->selectRaw("COALESCE(NULLIF(province, ''), 'ไม่ระบุจังหวัด') as province, COUNT(*) as total")
            ->groupByRaw("COALESCE(NULLIF(province, ''), 'ไม่ระบุจังหวัด')")
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        // Visit & task performance
        $visitBase = DB::table('crm_customer_visits as v');
        if (! $isManager) {
            $visitBase->where('v.staff_user_id', $user->id);
        }
        $totalVisits = (clone $visitBase)->count();
        $visitsThisMonth = (clone $visitBase)->where('v.visited_at', '>=', $monthStart)->count();

        $taskBase = DB::table('crm_follow_up_tasks');
        if (! $isManager) {
            $taskBase->where('assigned_to', $user->id);
        }
        $openTasks = (clone $taskBase)->where('status', '!=', 'done')->count();
        $completedTasks = (clone $taskBase)->where('status', 'done')->count();

        $recentVisits = DB::table('crm_customer_visits as v')
            ->leftJoin('staff_users as s', 's.id', '=', 'v.staff_user_id')
            ->leftJoin('erp_customers as c', 'c.id', '=', 'v.erp_customer_id')
            ->leftJoin('crm_leads as l', 'l.id', '=', 'v.crm_lead_id')
            ->when(! $isManager, fn ($q) => $q->where('v.staff_user_id', $user->id))
            ->select(
                'v.id',
                'v.visited_at',
                'v.result',
                's.name as staff_name',
                DB::raw('COALESCE(c.customer_name, l.shop_name) as shop_name')
            )
            ->orderByDesc('v.visited_at')
            ->limit(10)
            ->get();

        return response()->json([
            'customers' => $totalCustomers,
            'posted_invoices' => $salesSummary['invoice_count'],
            'posted_sales_net' => (string) $salesSummary['primary_sales_before_vat'],
            'sales_kpis' => $salesSummary,
            'monthly_sales_chart' => $monthlyChart,
            'customer_segmentation' => [
                'total_customers' => $totalCustomers,
                'active_customers' => $activeCustomers,
                'purchasing_customers' => $purchasingCustomers,
                'non_purchasing_customers' => max(0, $totalCustomers - $purchasingCustomers),
                'top_provinces' => $topProvinces,
            ],
            'visit_performance' => [
                'total_visits' => $totalVisits,
                'visits_this_month' => $visitsThisMonth,
                'open_tasks' => $openTasks,
                'completed_tasks' => $completedTasks,
            ],
            'recent_activities' => $recentVisits,
            'acquisition' => [
                'total_leads' => $totalLeads,
                'leads_today' => $leadsToday,
                'leads_this_week' => $leadsThisWeek,
                'leads_this_month' => $leadsThisMonth,
                'pending_approval' => $pendingApproval,
                'approved_leads' => $approvedLeads,
                'minimal_drafts' => $minimalDrafts,
                'converted_leads' => $convertedLeads,
                'converted_with_purchase' => $convertedWithPurchase,
                'by_stage' => $byStage,
                'by_staff' => $byStaff,
            ],
        ]);
    }

    public function reconciliationReport(Request $request)
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'format' => ['nullable', 'in:json,csv,print'],
        ]);

        $user = Auth::guard('staff')->user();
        $isManager = in_array($user->role, ['admin', 'manager'], true);
        $salesFilter = $this->salesScopeFilter($user, $isManager);

        $dateAndRoleFilter = function ($query, string $invAlias) use ($filters, $salesFilter): void {
            if ($salesFilter) {
                $salesFilter($query, $invAlias);
            }
            if (! empty($filters['from'])) {
                $query->whereDate("{$invAlias}.sold_at", '>=', $filters['from']);
            }
            if (! empty($filters['to'])) {
                $query->whereDate("{$invAlias}.sold_at", '<=', $filters['to']);
            }
        };

        $byDocTypeQuery = DB::table('erp_sales_invoices as inv');
        $dateAndRoleFilter($byDocTypeQuery, 'inv');
        $byDocType = $byDocTypeQuery
            ->groupBy('inv.doc_type', 'inv.dt_properties', 'inv.doc_category', 'inv.status')
            ->select(
                'inv.doc_type',
                'inv.dt_properties',
                'inv.doc_category',
                'inv.status',
                DB::raw('COUNT(*) as doc_count'),
                DB::raw('COALESCE(SUM(COALESCE(inv.before_vat_amount, inv.net_amount)), 0) as before_vat_total'),
                DB::raw('COALESCE(SUM(COALESCE(inv.vat_amount, 0)), 0) as vat_total'),
                DB::raw('COALESCE(SUM(COALESCE(inv.gross_amount, inv.net_amount)), 0) as gross_total')
            )
            ->orderBy('inv.dt_properties')
            ->orderBy('inv.doc_type')
            ->get();

        $excludedByCatQuery = DB::table('erp_sales_items as it')
            ->join('erp_sales_invoices as inv', function ($join): void {
                $join->on('inv.source_system', '=', 'it.source_system')
                    ->on('inv.branch_code', '=', 'it.branch_code')
                    ->on('inv.doc_type', '=', 'it.doc_type')
                    ->on('inv.invoice_no', '=', 'it.invoice_no');
            })
            ->where('it.is_excluded_kpi', true);
        SalesRules::applyActualSalesScope($excludedByCatQuery, 'inv');
        $dateAndRoleFilter($excludedByCatQuery, 'inv');
        $excludedByCat = $excludedByCatQuery
            ->groupBy('it.iccat_code', 'it.iccat_name')
            ->select(
                'it.iccat_code',
                'it.iccat_name',
                DB::raw('COUNT(*) as line_count'),
                DB::raw('COALESCE(SUM(COALESCE(it.before_vat_amount, it.net_amount)), 0) as before_vat_total'),
                DB::raw('COALESCE(SUM(COALESCE(it.gross_amount, it.net_amount)), 0) as gross_total')
            )
            ->orderBy('it.iccat_code')
            ->get();

        $report = [
            'filters' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'summary' => SalesRules::summarizeInvoices($dateAndRoleFilter),
            'by_doc_type' => $byDocType,
            'excluded_categories' => $excludedByCat,
        ];

        $format = $filters['format'] ?? 'json';
        if ($format === 'csv') {
            $csvSafe = static function ($value): string {
                $string = (string) ($value ?? '');
                if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $string = "'".$string;
                }

                return $string;
            };
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Section', 'Code', 'Status', 'Count', 'Before VAT', 'VAT', 'Gross']);
            foreach ($report['summary'] as $key => $value) {
                fputcsv($stream, ['summary', $csvSafe($key), '', is_numeric($value) && str_ends_with($key, 'count') ? $value : '',
                    is_numeric($value) && ! str_ends_with($key, 'count') ? $value : '', '', '']);
            }
            foreach ($byDocType as $row) {
                fputcsv($stream, ['document', $csvSafe($row->doc_type), $csvSafe($row->status), $row->doc_count,
                    $row->before_vat_total, $row->vat_total, $row->gross_total]);
            }
            foreach ($excludedByCat as $row) {
                fputcsv($stream, ['excluded_category', $csvSafe($row->iccat_code), $csvSafe($row->iccat_name), $row->line_count,
                    $row->before_vat_total, '', $row->gross_total]);
            }
            rewind($stream);
            $csv = (string) stream_get_contents($stream);
            fclose($stream);

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="sales-reconciliation.csv"',
            ]);
        }

        if ($format === 'print') {
            $escape = static fn ($value): string => htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES, 'UTF-8');
            $rows = '';
            foreach ($byDocType as $row) {
                $rows .= '<tr><td>'.$escape($row->doc_type).'</td><td>'.$escape($row->status).'</td><td>'.(int) $row->doc_count
                    .'</td><td>'.$escape($row->before_vat_total).'</td><td>'.$escape($row->vat_total).'</td><td>'.$escape($row->gross_total).'</td></tr>';
            }
            $excludedRows = '';
            foreach ($excludedByCat as $row) {
                $excludedRows .= '<tr><td>'.$escape($row->iccat_code).'</td><td>'.$escape($row->iccat_name).'</td><td>'.(int) $row->line_count
                    .'</td><td>'.$escape($row->before_vat_total).'</td><td>'.$escape($row->gross_total).'</td></tr>';
            }
            $html = '<!doctype html><html lang="th"><head><meta charset="utf-8"><title>Sales Reconciliation</title>'
                .'<style>body{font-family:sans-serif;margin:24px;color:#182230}table{width:100%;border-collapse:collapse;margin:12px 0 24px}th,td{border:1px solid #cbd5e1;padding:8px;text-align:left}th{background:#f1f5f9}.cards{display:flex;flex-wrap:wrap;gap:12px}.card{padding:12px;border:1px solid #cbd5e1;border-radius:8px}</style></head><body>'
                .'<h1>Popstar Sales Reconciliation</h1><p>Period: '.$escape($filters['from'] ?? 'All').' – '.$escape($filters['to'] ?? 'All').'</p><div class="cards">';
            foreach ($report['summary'] as $key => $value) {
                $html .= '<div class="card"><strong>'.$escape($key).'</strong><br>'.$escape($value).'</div>';
            }
            $html .= '</div><h2>Documents</h2><table><thead><tr><th>Document</th><th>Status</th><th>Count</th><th>Before VAT</th><th>VAT</th><th>Gross</th></tr></thead><tbody>'.$rows
                .'</tbody></table><h2>Excluded categories</h2><table><thead><tr><th>Code</th><th>Category</th><th>Lines</th><th>Before VAT</th><th>Gross</th></tr></thead><tbody>'.$excludedRows
                .'</tbody></table><script>window.print()</script></body></html>';

            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        return response()->json($report);
    }

    public function memberLinks(): JsonResponse
    {
        $user = Auth::guard('staff')->user();
        abort_unless(in_array($user->role, ['admin', 'manager'], true), 403, 'Admin or manager role required');

        $links = DB::table('member_erp_links as l')
            ->join('member_accounts as m', 'm.id', '=', 'l.member_account_id')
            ->leftJoin('erp_customers as c', 'c.id', '=', 'l.erp_customer_id')
            ->leftJoin('crm_customer_masters as cm', 'cm.id', '=', 'l.crm_customer_master_id')
            ->leftJoin('erp_members as em', function ($join): void {
                $join->on('em.source_system', '=', 'c.source_system')
                    ->on('em.member_code', '=', 'l.erp_member_code');
            })
            ->leftJoin('staff_users as s', 's.id', '=', 'l.verified_by')
            ->select(
                'l.id',
                'l.member_account_id',
                'l.erp_customer_id',
                'l.crm_customer_master_id',
                'l.erp_member_code',
                'l.verification_status',
                'l.verified_at',
                'l.verification_note',
                'm.name as member_name',
                'm.email as member_email',
                'm.phone as member_phone',
                'c.erp_customer_code',
                'c.customer_name as erp_customer_name',
                'cm.internal_customer_code',
                'cm.link_status as erp_link_status',
                'em.erp_point_balance',
                's.name as verified_by_name'
            )
            ->orderByDesc('l.id')
            ->limit(250)
            ->get();

        return response()->json($links);
    }

    public function createMemberLink(Request $request): JsonResponse
    {
        $user = Auth::guard('staff')->user();
        abort_unless(in_array($user->role, ['admin', 'manager'], true), 403, 'Admin or manager role required');

        $d = $request->validate([
            'member_account_id' => ['required', 'integer', 'exists:member_accounts,id'],
            'erp_customer_id' => ['nullable', 'integer', 'exists:erp_customers,id', 'required_without:crm_customer_master_id'],
            'crm_customer_master_id' => ['nullable', 'integer', 'exists:crm_customer_masters,id', 'required_without:erp_customer_id'],
            'erp_member_code' => ['nullable', 'string', 'max:64'],
            'verification_status' => ['nullable', 'in:pending,verified,rejected'],
            'verification_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $status = $d['verification_status'] ?? 'pending';
        $now = now();
        $customerMasterId = $d['crm_customer_master_id'] ?? null;
        $erpCustomerId = $d['erp_customer_id'] ?? null;
        if ($erpCustomerId) {
            $master = DB::table('crm_customer_masters')->where('erp_customer_id', $erpCustomerId)->first();
            if (! $master) {
                $customer = DB::table('erp_customers')->where('id', $erpCustomerId)->first();
                $customerMasterId = DB::table('crm_customer_masters')->insertGetId([
                    'internal_customer_code' => sprintf('POP-ERP-%06d', $customer->id),
                    'erp_customer_id' => $customer->id,
                    'erp_source_system' => $customer->source_system,
                    'erp_customer_code' => $customer->erp_customer_code,
                    'link_status' => 'linked', 'verified_at' => $now, 'verified_by' => $user->id,
                    'display_name' => $customer->customer_name, 'phone' => $customer->phone,
                    'address' => $customer->address ?? null, 'province' => $customer->province ?? null,
                    'assigned_salesperson_code' => $customer->salesperson_code ?? null,
                    'created_source' => 'erp_mirror', 'created_by' => $user->id, 'updated_by' => $user->id,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                if ($customerMasterId && (int) $customerMasterId !== (int) $master->id) {
                    abort(409, 'Selected internal customer does not match the ERP customer.');
                }
                $customerMasterId = $master->id;
            }
        }

        $id = DB::transaction(function () use ($d, $status, $user, $now, $customerMasterId, $erpCustomerId) {
            if (DB::table('member_erp_links')->where('member_account_id', $d['member_account_id'])->exists()) {
                abort(409, 'Member account already has a customer link. Use the existing link review workflow.');
            }
            $linkId = DB::table('member_erp_links')->insertGetId([
                'member_account_id' => $d['member_account_id'],
                'crm_customer_master_id' => $customerMasterId,
                'erp_customer_id' => $erpCustomerId,
                'erp_member_code' => $d['erp_member_code'] ?? null,
                'verification_status' => $status,
                'verified_at' => $status === 'verified' ? $now : null,
                'verified_by' => $status === 'verified' ? $user->id : null,
                'verification_note' => $d['verification_note'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('crm_audit_logs')->insert([
                'entity_type' => 'member_erp_link',
                'entity_id' => $linkId,
                'action' => 'created_'.$status,
                'actor_staff_id' => $user->id,
                'changes' => json_encode($d, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);

            return $linkId;
        });

        return response()->json(['ok' => true, 'id' => $id, 'verification_status' => $status], 201);
    }

    public function verifyMemberLink(Request $request, int $id): JsonResponse
    {
        $user = Auth::guard('staff')->user();
        abort_unless(in_array($user->role, ['admin', 'manager'], true), 403, 'Admin or manager role required');

        $d = $request->validate([
            'verification_status' => ['required', 'in:verified,rejected,pending'],
            'erp_member_code' => ['nullable', 'string', 'max:64'],
            'verification_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $existing = DB::table('member_erp_links')->where('id', $id)->first();
        abort_unless($existing, 404, 'Member link not found');

        $now = now();
        $status = $d['verification_status'];

        DB::transaction(function () use ($id, $d, $status, $user, $now, $existing): void {
            DB::table('member_erp_links')->where('id', $id)->update([
                'verification_status' => $status,
                'verified_at' => $status === 'verified' ? $now : null,
                'verified_by' => $user->id,
                'erp_member_code' => array_key_exists('erp_member_code', $d) ? $d['erp_member_code'] : $existing->erp_member_code,
                'verification_note' => $d['verification_note'] ?? null,
                'updated_at' => $now,
            ]);

            DB::table('crm_audit_logs')->insert([
                'entity_type' => 'member_erp_link',
                'entity_id' => $id,
                'action' => 'status_'.$status,
                'actor_staff_id' => $user->id,
                'changes' => json_encode($d, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        });

        return response()->json(['ok' => true, 'id' => $id, 'verification_status' => $status]);
    }

    public function visits(Request $request): JsonResponse
    {
        $user = Auth::guard('staff')->user();
        $query = DB::table('crm_customer_visits as v')
            ->leftJoin('erp_customers as c', 'c.id', '=', 'v.erp_customer_id')
            ->leftJoin('crm_leads as l', 'l.id', '=', 'v.crm_lead_id')
            ->leftJoin('staff_users as s', 's.id', '=', 'v.staff_user_id');
        if (! in_array($user->role, ['admin', 'manager'], true)) {
            $query->where('v.staff_user_id', $user->id);
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $likeOp = SalesRules::likeOperator();
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($w) use ($likeOp, $like): void {
                $w->where('c.customer_name', $likeOp, $like)
                    ->orWhere('l.shop_name', $likeOp, $like)
                    ->orWhere('v.supplier_name', $likeOp, $like)
                    ->orWhere('v.note', $likeOp, $like);
            });
        }

        $resultFilter = trim((string) $request->query('result', ''));
        if ($resultFilter !== '') {
            $query->where('v.result', $resultFilter);
        }

        $query->select(
            'v.id',
            'v.erp_customer_id',
            'v.crm_lead_id',
            'v.customer_master_id',
            'c.customer_name',
            'c.erp_customer_code',
            'l.shop_name',
            'v.visited_at',
            'v.device_recorded_at',
            'v.server_recorded_at',
            'v.checkin_at',
            'v.checkin_latitude',
            'v.checkin_longitude',
            'v.checkin_accuracy_m',
            'v.gps_permission_status',
            'v.client_draft_id',
            'v.note',
            'v.result',
            'v.shop_type',
            'v.shop_phone',
            'v.supplier_name',
            'v.product_types',
            'v.customer_issues',
            'v.customer_feedback',
            'v.follow_up_action',
            'v.follow_up_date',
            's.name as staff_name',
            DB::raw('(SELECT COUNT(*) FROM crm_visit_photos vp WHERE vp.visit_id = v.id) as photo_count')
        )->orderByDesc('v.visited_at')->orderByDesc('v.id');

        if ($request->boolean('paginate')) {
            $perPage = max(10, min(100, (int) $request->query('per_page', 20)));
            $page = max(1, (int) $request->query('page', 1));
            $total = (clone $query)->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);
            $items = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

            return response()->json([
                'data' => $items,
                'meta' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ]);
        }

        return response()->json($query->limit(200)->get());
    }

    public function storeVisit(Request $request): JsonResponse
    {
        $d = $request->validate([
            'erp_customer_id' => 'required|integer|exists:erp_customers,id',
            'visited_at' => 'required|date',
            'note' => 'nullable|string|max:2000',
            'result' => 'required|in:visited,follow_up,interested,not_interested',
        ]);
        if (! $this->accessibleCustomers('erp_customers')->where('id', $d['erp_customer_id'])->exists()) {
            return response()->json(['error' => 'Customer not accessible'], 403);
        }
        $id = DB::table('crm_customer_visits')->insertGetId([
            'erp_customer_id' => $d['erp_customer_id'],
            'staff_user_id' => Auth::guard('staff')->id(),
            'visited_at' => $d['visited_at'],
            'note' => $d['note'] ?? null,
            'result' => $d['result'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function members(Request $request): JsonResponse
    {
        $user = Auth::guard('staff')->user();
        abort_unless(in_array($user->role, ['admin', 'manager'], true), 403, 'Admin or manager role required');

        $search = trim((string) $request->query('q', ''));
        $likeOp = SalesRules::likeOperator();

        $q = DB::table('member_accounts as m')
            ->leftJoin('member_erp_links as l', 'l.member_account_id', '=', 'm.id')
            ->leftJoin('erp_customers as ec', 'ec.id', '=', 'l.erp_customer_id')
            ->leftJoin('crm_customer_masters as cm', 'cm.id', '=', 'l.crm_customer_master_id');

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $q->where(function ($w) use ($likeOp, $like): void {
                $w->where('m.name', $likeOp, $like)
                    ->orWhere('m.email', $likeOp, $like)
                    ->orWhere('m.phone', $likeOp, $like)
                    ->orWhere('ec.erp_customer_code', $likeOp, $like)
                    ->orWhere('ec.customer_name', $likeOp, $like);
            });
        }

        $rows = $q->select(
            'm.id',
            'm.name',
            'm.email',
            'm.phone',
            'm.status',
            'm.created_at',
            'l.id as link_id',
            'l.erp_customer_id',
            'l.crm_customer_master_id',
            'l.erp_member_code',
            'l.verification_status',
            'l.verified_at',
            'ec.erp_customer_code',
            'ec.customer_name as erp_customer_name',
            'cm.internal_customer_code',
            DB::raw("(SELECT mla.binding_status FROM member_line_accounts mla WHERE mla.member_account_id = m.id AND mla.revoked_at IS NULL ORDER BY mla.id DESC LIMIT 1) as line_binding_status"),
            DB::raw("(SELECT COALESCE(SUM(lpl.points_delta), 0) FROM loyalty_points_ledger lpl WHERE lpl.member_account_id = m.id) as points_balance")
        )
            ->orderByDesc('m.id')
            ->limit(250)
            ->get();

        return response()->json($rows);
    }

    public function settingsOverview(): JsonResponse
    {
        $user = Auth::guard('staff')->user();
        abort_unless(in_array($user->role, ['admin', 'manager'], true), 403, 'Admin or manager role required');

        $staff = DB::table('staff_users')
            ->select('id', 'name', 'email', 'role', 'salesperson_code', 'is_active', 'created_at')
            ->orderBy('id')
            ->get();

        $auditLogs = DB::table('crm_audit_logs as a')
            ->leftJoin('staff_users as s', 's.id', '=', 'a.actor_staff_id')
            ->select('a.id', 'a.entity_type', 'a.entity_id', 'a.action', 'a.changes', 'a.created_at', 's.name as actor_name')
            ->orderByDesc('a.id')
            ->limit(80)
            ->get();

        $activeRules = DB::table('loyalty_rules')->where('is_active', true)->count();
        $totalRules = DB::table('loyalty_rules')->count();

        return response()->json([
            'staff_users' => $staff,
            'audit_logs' => $auditLogs,
            'security_posture' => [
                'erp_mirror_readonly' => true,
                'staff_member_auth_separated' => true,
                'phone_only_never_auto_verifies' => true,
                'bridge_url_configured' => trim((string) config('popstar.local_bridge_url', '')) !== '',
                'local_bridge_token_configured' => trim((string) config('popstar.local_bridge_token', '')) !== '',
                'sync_token_configured' => trim((string) config('popstar.sync_api_token', '')) !== '',
                'line_channel_configured' => trim((string) config('popstar.line_channel_secret', '')) !== '',
                'active_loyalty_rules' => $activeRules,
                'total_loyalty_rules' => $totalRules,
            ],
        ]);
    }
}
