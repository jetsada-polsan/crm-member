<?php

namespace App\Modules\CRM;

use App\Modules\ERPIntegration\SalesRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class VisitPresentationController
{
    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'staff_id' => ['nullable', 'integer', 'exists:staff_users,id'],
            'route_code' => ['nullable', 'string', 'max:32'],
        ]);
        $start = Carbon::parse($filters['from'], 'Asia/Bangkok')->startOfDay();
        $end = Carbon::parse($filters['to'], 'Asia/Bangkok')->addDay()->startOfDay();
        abort_if($start->diffInDays($end) > 31, 422, 'ช่วงรายงานไม่เกิน 31 วัน');
        // PostgreSQL stores timestamps with time zones and its session may be in
        // Asia/Bangkok; keep the offset in query bindings. SQLite test dates are
        // stored as local wall-clock strings without an offset.
        $isPostgres = DB::connection()->getDriverName() === 'pgsql';
        $fromBound = $isPostgres ? $start->toIso8601String() : $start->format('Y-m-d H:i:s');
        $toBound = $isPostgres ? $end->toIso8601String() : $end->format('Y-m-d H:i:s');

        $user = Auth::guard('staff')->user();
        $isManager = CustomerAccess::isManager($user);
        $staffId = $isManager ? ($filters['staff_id'] ?? null) : $user->id;
        if (! $isManager && ! empty($filters['staff_id']) && (int) $filters['staff_id'] !== (int) $user->id) {
            abort(403);
        }
        $selectedStaff = $staffId ? DB::table('staff_users')->where('id', $staffId)->first(['id', 'name', 'salesperson_code']) : null;
        $routeCode = trim((string) ($filters['route_code'] ?? ''));

        $visitsQuery = DB::table('crm_customer_visits as v')
            ->leftJoin('staff_users as s', 's.id', '=', 'v.staff_user_id')
            ->leftJoin('crm_leads as l', 'l.id', '=', 'v.crm_lead_id')
            ->leftJoin('erp_customers as c', function ($join): void {
                $join->on('c.id', '=', DB::raw('COALESCE(v.erp_customer_id, l.converted_erp_customer_id)'));
            })
            ->where('v.visited_at', '>=', $fromBound)
            ->where('v.visited_at', '<', $toBound)
            ->when($staffId, fn ($q) => $q->where('v.staff_user_id', $staffId))
            ->when($routeCode !== '', fn ($q) => $q->where('c.route_code', $routeCode))
            ->select('v.id', 'v.visited_at', 'v.erp_customer_id', 'v.crm_lead_id', 'v.staff_user_id', 's.name as staff_name', 'v.result', 'v.shop_type',
                'v.shop_phone', 'v.supplier_name', 'v.product_types', 'v.customer_issues', 'v.customer_feedback',
                'v.follow_up_action', 'v.follow_up_date', 'v.note', 'c.route_code', 'c.route_name', 'c.erp_customer_code',
                DB::raw('COALESCE(c.customer_name, l.shop_name) as shop_name'))
            ->orderBy('v.visited_at')->orderBy('v.id');
        $visits = $visitsQuery->limit(201)->get();
        abort_if($visits->count() > 200, 422, 'พบการเข้าพบเกิน 200 รายการ กรุณาลดช่วงวันที่หรือเลือกพนักงาน');
        $photos = DB::table('crm_visit_photos')->whereIn('visit_id', $visits->pluck('id'))
            ->select('id', 'visit_id', 'category')->orderBy('id')->get()->groupBy('visit_id');
        foreach ($visits as $visit) {
            $visit->photos = ($photos[$visit->id] ?? collect())->take(2)->values();
        }

        // Sales are attributed using the salesperson recorded on the invoice.
        // A selected staff member without a confirmed ERP code gets no invented total.
        $sales = null;
        $dailySales = [];
        $salespersonCode = $selectedStaff ? trim((string) $selectedStaff->salesperson_code) : null;
        if (! $selectedStaff || $salespersonCode !== '') {
            $salesQuery = DB::table('erp_sales_invoices as inv')
                ->leftJoin('erp_customers as ec', function ($join): void {
                    $join->on('ec.source_system', '=', 'inv.source_system')->on('ec.erp_customer_code', '=', 'inv.erp_customer_code');
                })
                ->where('inv.sold_at', '>=', $fromBound)
                ->where('inv.sold_at', '<', $toBound)
                ->when($selectedStaff, fn ($q) => $q->where('inv.salesperson_code', $salespersonCode))
                ->when($routeCode !== '', fn ($q) => $q->where('ec.route_code', $routeCode));
            CustomerAccess::applyInvoiceScope($salesQuery, 'inv', $user);
            SalesRules::applyActualSalesScope($salesQuery, 'inv');
            $sales = (clone $salesQuery)->selectRaw("COALESCE(SUM(COALESCE(inv.before_vat_amount, inv.net_amount)), 0) as net_before_vat, SUM(CASE WHEN inv.doc_category = 'sale' THEN 1 ELSE 0 END) as sale_count, SUM(CASE WHEN inv.doc_category = 'return' THEN 1 ELSE 0 END) as return_count")->first();
            $dayExpression = $isPostgres
                ? "DATE(inv.sold_at AT TIME ZONE 'Asia/Bangkok')"
                : "DATE(inv.sold_at)";
            $dailySales = (clone $salesQuery)->selectRaw("{$dayExpression} as sale_date, COALESCE(SUM(COALESCE(inv.before_vat_amount, inv.net_amount)), 0) as net_before_vat")
                ->groupByRaw($dayExpression)->orderBy('sale_date')->get();
        }
        $staff = DB::table('staff_users')->where('is_active', true)
            ->when(! $isManager, fn ($q) => $q->where('id', $user->id))
            ->orderBy('name')->get(['id', 'name']);
        $latestInvoice = DB::table('erp_sales_invoices as inv');
        CustomerAccess::applyInvoiceScope($latestInvoice, 'inv', $user);
        SalesRules::applyActualSalesScope($latestInvoice, 'inv');

        return response()->json([
            'filters' => ['from' => $filters['from'], 'to' => $filters['to'], 'staff_id' => $staffId, 'route_code' => $routeCode],
            'staff' => $staff, 'selected_staff' => $selectedStaff ? ['id' => $selectedStaff->id, 'name' => $selectedStaff->name] : null,
            'visits' => $visits, 'sales' => $sales, 'daily_sales' => $dailySales,
            'latest_invoice_at' => $latestInvoice->max('inv.sold_at'),
            'sales_note' => 'ยอดขายจริงก่อน VAT จาก ERP mirror หลังหักคืน; วันที่ซิงก์ล่าสุดไม่ได้ยืนยันความครบถ้วน',
        ]);
    }
}
