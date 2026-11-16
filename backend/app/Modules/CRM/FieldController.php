<?php

namespace App\Modules\CRM;

use App\Modules\ERPIntegration\SalesRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FieldController
{
    private const PIPELINE_STAGES = [
        'new_lead',
        'contacted',
        'interested',
        'quoted',
        'follow_up',
        'erp_creation_requested',
        'erp_match_pending',
        'converted',
        'lost',
    ];

    private function user()
    {
        return Auth::guard('staff')->user();
    }

    private function manager(): bool
    {
        return CustomerAccess::isManager($this->user());
    }

    private function customerAllowed(int $id): bool
    {
        return CustomerAccess::query('c', $this->user())->where('c.id', $id)->exists();
    }

    private function leadAllowed(int $id): bool
    {
        $q = DB::table('crm_leads')->where('id', $id);
        if (!$this->manager()) {
            $q->where('owner_staff_id', $this->user()->id);
        }
        return $q->exists();
    }

    private function checkSubject(array $d): void
    {
        if (((int) !empty($d['erp_customer_id']) + (int) !empty($d['crm_lead_id'])) !== 1) {
            throw ValidationException::withMessages(['customer' => 'Specify exactly one ERP customer or CRM lead']);
        }
        if (!empty($d['erp_customer_id']) && !$this->customerAllowed((int) $d['erp_customer_id'])) {
            abort(403);
        }
        if (!empty($d['crm_lead_id']) && !$this->leadAllowed((int) $d['crm_lead_id'])) {
            abort(403);
        }
    }

    private function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with($digits, '66') && strlen($digits) >= 10) {
            $digits = '0' . substr($digits, 2);
        }
        return $digits;
    }

    private function normalizeName(?string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $name)));
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return (int) round(2 * $earthRadius * asin(min(1.0, sqrt($a))));
    }

    private function findPotentialDuplicates(
        ?string $shopName,
        ?string $phone,
        ?float $lat = null,
        ?float $lng = null,
        ?int $excludeLeadId = null,
        ?string $erpCustomerCode = null
    ): array {
        $normPhone = $this->normalizePhone($phone);
        $normName = $this->normalizeName($shopName);
        $normErpCode = mb_strtolower(trim((string) ($erpCustomerCode ?? '')));
        $matches = [];

        if ($normPhone === '' && $normName === '' && $normErpCode === '' && ($lat === null || $lng === null)) {
            return [];
        }

        // Check existing CRM leads
        $leads = DB::table('crm_leads as l')
            ->leftJoin('crm_customer_locations as loc', 'loc.crm_lead_id', '=', 'l.id')
            ->leftJoin('erp_customers as linked_ec', 'linked_ec.id', '=', 'l.converted_erp_customer_id')
            ->select(
                'l.id',
                'l.shop_name',
                'l.phone',
                'l.district',
                'l.pipeline_stage',
                'loc.latitude',
                'loc.longitude',
                'linked_ec.erp_customer_code as linked_erp_code'
            )
            ->when($excludeLeadId, fn ($q) => $q->where('l.id', '!=', $excludeLeadId))
            ->get();

        foreach ($leads as $row) {
            $reasons = [];
            if ($normErpCode !== '' && mb_strtolower(trim((string) ($row->linked_erp_code ?? ''))) === $normErpCode) {
                $reasons[] = 'รหัสลูกค้า ERP ตรงกับรายการที่เชื่อมแล้ว';
            }
            if ($normPhone !== '' && strlen($normPhone) >= 8 && $this->normalizePhone($row->phone) === $normPhone) {
                $reasons[] = 'เบอร์โทรตรงกัน';
            }
            $rowName = $this->normalizeName($row->shop_name);
            if ($normName !== '' && mb_strlen($normName) >= 3 && ($rowName === $normName || str_contains($rowName, $normName) || str_contains($normName, $rowName))) {
                $reasons[] = 'ชื่อร้านคล้าย/ซ้ำ';
            }
            $dist = null;
            if ($lat !== null && $lng !== null && $row->latitude !== null && $row->longitude !== null) {
                $dist = $this->haversineMeters($lat, $lng, (float) $row->latitude, (float) $row->longitude);
                if ($dist <= 150) {
                    $reasons[] = "พิกัดใกล้เคียง ({$dist} ม.)";
                }
            }
            if ($reasons !== []) {
                $matches[] = [
                    'source' => 'lead',
                    'id' => $row->id,
                    'code' => 'LEAD-' . $row->id,
                    'name' => $row->shop_name,
                    'phone' => $row->phone,
                    'district' => $row->district,
                    'distance_m' => $dist,
                    'match_reasons' => $reasons,
                ];
            }
        }

        // Check ERP customers (read-only mirror)
        $erpRows = DB::table('erp_customers as c')
            ->leftJoin('crm_customer_locations as loc', 'loc.erp_customer_id', '=', 'c.id')
            ->select('c.id', 'c.erp_customer_code', 'c.customer_name', 'c.phone', 'c.province', 'loc.latitude', 'loc.longitude')
            ->get();

        foreach ($erpRows as $row) {
            $reasons = [];
            if ($normErpCode !== '' && mb_strtolower(trim((string) $row->erp_customer_code)) === $normErpCode) {
                $reasons[] = 'รหัสลูกค้า ERP ตรงกับในระบบ';
            }
            if ($normPhone !== '' && strlen($normPhone) >= 8 && $this->normalizePhone($row->phone) === $normPhone) {
                $reasons[] = 'เบอร์โทรตรงกับลูกค้า ERP';
            }
            $rowName = $this->normalizeName($row->customer_name);
            if ($normName !== '' && mb_strlen($normName) >= 3 && ($rowName === $normName || str_contains($rowName, $normName) || str_contains($normName, $rowName))) {
                $reasons[] = 'ชื่อตรงกับลูกค้า ERP';
            }
            $dist = null;
            if ($lat !== null && $lng !== null && $row->latitude !== null && $row->longitude !== null) {
                $dist = $this->haversineMeters($lat, $lng, (float) $row->latitude, (float) $row->longitude);
                if ($dist <= 150) {
                    $reasons[] = "พิกัดใกล้ลูกค้า ERP ({$dist} ม.)";
                }
            }
            if ($reasons !== []) {
                $matches[] = [
                    'source' => 'erp',
                    'id' => $row->id,
                    'code' => $row->erp_customer_code,
                    'name' => $row->customer_name,
                    'phone' => $row->phone,
                    'district' => $row->province,
                    'distance_m' => $dist,
                    'match_reasons' => $reasons,
                ];
            }
        }

        return array_slice($matches, 0, 10);
    }

    private function recordAudit(string $entityType, int $entityId, string $action, array $changes = []): void
    {
        DB::table('crm_audit_logs')->insert([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'actor_staff_id' => $this->user()->id,
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
    }

    private function computeIsMinimalDraft(array $data): bool
    {
        return empty(trim((string) ($data['district'] ?? '')))
            && empty(trim((string) ($data['address'] ?? '')))
            && empty(trim((string) ($data['line_contact'] ?? '')));
    }

    public function checkDuplicate(Request $r): JsonResponse
    {
        $d = $r->validate([
            'shop_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:40',
            'erp_customer_code' => 'nullable|string|max:64',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'exclude_lead_id' => 'nullable|integer',
        ]);

        $duplicates = $this->findPotentialDuplicates(
            $d['shop_name'] ?? null,
            $d['phone'] ?? null,
            isset($d['latitude']) ? (float) $d['latitude'] : null,
            isset($d['longitude']) ? (float) $d['longitude'] : null,
            isset($d['exclude_lead_id']) ? (int) $d['exclude_lead_id'] : null,
            $d['erp_customer_code'] ?? null
        );

        return response()->json(['duplicates' => $duplicates]);
    }

    public function leads(Request $r): JsonResponse
    {
        $invoiceAgg = SalesRules::customerActualInvoiceSubquery();
        $excludedAgg = SalesRules::customerExcludedItemsSubquery();

        $q = DB::table('crm_leads as l')
            ->leftJoin('staff_users as owner', 'owner.id', '=', 'l.owner_staff_id')
            ->leftJoin('staff_users as creator', 'creator.id', '=', 'l.created_by')
            ->leftJoin('staff_users as updater', 'updater.id', '=', 'l.updated_by')
            ->leftJoin('staff_users as approver', 'approver.id', '=', 'l.approved_by')
            ->leftJoin('crm_customer_locations as loc', 'loc.crm_lead_id', '=', 'l.id')
            ->leftJoin('crm_customer_masters as cm', 'cm.crm_lead_id', '=', 'l.id')
            ->leftJoin('erp_customers as ec', 'ec.id', '=', 'l.converted_erp_customer_id')
            ->leftJoinSub($invoiceAgg, 'inv', function ($join) {
                $join->on('inv.source_system', '=', 'ec.source_system')
                    ->on('inv.erp_customer_code', '=', 'ec.erp_customer_code');
            })
            ->leftJoinSub($excludedAgg, 'exc', function ($join) {
                $join->on('exc.source_system', '=', 'ec.source_system')
                    ->on('exc.erp_customer_code', '=', 'ec.erp_customer_code');
            });

        if (!$this->manager()) {
            $q->where('l.owner_staff_id', $this->user()->id);
        }

        if ($stage = trim((string) $r->query('stage', ''))) {
            $q->where('l.pipeline_stage', $stage);
        }
        if ($approval = trim((string) $r->query('approval_status', ''))) {
            $q->where('l.approval_status', $approval);
        }
        if ($province = trim((string) $r->query('province', ''))) {
            $q->where('l.province', $province);
        }
        if ($salesCode = trim((string) $r->query('assigned_salesperson_code', ''))) {
            $q->where('l.assigned_salesperson_code', $salesCode);
        }
        if ($r->boolean('minimal_only')) {
            $q->where('l.is_minimal_draft', true);
        }
        if ($search = trim((string) $r->query('q', ''))) {
            $likeOp = SalesRules::likeOperator();
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $q->where(function ($w) use ($likeOp, $like) {
                $w->where('l.shop_name', $likeOp, $like)
                    ->orWhere('l.phone', $likeOp, $like)
                    ->orWhere('l.contact_name', $likeOp, $like)
                    ->orWhere('l.province', $likeOp, $like)
                    ->orWhere('l.district', $likeOp, $like)
                    ->orWhere('l.shop_type', $likeOp, $like);
            });
        }

        $q->select(
            'l.*',
            'cm.id as customer_master_id',
            'cm.internal_customer_code',
            'owner.name as owner_staff_name',
            'creator.name as created_by_name',
            'updater.name as updated_by_name',
            'approver.name as approved_by_name',
            'loc.latitude',
            'loc.longitude',
            'loc.accuracy_m',
            'ec.erp_customer_code',
            'ec.customer_name as erp_customer_name',
            DB::raw('COALESCE(inv.invoice_count, 0) as erp_invoice_count'),
            DB::raw('COALESCE(inv.actual_before_vat, 0) - COALESCE(exc.excluded_before_vat, 0) as erp_total_sales_net'),
            DB::raw('(SELECT COUNT(*) FROM crm_lead_photos p WHERE p.crm_lead_id = l.id) as photo_count')
        )->orderByDesc('l.id');

        if ($r->boolean('paginate')) {
            $perPage = max(10, min(100, (int) $r->query('per_page', 25)));
            $page = max(1, (int) $r->query('page', 1));
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

    public function leadDetail(int $id): JsonResponse
    {
        abort_unless($this->leadAllowed($id), 403);

        $lead = DB::table('crm_leads as l')
            ->leftJoin('staff_users as owner', 'owner.id', '=', 'l.owner_staff_id')
            ->leftJoin('staff_users as creator', 'creator.id', '=', 'l.created_by')
            ->leftJoin('staff_users as updater', 'updater.id', '=', 'l.updated_by')
            ->leftJoin('staff_users as approver', 'approver.id', '=', 'l.approved_by')
            ->leftJoin('erp_customers as ec', 'ec.id', '=', 'l.converted_erp_customer_id')
            ->where('l.id', $id)
            ->select(
                'l.*',
                'owner.name as owner_staff_name',
                'creator.name as created_by_name',
                'updater.name as updated_by_name',
                'approver.name as approved_by_name',
                'ec.erp_customer_code',
                'ec.customer_name as erp_customer_name',
                'ec.source_system as erp_source_system'
            )
            ->first();

        abort_unless($lead, 404);

        $location = DB::table('crm_customer_locations')->where('crm_lead_id', $id)->first();
        $photos = DB::table('crm_lead_photos')->where('crm_lead_id', $id)->orderByDesc('id')->get();
        $tasks = DB::table('crm_follow_up_tasks')->where('crm_lead_id', $id)->orderBy('due_date')->get();
        $audits = DB::table('crm_audit_logs as a')
            ->leftJoin('staff_users as s', 's.id', '=', 'a.actor_staff_id')
            ->where('a.entity_type', 'crm_lead')
            ->where('a.entity_id', $id)
            ->select('a.id', 'a.action', 'a.changes', 'a.created_at', 's.name as actor_name')
            ->orderByDesc('a.id')
            ->limit(50)
            ->get();

        $erpInvoices = [];
        $salesSummary = null;
        if ($lead->converted_erp_customer_id && $lead->erp_customer_code) {
            $erpInvoices = DB::table('erp_sales_invoices')
                ->where('source_system', $lead->erp_source_system)
                ->where('erp_customer_code', $lead->erp_customer_code)
                ->orderByDesc('sold_at')
                ->limit(50)
                ->get();
            $salesSummary = SalesRules::summarizeInvoices(function ($query, string $alias) use ($lead): void {
                $query->where("{$alias}.source_system", $lead->erp_source_system)
                    ->where("{$alias}.erp_customer_code", $lead->erp_customer_code);
            });
        }

        return response()->json([
            'lead' => $lead,
            'location' => $location,
            'photos' => $photos,
            'tasks' => $tasks,
            'erp_invoices' => $erpInvoices,
            'sales_summary' => $salesSummary,
            'audits' => $audits,
        ]);
    }

    public function storeLead(Request $r): JsonResponse
    {
        $d = $r->validate([
            'shop_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:40',
            'contact_name' => 'nullable|string|max:120',
            'shop_type' => 'nullable|string|max:64',
            'line_contact' => 'nullable|string|max:120',
            'province' => 'nullable|string|max:120',
            'district' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:2000',
            'supplier_source' => 'nullable|string|max:255',
            'product_categories' => 'nullable|string|max:2000',
            'assigned_salesperson_code' => 'nullable|string|max:64',
            'erp_customer_code' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:2000',
            'next_follow_up_date' => 'nullable|date_format:Y-m-d',
            'force_create' => 'nullable|boolean',
        ]);

        $force = $r->boolean('force_create');
        if (!$force) {
            $duplicates = $this->findPotentialDuplicates(
                $d['shop_name'],
                $d['phone'] ?? null,
                null,
                null,
                null,
                $d['erp_customer_code'] ?? null
            );
            if ($duplicates !== []) {
                return response()->json([
                    'error' => 'พบข้อมูลร้านค้าที่อาจซ้ำในระบบ กรุณาตรวจสอบก่อนบันทึก',
                    'duplicates' => $duplicates,
                ], 409);
            }
        }

        $isMinimal = $this->computeIsMinimalDraft($d);
        $userId = $this->user()->id;

        $res = DB::transaction(function () use ($d, $isMinimal, $userId) {
            $id = DB::table('crm_leads')->insertGetId([
                'shop_name' => trim($d['shop_name']),
                'shop_type' => $d['shop_type'] ?? null,
                'phone' => $d['phone'] ?? null,
                'contact_name' => $d['contact_name'] ?? null,
                'line_contact' => $d['line_contact'] ?? null,
                'province' => $d['province'] ?? null,
                'district' => $d['district'] ?? null,
                'address' => $d['address'] ?? null,
                'supplier_source' => $d['supplier_source'] ?? null,
                'product_categories' => $d['product_categories'] ?? null,
                'notes' => $d['notes'] ?? null,
                'next_follow_up_date' => $d['next_follow_up_date'] ?? null,
                'status' => 'new',
                'pipeline_stage' => 'new_lead',
                'approval_status' => 'pending',
                'is_minimal_draft' => $isMinimal,
                'owner_staff_id' => $userId,
                'assigned_salesperson_code' => $d['assigned_salesperson_code'] ?? $this->user()->salesperson_code,
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $master = CustomerMasterController::syncLeadMaster($id, $userId);

            $this->recordAudit('crm_lead', $id, 'created', [
                'shop_name' => trim($d['shop_name']),
                'phone' => $d['phone'] ?? null,
                'shop_type' => $d['shop_type'] ?? null,
                'internal_customer_code' => $master?->internal_customer_code,
            ]);

            return [
                'id' => $id,
                'is_minimal_draft' => $isMinimal,
                'customer_master_id' => $master?->id,
                'internal_customer_code' => $master?->internal_customer_code,
            ];
        });

        return response()->json($res, 201);
    }

    public function quickStoreLead(Request $r): JsonResponse
    {
        $d = $r->validate([
            'shop_name' => 'required|string|max:255',
            'phone' => 'required|string|max:40',
            'shop_type' => 'required|string|max:64',
            'contact_name' => 'nullable|string|max:120',
            'line_contact' => 'nullable|string|max:120',
            'province' => 'nullable|string|max:120',
            'district' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:2000',
            'supplier_source' => 'nullable|string|max:255',
            'product_categories' => 'nullable|string|max:2000',
            'assigned_salesperson_code' => 'nullable|string|max:64',
            'erp_customer_code' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:2000',
            'next_follow_up_date' => 'nullable|date_format:Y-m-d',
            'follow_up_action' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy_m' => 'nullable|integer|min:0|max:100000',
            'pin_source' => ['nullable', Rule::in(['gps', 'manual', 'map_link'])],
            'photo' => 'nullable|image|mimes:jpeg,png,webp|max:5120',
            'consent_confirmed' => 'nullable',
            'captured_at' => 'nullable|date',
            'compressed_on_client' => 'nullable',
            'force_create' => 'nullable',
        ]);

        $hasLat = isset($d['latitude']) && $d['latitude'] !== '';
        $hasLng = isset($d['longitude']) && $d['longitude'] !== '';
        if ($hasLat !== $hasLng) {
            throw ValidationException::withMessages(['gps' => 'กรุณาระบุทั้ง Latitude และ Longitude']);
        }

        $force = filter_var($r->input('force_create', false), FILTER_VALIDATE_BOOLEAN);
        if (!$force) {
            $duplicates = $this->findPotentialDuplicates(
                $d['shop_name'],
                $d['phone'],
                $hasLat ? (float) $d['latitude'] : null,
                $hasLng ? (float) $d['longitude'] : null,
                null,
                $d['erp_customer_code'] ?? null
            );
            if ($duplicates !== []) {
                return response()->json([
                    'error' => 'พบข้อมูลร้านค้าที่อาจซ้ำในระบบ กรุณาตรวจสอบก่อนบันทึก',
                    'duplicates' => $duplicates,
                ], 409);
            }
        }

        $userId = $this->user()->id;
        $isMinimal = $this->computeIsMinimalDraft($d);
        $storedPath = null;
        $photoMeta = null;
        if ($r->hasFile('photo')) {
            $file = $r->file('photo');
            $photoMeta = [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size_bytes' => $file->getSize(),
            ];
            $storedPath = $file->store('leads/pending', 'local');
        }

        $result = DB::transaction(function () use ($d, $hasLat, $userId, $isMinimal, $r, &$storedPath, $photoMeta) {
            $now = now();
            $leadId = DB::table('crm_leads')->insertGetId([
                'shop_name' => trim($d['shop_name']),
                'shop_type' => trim($d['shop_type']),
                'phone' => trim($d['phone']),
                'contact_name' => $d['contact_name'] ?? null,
                'line_contact' => $d['line_contact'] ?? null,
                'province' => $d['province'] ?? null,
                'district' => $d['district'] ?? null,
                'address' => $d['address'] ?? null,
                'supplier_source' => $d['supplier_source'] ?? null,
                'product_categories' => $d['product_categories'] ?? null,
                'notes' => $d['notes'] ?? null,
                'next_follow_up_date' => $d['next_follow_up_date'] ?? null,
                'status' => 'new',
                'pipeline_stage' => 'new_lead',
                'approval_status' => 'pending',
                'is_minimal_draft' => $isMinimal,
                'owner_staff_id' => $userId,
                'assigned_salesperson_code' => $d['assigned_salesperson_code'] ?? $this->user()->salesperson_code,
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($hasLat) {
                $this->upsertCustomerLocation(
                    null,
                    (int) $leadId,
                    (float) $d['latitude'],
                    (float) $d['longitude'],
                    isset($d['accuracy_m']) ? (int) $d['accuracy_m'] : null,
                    $d['pin_source'] ?? 'gps',
                    $userId,
                    $d['address'] ?? null,
                    $now
                );
            }

            if ($storedPath !== null && $photoMeta !== null) {
                $finalPath = "leads/{$leadId}/" . basename($storedPath);
                Storage::disk('local')->move($storedPath, $finalPath);
                $storedPath = $finalPath;
                DB::table('crm_lead_photos')->insert([
                    'crm_lead_id' => $leadId,
                    'storage_path' => $finalPath,
                    'category' => 'storefront',
                    'original_name' => $photoMeta['original_name'],
                    'consent_confirmed' => filter_var($r->input('consent_confirmed', false), FILTER_VALIDATE_BOOLEAN),
                    'captured_at' => $d['captured_at'] ?? $now,
                    'latitude' => $hasLat ? (float) $d['latitude'] : null,
                    'longitude' => $hasLat ? (float) $d['longitude'] : null,
                    'accuracy_m' => ($hasLat && isset($d['accuracy_m'])) ? (int) $d['accuracy_m'] : null,
                    'mime_type' => $photoMeta['mime_type'],
                    'file_size_bytes' => $photoMeta['file_size_bytes'],
                    'compressed_on_client' => filter_var($r->input('compressed_on_client', false), FILTER_VALIDATE_BOOLEAN),
                    'uploaded_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (!empty($d['follow_up_action']) || !empty($d['next_follow_up_date'])) {
                DB::table('crm_follow_up_tasks')->insert([
                    'crm_lead_id' => $leadId,
                    'assigned_to' => $userId,
                    'title' => $d['follow_up_action'] ?? ('ติดตามร้านค้าใหม่: ' . trim($d['shop_name'])),
                    'due_date' => $d['next_follow_up_date'] ?? null,
                    'status' => 'open',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $master = CustomerMasterController::syncLeadMaster($leadId, $userId);

            $this->recordAudit('crm_lead', $leadId, 'quick_created', [
                'shop_name' => trim($d['shop_name']),
                'phone' => trim($d['phone']),
                'shop_type' => trim($d['shop_type']),
                'has_gps' => $hasLat,
                'has_photo' => $storedPath !== null,
                'is_minimal_draft' => $isMinimal,
                'internal_customer_code' => $master?->internal_customer_code,
            ]);

            return [
                'id' => $leadId,
                'is_minimal_draft' => $isMinimal,
                'customer_master_id' => $master?->id,
                'internal_customer_code' => $master?->internal_customer_code,
            ];
        });

        return response()->json(['ok' => true, ...$result], 201);
    }

    public function updateLead(Request $r, int $id): JsonResponse
    {
        abort_unless($this->leadAllowed($id), 403);
        $lead = DB::table('crm_leads')->where('id', $id)->first();
        abort_unless($lead, 404);

        $d = $r->validate([
            'shop_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:40',
            'contact_name' => 'nullable|string|max:120',
            'shop_type' => 'nullable|string|max:64',
            'line_contact' => 'nullable|string|max:120',
            'province' => 'nullable|string|max:120',
            'district' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:2000',
            'supplier_source' => 'nullable|string|max:255',
            'product_categories' => 'nullable|string|max:2000',
            'assigned_salesperson_code' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:2000',
            'pipeline_stage' => ['nullable', Rule::in(self::PIPELINE_STAGES)],
            'next_follow_up_date' => 'nullable|date_format:Y-m-d',
        ]);

        $isMinimal = $this->computeIsMinimalDraft($d);
        $stage = $d['pipeline_stage'] ?? $lead->pipeline_stage;

        DB::transaction(function () use ($id, $lead, $d, $isMinimal, $stage) {
            DB::table('crm_leads')->where('id', $id)->update([
                'shop_name' => trim($d['shop_name']),
                'phone' => $d['phone'] ?? null,
                'contact_name' => array_key_exists('contact_name', $d) ? $d['contact_name'] : ($lead->contact_name ?? null),
                'shop_type' => $d['shop_type'] ?? null,
                'line_contact' => $d['line_contact'] ?? null,
                'province' => array_key_exists('province', $d) ? $d['province'] : ($lead->province ?? null),
                'district' => $d['district'] ?? null,
                'address' => $d['address'] ?? null,
                'supplier_source' => array_key_exists('supplier_source', $d) ? $d['supplier_source'] : ($lead->supplier_source ?? null),
                'product_categories' => array_key_exists('product_categories', $d) ? $d['product_categories'] : ($lead->product_categories ?? null),
                'assigned_salesperson_code' => array_key_exists('assigned_salesperson_code', $d) ? $d['assigned_salesperson_code'] : ($lead->assigned_salesperson_code ?? null),
                'notes' => $d['notes'] ?? null,
                'pipeline_stage' => $stage,
                'next_follow_up_date' => $d['next_follow_up_date'] ?? null,
                'is_minimal_draft' => $isMinimal,
                'updated_by' => $this->user()->id,
                'updated_at' => now(),
            ]);

            CustomerMasterController::syncLeadMaster($id, $this->user()->id);
            $this->recordAudit('crm_lead', $id, 'updated', $d);
        });

        return response()->json(['ok' => true, 'is_minimal_draft' => $isMinimal]);
    }

    public function updateLeadStage(Request $r, int $id): JsonResponse
    {
        abort_unless($this->leadAllowed($id), 403);
        $lead = DB::table('crm_leads')->where('id', $id)->first();
        abort_unless($lead, 404);

        $d = $r->validate([
            'pipeline_stage' => ['required', Rule::in(self::PIPELINE_STAGES)],
            'next_follow_up_date' => 'nullable|date_format:Y-m-d',
            'follow_up_action' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($id, $lead, $d) {
            DB::table('crm_leads')->where('id', $id)->update([
                'pipeline_stage' => $d['pipeline_stage'],
                'next_follow_up_date' => $d['next_follow_up_date'] ?? $lead->next_follow_up_date,
                'updated_by' => $this->user()->id,
                'updated_at' => now(),
            ]);

            if (!empty($d['follow_up_action'])) {
                DB::table('crm_follow_up_tasks')->insert([
                    'crm_lead_id' => $id,
                    'assigned_to' => $lead->owner_staff_id,
                    'title' => $d['follow_up_action'],
                    'due_date' => $d['next_follow_up_date'] ?? null,
                    'status' => 'open',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->recordAudit('crm_lead', $id, 'stage_changed', [
                'from' => $lead->pipeline_stage,
                'to' => $d['pipeline_stage'],
            ]);
        });

        return response()->json(['ok' => true]);
    }

    public function reviewLead(Request $r, int $id): JsonResponse
    {
        if (!$this->manager()) {
            abort(403, 'เฉพาะผู้จัดการหรือผู้ดูแลระบบเท่านั้นที่อนุมัติได้');
        }
        $lead = DB::table('crm_leads')->where('id', $id)->first();
        abort_unless($lead, 404);

        $d = $r->validate([
            'approval_status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
            'approval_note' => 'nullable|string|max:2000',
            'converted_erp_customer_id' => 'prohibited',
        ]);

        DB::transaction(function () use ($id, $lead, $d) {
            $convertedId = $lead->converted_erp_customer_id;

            $newStage = $lead->pipeline_stage;
            if ($convertedId && $newStage !== 'lost') {
                $newStage = 'converted';
            }

            DB::table('crm_leads')->where('id', $id)->update([
                'approval_status' => $d['approval_status'],
                'approval_note' => $d['approval_note'] ?? null,
                'approved_by' => $this->user()->id,
                'approved_at' => now(),
                'converted_erp_customer_id' => $convertedId,
                'pipeline_stage' => $newStage,
                'updated_by' => $this->user()->id,
                'updated_at' => now(),
            ]);

            CustomerMasterController::syncLeadMaster($id, $this->user()->id);

            $this->recordAudit('crm_lead', $id, 'reviewed', [
                'approval_status' => $d['approval_status'],
                'converted_erp_customer_id' => $convertedId,
                'approval_note' => $d['approval_note'] ?? null,
            ]);
        });

        return response()->json(['ok' => true]);
    }

    public function leadPhotos(int $id): JsonResponse
    {
        abort_unless($this->leadAllowed($id), 403);
        $rows = DB::table('crm_lead_photos')
            ->where('crm_lead_id', $id)
            ->orderByDesc('id')
            ->get();

        return response()->json($rows);
    }

    public function uploadLeadPhoto(Request $r, int $id): JsonResponse
    {
        abort_unless($this->leadAllowed($id), 403);
        $d = $r->validate([
            'photo' => 'required|image|mimes:jpeg,png,webp|max:5120',
            'category' => ['nullable', Rule::in(['storefront', 'interior', 'products', 'competitor', 'customer_discussion', 'other'])],
            'consent_confirmed' => 'nullable',
            'captured_at' => 'nullable|date',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy_m' => 'nullable|integer|min:0|max:100000',
            'compressed_on_client' => 'nullable',
        ]);
        $cat = $d['category'] ?? 'storefront';
        $consent = filter_var($r->input('consent_confirmed', false), FILTER_VALIDATE_BOOLEAN);
        if ($cat === 'customer_discussion' && ! $consent) {
            throw ValidationException::withMessages([
                'consent_confirmed' => 'กรุณายืนยันว่าได้รับความยินยอมจากลูกค้าก่อนแนบภาพถ่ายร่วมกับลูกค้า',
            ]);
        }
        $hasLat = isset($d['latitude']) && isset($d['longitude']);
        $file = $r->file('photo');
        $path = $file->store('leads/' . $id, 'local');
        $photoId = DB::table('crm_lead_photos')->insertGetId([
            'crm_lead_id' => $id,
            'storage_path' => $path,
            'category' => $cat,
            'original_name' => $file->getClientOriginalName(),
            'consent_confirmed' => $consent,
            'captured_at' => $d['captured_at'] ?? now(),
            'latitude' => $hasLat ? (float) $d['latitude'] : null,
            'longitude' => $hasLat ? (float) $d['longitude'] : null,
            'accuracy_m' => ($hasLat && isset($d['accuracy_m'])) ? (int) $d['accuracy_m'] : null,
            'mime_type' => $file->getMimeType(),
            'file_size_bytes' => $file->getSize(),
            'compressed_on_client' => filter_var($r->input('compressed_on_client', false), FILTER_VALIDATE_BOOLEAN),
            'uploaded_by' => $this->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->recordAudit('crm_lead', $id, 'photo_uploaded', ['photo_id' => $photoId, 'category' => $cat]);
        return response()->json(['id' => $photoId, 'category' => $cat], 201);
    }

    public function downloadLeadPhoto(Request $r, int $photoId)
    {
        $p = DB::table('crm_lead_photos')->where('id', $photoId)->first();
        abort_unless($p, 404);
        abort_unless($this->leadAllowed((int) $p->crm_lead_id), 403);
        abort_unless(Storage::disk('local')->exists($p->storage_path), 404);
        if ($r->boolean('inline')) {
            return response()->file(Storage::disk('local')->path($p->storage_path), [
                'Cache-Control' => 'private, max-age=300',
            ]);
        }
        return Storage::disk('local')->download($p->storage_path);
    }

    public function addLeadTask(Request $r, int $id): JsonResponse
    {
        abort_unless($this->leadAllowed($id), 403);
        $d = $r->validate([
            'title' => 'required|string|max:255',
            'due_date' => 'nullable|date_format:Y-m-d',
            'description' => 'nullable|string|max:2000',
        ]);

        $taskId = DB::table('crm_follow_up_tasks')->insertGetId([
            'crm_lead_id' => $id,
            'assigned_to' => $this->user()->id,
            'title' => $d['title'],
            'description' => $d['description'] ?? null,
            'due_date' => $d['due_date'] ?? null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->recordAudit('crm_lead', $id, 'task_added', ['task_id' => $taskId, 'title' => $d['title']]);
        return response()->json(['id' => $taskId], 201);
    }

    public function importTemplate()
    {
        $csv = "\xEF\xBB\xBF" . "shop_name,phone,shop_type,district,address,line_contact,notes\n"
            . "ร้านตัวอย่าง (ลบแถวนี้ก่อนนำเข้า),0812345678,ร้านขายของชำ,เมือง,123 ถ.ตัวอย่าง,@sample,หมายเหตุ\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="popstar-leads-template.csv"',
        ]);
    }

    public function importLeads(Request $r): JsonResponse
    {
        $r->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $raw = file_get_contents($r->file('file')->getRealPath());
        if ($raw === false || trim($raw) === '') {
            return response()->json(['error' => 'ไฟล์ว่างเปล่า'], 422);
        }

        // Handle UTF-8 BOM or Thai Windows-874/TIS-620 encoding
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'TIS-620,Windows-874,ISO-8859-1');
        }

        $lines = preg_split("/\r\n|\n|\r/", trim($raw));
        if (count($lines) < 2) {
            return response()->json(['error' => 'ต้องมีหัวตารางและข้อมูลอย่างน้อย 1 แถว'], 422);
        }

        $headers = array_map(fn ($h) => mb_strtolower(trim((string) $h)), str_getcsv(array_shift($lines)));
        $colMap = [
            'shop_name' => ['shop_name', 'ชื่อร้าน', 'ชื่อร้านค้า'],
            'phone' => ['phone', 'เบอร์โทร', 'เบอร์โทรศัพท์', 'tel'],
            'shop_type' => ['shop_type', 'ประเภทร้าน', 'ประเภทร้านค้า'],
            'district' => ['district', 'อำเภอ'],
            'address' => ['address', 'ที่อยู่'],
            'line_contact' => ['line_contact', 'line', 'ไลน์'],
            'notes' => ['notes', 'หมายเหตุ'],
        ];

        $resolveIndex = function (array $aliases) use ($headers): ?int {
            foreach ($aliases as $a) {
                $idx = array_search($a, $headers, true);
                if ($idx !== false) {
                    return (int) $idx;
                }
            }
            return null;
        };

        $idxShop = $resolveIndex($colMap['shop_name']);
        if ($idxShop === null) {
            return response()->json(['error' => 'ไม่พบคอลัมน์ shop_name หรือ ชื่อร้านค้า'], 422);
        }
        $idxPhone = $resolveIndex($colMap['phone']);
        $idxType = $resolveIndex($colMap['shop_type']);
        $idxDistrict = $resolveIndex($colMap['district']);
        $idxAddress = $resolveIndex($colMap['address']);
        $idxLine = $resolveIndex($colMap['line_contact']);
        $idxNotes = $resolveIndex($colMap['notes']);

        $imported = 0;
        $skipped = 0;
        $skippedList = [];
        $userId = $this->user()->id;

        DB::transaction(function () use (
            $lines, $idxShop, $idxPhone, $idxType, $idxDistrict, $idxAddress, $idxLine, $idxNotes,
            $userId, &$imported, &$skipped, &$skippedList
        ) {
            $now = now();
            foreach (array_slice($lines, 0, 500) as $lineNo => $line) {
                if (trim($line) === '') {
                    continue;
                }
                $cols = str_getcsv($line);
                $shopName = trim((string) ($cols[$idxShop] ?? ''));
                if ($shopName === '') {
                    continue;
                }
                $phone = $idxPhone !== null ? trim((string) ($cols[$idxPhone] ?? '')) : null;
                $shopType = $idxType !== null ? trim((string) ($cols[$idxType] ?? '')) : null;
                $district = $idxDistrict !== null ? trim((string) ($cols[$idxDistrict] ?? '')) : null;
                $address = $idxAddress !== null ? trim((string) ($cols[$idxAddress] ?? '')) : null;
                $lineContact = $idxLine !== null ? trim((string) ($cols[$idxLine] ?? '')) : null;
                $notes = $idxNotes !== null ? trim((string) ($cols[$idxNotes] ?? '')) : null;

                $dups = $this->findPotentialDuplicates($shopName, $phone);
                if ($dups !== []) {
                    $skipped++;
                    $skippedList[] = [
                        'row' => $lineNo + 2,
                        'shop_name' => $shopName,
                        'reason' => implode(', ', $dups[0]['match_reasons']),
                    ];
                    continue;
                }

                $isMinimal = $this->computeIsMinimalDraft([
                    'district' => $district,
                    'address' => $address,
                    'line_contact' => $lineContact,
                ]);

                $leadId = DB::table('crm_leads')->insertGetId([
                    'shop_name' => mb_substr($shopName, 0, 255),
                    'phone' => $phone ? mb_substr($phone, 0, 40) : null,
                    'shop_type' => $shopType ? mb_substr($shopType, 0, 64) : null,
                    'district' => $district ? mb_substr($district, 0, 120) : null,
                    'address' => $address ?: null,
                    'line_contact' => $lineContact ? mb_substr($lineContact, 0, 120) : null,
                    'notes' => $notes ?: null,
                    'status' => 'new',
                    'pipeline_stage' => 'new_lead',
                    'approval_status' => 'pending',
                    'is_minimal_draft' => $isMinimal,
                    'owner_staff_id' => $userId,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->recordAudit('crm_lead', $leadId, 'csv_imported', ['shop_name' => $shopName]);
                $imported++;
            }
        });

        return response()->json([
            'ok' => true,
            'imported' => $imported,
            'skipped_duplicates' => $skipped,
            'skipped_details' => array_slice($skippedList, 0, 30),
        ]);
    }

    private function upsertCustomerLocation(
        ?int $erpCustomerId,
        ?int $crmLeadId,
        float $latitude,
        float $longitude,
        ?int $accuracyM,
        string $source,
        int $staffUserId,
        ?string $address = null,
        ?\DateTimeInterface $timestamp = null,
        bool $preserveManualPin = false
    ): void {
        $now = $timestamp ?? now();
        $where = $erpCustomerId !== null
            ? ['erp_customer_id' => $erpCustomerId]
            : ['crm_lead_id' => $crmLeadId];

        $existing = DB::table('crm_customer_locations')->where($where)->first();
        if ($existing && $preserveManualPin && in_array($existing->source, ['manual', 'map_link'], true)) {
            return;
        }

        $payload = [
            'erp_customer_id' => $erpCustomerId,
            'crm_lead_id' => $crmLeadId,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy_m' => $accuracyM,
            'source' => $source,
            'pinned_by' => $staffUserId,
            'pinned_at' => $now,
            'updated_at' => $now,
        ];
        if ($address !== null) {
            $payload['address'] = $address;
        }

        if ($existing) {
            DB::table('crm_customer_locations')->where('id', $existing->id)->update($payload);
        } else {
            DB::table('crm_customer_locations')->insert([
                ...$payload,
                'created_at' => $now,
            ]);
        }
    }

    public function pins(): JsonResponse
    {
        $isManager = $this->manager();
        $user = $this->user();
        $applyScope = function ($q) use ($user): void {
            CustomerAccess::applyCustomerAndLeadScope($q, 'c', 'd', $user);
        };

        $pinnedQuery = DB::table('crm_customer_locations as l')
            ->leftJoin('erp_customers as c', 'c.id', '=', 'l.erp_customer_id')
            ->leftJoin('crm_leads as d', 'd.id', '=', 'l.crm_lead_id');
        $applyScope($pinnedQuery);

        $pins = $pinnedQuery->select(
            'l.id',
            'l.erp_customer_id',
            'l.crm_lead_id',
            'l.latitude',
            'l.longitude',
            'l.accuracy_m',
            'l.address',
            'l.source',
            'l.pinned_at',
            'c.customer_name',
            'c.erp_customer_code',
            'c.phone as erp_phone',
            'c.province as erp_province',
            'c.address as erp_address',
            'c.salesperson_name as erp_salesperson_name',
            'd.shop_name',
            'd.shop_type',
            'd.pipeline_stage',
            'd.approval_status',
            'd.converted_erp_customer_id',
            'd.phone as lead_phone',
            'd.province as lead_province',
            'd.district as lead_district',
            'd.address as lead_address',
            'd.owner_staff_id as lead_owner_staff_id'
        )->limit(1000)->get()->all();

        $seen = [];
        foreach ($pins as $pin) {
            if ($pin->erp_customer_id !== null) {
                $seen['erp:'.$pin->erp_customer_id] = true;
            }
            if ($pin->crm_lead_id !== null) {
                $seen['lead:'.$pin->crm_lead_id] = true;
            }
        }

        if (count($pins) < 1000) {
            $visitQuery = DB::table('crm_customer_visits as v')
                ->leftJoin('erp_customers as c', 'c.id', '=', 'v.erp_customer_id')
                ->leftJoin('crm_leads as d', 'd.id', '=', 'v.crm_lead_id')
                ->whereNotNull('v.checkin_latitude')
                ->whereNotNull('v.checkin_longitude')
                ->orderByDesc('v.visited_at')
                ->orderByDesc('v.id');
            $applyScope($visitQuery);

            $visitPins = $visitQuery->select(
                'v.id',
                'v.erp_customer_id',
                'v.crm_lead_id',
                'v.checkin_latitude as latitude',
                'v.checkin_longitude as longitude',
                'v.checkin_accuracy_m as accuracy_m',
                DB::raw('NULL as address'),
                DB::raw("'visit_gps' as source"),
                DB::raw('COALESCE(v.checkin_at, v.visited_at) as pinned_at'),
                'c.customer_name',
                'c.erp_customer_code',
                'c.phone as erp_phone',
                'c.province as erp_province',
                'c.address as erp_address',
                'c.salesperson_name as erp_salesperson_name',
                'd.shop_name',
                'd.shop_type',
                'd.pipeline_stage',
                'd.approval_status',
                'd.converted_erp_customer_id',
                'd.phone as lead_phone',
                'd.province as lead_province',
                'd.district as lead_district',
                'd.address as lead_address',
                'd.owner_staff_id as lead_owner_staff_id'
            )->limit(1000)->get();

            foreach ($visitPins as $vp) {
                $key = $vp->erp_customer_id !== null
                    ? 'erp:'.$vp->erp_customer_id
                    : ($vp->crm_lead_id !== null ? 'lead:'.$vp->crm_lead_id : null);
                if ($key === null || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $vp->id = 'visit-'.$vp->id;
                $pins[] = $vp;
                if (count($pins) >= 1000) {
                    break;
                }
            }
        }

        return response()->json($this->enrichPinsWithVisitsAndPhotos($pins, $isManager, (int) $this->user()->id));
    }

    private function enrichPinsWithVisitsAndPhotos(array $pins, bool $isManager, int $userId): array
    {
        if ($pins === []) {
            return [];
        }

        $erpIds = [];
        $leadIds = [];
        $authorizedLeadIds = [];
        foreach ($pins as $pin) {
            if ($pin->erp_customer_id !== null) {
                $erpIds[(int) $pin->erp_customer_id] = true;
            }
            if ($pin->crm_lead_id !== null) {
                $leadId = (int) $pin->crm_lead_id;
                $leadIds[$leadId] = true;
                if ($isManager || (int) ($pin->lead_owner_staff_id ?? 0) === $userId) {
                    $authorizedLeadIds[$leadId] = true;
                }
            }
        }

        $visitCountByKey = [];
        $latestVisitByKey = [];
        $visitPinKeys = [];

        if ($erpIds !== [] || $leadIds !== []) {
            $visits = DB::table('crm_customer_visits as v')
                ->leftJoin('staff_users as s', 's.id', '=', 'v.staff_user_id')
                ->where(function ($w) use ($erpIds, $leadIds): void {
                    if ($erpIds !== []) {
                        $w->orWhereIn('v.erp_customer_id', array_keys($erpIds));
                    }
                    if ($leadIds !== []) {
                        $w->orWhereIn('v.crm_lead_id', array_keys($leadIds));
                    }
                })
                ->when(! $isManager, fn ($q) => $q->where('v.staff_user_id', $userId))
                ->select(
                    'v.id',
                    'v.erp_customer_id',
                    'v.crm_lead_id',
                    'v.visited_at',
                    'v.checkin_at',
                    'v.result',
                    'v.note',
                    'v.follow_up_action',
                    'v.follow_up_date',
                    'v.supplier_name',
                    'v.product_types',
                    's.name as staff_name'
                )
                ->orderByDesc('v.visited_at')
                ->orderByDesc('v.id')
                ->limit(2000)
                ->get();

            foreach ($visits as $v) {
                $keys = [];
                if ($v->erp_customer_id !== null) {
                    $keys[] = 'erp:'.$v->erp_customer_id;
                }
                if ($v->crm_lead_id !== null) {
                    $keys[] = 'lead:'.$v->crm_lead_id;
                }
                foreach ($keys as $k) {
                    $visitCountByKey[$k] = ($visitCountByKey[$k] ?? 0) + 1;
                    if (! isset($latestVisitByKey[$k])) {
                        $latestVisitByKey[$k] = [
                            'id' => (int) $v->id,
                            'visited_at' => $v->visited_at,
                            'checkin_at' => $v->checkin_at,
                            'result' => $v->result,
                            'staff_name' => $v->staff_name,
                            'note' => $v->note,
                            'follow_up_action' => $v->follow_up_action,
                            'follow_up_date' => $v->follow_up_date,
                            'supplier_name' => $v->supplier_name,
                            'product_types' => $v->product_types,
                        ];
                    }
                    $visitPinKeys[(int) $v->id][] = $k;
                }
            }
        }

        $photosByKey = [];
        $appendPhoto = static function (string $key, array $photo) use (&$photosByKey): void {
            $dedup = $photo['kind'].':'.$photo['id'];
            $photosByKey[$key][$dedup] = $photo;
        };

        if ($visitPinKeys !== []) {
            $visitPhotos = DB::table('crm_visit_photos')
                ->whereIn('visit_id', array_keys($visitPinKeys))
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->limit(3000)
                ->get();

            foreach ($visitPhotos as $p) {
                $item = [
                    'id' => (int) $p->id,
                    'kind' => 'visit',
                    'url' => "/api/crm/photos/{$p->id}?inline=1",
                    'category' => $p->category,
                    'captured_at' => $p->captured_at ?? $p->created_at,
                    'consent_confirmed' => (bool) $p->consent_confirmed,
                    'compressed_on_client' => (bool) ($p->compressed_on_client ?? false),
                    'latitude' => $p->latitude,
                    'longitude' => $p->longitude,
                    'visit_id' => (int) $p->visit_id,
                ];
                foreach ($visitPinKeys[(int) $p->visit_id] ?? [] as $k) {
                    $appendPhoto($k, $item);
                }
            }
        }

        if ($authorizedLeadIds !== []) {
            $leadPhotos = DB::table('crm_lead_photos')
                ->whereIn('crm_lead_id', array_keys($authorizedLeadIds))
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->limit(2000)
                ->get();

            foreach ($leadPhotos as $p) {
                $appendPhoto('lead:'.$p->crm_lead_id, [
                    'id' => (int) $p->id,
                    'kind' => 'lead',
                    'url' => "/api/crm/lead-photos/{$p->id}?inline=1",
                    'category' => $p->category,
                    'captured_at' => $p->captured_at ?? $p->created_at,
                    'consent_confirmed' => (bool) $p->consent_confirmed,
                    'compressed_on_client' => (bool) ($p->compressed_on_client ?? false),
                    'latitude' => $p->latitude,
                    'longitude' => $p->longitude,
                    'crm_lead_id' => (int) $p->crm_lead_id,
                ]);
            }
        }

        foreach ($pins as $pin) {
            $keys = [];
            if ($pin->erp_customer_id !== null) {
                $keys[] = 'erp:'.$pin->erp_customer_id;
            }
            if ($pin->crm_lead_id !== null) {
                $keys[] = 'lead:'.$pin->crm_lead_id;
            }

            $latestVisit = null;
            $visitCount = 0;
            $mergedPhotos = [];
            foreach ($keys as $k) {
                $visitCount = max($visitCount, $visitCountByKey[$k] ?? 0);
                if ($latestVisit === null && isset($latestVisitByKey[$k])) {
                    $latestVisit = $latestVisitByKey[$k];
                }
                foreach ($photosByKey[$k] ?? [] as $dedup => $photo) {
                    $mergedPhotos[$dedup] = $photo;
                }
            }

            $photoList = array_values($mergedPhotos);
            usort($photoList, static fn ($a, $b) => strcmp((string) ($b['captured_at'] ?? ''), (string) ($a['captured_at'] ?? '')) ?: ($b['id'] <=> $a['id']));

            $pin->phone = $pin->erp_phone ?? $pin->lead_phone ?? null;
            $pin->province = $pin->erp_province ?? $pin->lead_province ?? null;
            $pin->district = $pin->lead_district ?? null;
            $pin->shop_address = $pin->address ?? $pin->lead_address ?? $pin->erp_address ?? null;
            $pin->salesperson_name = $pin->erp_salesperson_name ?? null;
            $pin->visit_count = $visitCount;
            $pin->latest_visit = $latestVisit;
            $pin->photo_count = count($photoList);
            $pin->photos = array_slice($photoList, 0, 12);

            unset(
                $pin->erp_phone,
                $pin->erp_province,
                $pin->erp_address,
                $pin->erp_salesperson_name,
                $pin->lead_phone,
                $pin->lead_province,
                $pin->lead_district,
                $pin->lead_address,
                $pin->lead_owner_staff_id
            );
        }

        return $pins;
    }

    public function storePin(Request $r): JsonResponse
    {
        $d = $r->validate([
            'erp_customer_id' => 'nullable|integer|exists:erp_customers,id',
            'crm_lead_id' => 'nullable|integer|exists:crm_leads,id',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy_m' => 'nullable|integer|min:0|max:100000',
            'address' => 'nullable|string|max:2000',
            'source' => ['required', Rule::in(['gps', 'manual', 'map_link'])],
        ]);
        $this->checkSubject($d);
        $this->upsertCustomerLocation(
            ! empty($d['erp_customer_id']) ? (int) $d['erp_customer_id'] : null,
            ! empty($d['crm_lead_id']) ? (int) $d['crm_lead_id'] : null,
            (float) $d['latitude'],
            (float) $d['longitude'],
            isset($d['accuracy_m']) ? (int) $d['accuracy_m'] : null,
            $d['source'],
            $this->user()->id,
            $d['address'] ?? null
        );
        if (!empty($d['crm_lead_id'])) {
            $this->recordAudit('crm_lead', (int) $d['crm_lead_id'], 'pin_updated', [
                'latitude' => $d['latitude'],
                'longitude' => $d['longitude'],
                'source' => $d['source'],
            ]);
        }
        return response()->json(['ok' => true]);
    }

    public function plans(Request $r): JsonResponse
    {
        $q = DB::table('crm_visit_plans');
        if (!$this->manager()) {
            $q->where('assigned_to', $this->user()->id);
        }
        return response()->json($q->orderByDesc('visit_date')->limit(100)->get());
    }

    public function storePlan(Request $r): JsonResponse
    {
        if (!$this->manager()) {
            abort(403);
        }
        $d = $r->validate([
            'route_name' => 'required|string|max:160',
            'visit_date' => 'required|date_format:Y-m-d',
            'district' => 'nullable|string|max:120',
            'assigned_to' => 'required|integer|exists:staff_users,id',
            'note' => 'nullable|string|max:2000',
        ]);
        $id = DB::table('crm_visit_plans')->insertGetId([
            ...$d,
            'created_by' => $this->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function addStop(Request $r, int $planId): JsonResponse
    {
        $p = DB::table('crm_visit_plans')->where('id', $planId)->first();
        abort_unless($p, 404);
        if (!$this->manager() && (int) $p->assigned_to !== (int) $this->user()->id) {
            abort(403);
        }
        $d = $r->validate([
            'stop_order' => 'required|integer|min:1|max:500',
            'erp_customer_id' => 'nullable|integer|exists:erp_customers,id',
            'crm_lead_id' => 'nullable|integer|exists:crm_leads,id',
        ]);
        $this->checkSubject($d);
        $id = DB::table('crm_visit_plan_stops')->insertGetId([
            ...$d,
            'plan_id' => $planId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function storeFieldVisit(Request $r): JsonResponse
    {
        $d = $r->validate([
            'erp_customer_id' => 'nullable|integer|exists:erp_customers,id',
            'crm_lead_id' => 'nullable|integer|exists:crm_leads,id',
            'plan_stop_id' => 'nullable|integer|exists:crm_visit_plan_stops,id',
            'client_draft_id' => 'nullable|string|max:80',
            'visited_at' => 'required|date',
            'device_recorded_at' => 'nullable|date',
            'result' => ['required', Rule::in(['visited', 'follow_up', 'interested', 'not_interested', 'not_found'])],
            'shop_type' => ['required', Rule::in(['existing', 'new'])],
            'shop_phone' => 'nullable|string|max:40',
            'supplier_name' => 'nullable|string|max:255',
            'product_types' => 'nullable|string|max:4000',
            'customer_issues' => 'nullable|string|max:4000',
            'customer_feedback' => 'nullable|string|max:4000',
            'follow_up_action' => 'nullable|string|max:2000',
            'follow_up_date' => 'nullable|date_format:Y-m-d',
            'note' => 'nullable|string|max:2000',
            'checkin_latitude' => 'nullable|numeric|between:-90,90',
            'checkin_longitude' => 'nullable|numeric|between:-180,180',
            'checkin_accuracy_m' => 'nullable|integer|min:0|max:100000',
            'gps_permission_status' => ['nullable', Rule::in(['granted', 'denied', 'prompt', 'unavailable'])],
        ]);
        $this->checkSubject($d);
        if (isset($d['checkin_latitude']) !== isset($d['checkin_longitude'])) {
            throw ValidationException::withMessages(['gps' => 'Latitude and longitude must be supplied together']);
        }

        // Idempotency for offline draft queue replay
        $draftId = isset($d['client_draft_id']) && trim((string) $d['client_draft_id']) !== ''
            ? trim((string) $d['client_draft_id'])
            : null;
        if ($draftId !== null) {
            $existing = DB::table('crm_customer_visits')->where('client_draft_id', $draftId)->first();
            if ($existing) {
                if ((int) $existing->staff_user_id !== (int) $this->user()->id) {
                    return response()->json(['error' => 'Draft ID conflict'], 409);
                }
                return response()->json([
                    'ok' => true,
                    'id' => $existing->id,
                    'deduplicated' => true,
                    'server_recorded_at' => $existing->server_recorded_at ?? $existing->created_at,
                    'device_recorded_at' => $existing->device_recorded_at,
                ], 200);
            }
        }

        if (!empty($d['plan_stop_id'])) {
            $stop = DB::table('crm_visit_plan_stops as s')
                ->join('crm_visit_plans as p', 'p.id', '=', 's.plan_id')
                ->where('s.id', $d['plan_stop_id'])
                ->first();
            abort_unless($stop, 404);
            if (!$this->manager() && (int) $stop->assigned_to !== (int) $this->user()->id) {
                abort(403);
            }
            if ((int) $stop->erp_customer_id !== (int) ($d['erp_customer_id'] ?? 0) || (int) $stop->crm_lead_id !== (int) ($d['crm_lead_id'] ?? 0)) {
                throw ValidationException::withMessages(['plan_stop_id' => 'Stop must match customer or lead']);
            }
        }

        $serverRecordedAt = now();
        $masterId = null;
        if (!empty($d['crm_lead_id'])) {
            $masterId = DB::table('crm_customer_masters')->where('crm_lead_id', $d['crm_lead_id'])->value('id');
        } elseif (!empty($d['erp_customer_id'])) {
            $masterId = DB::table('crm_customer_masters')->where('erp_customer_id', $d['erp_customer_id'])->value('id');
        }

        $id = DB::transaction(function () use ($d, $draftId, $serverRecordedAt, $masterId) {
            $id = DB::table('crm_customer_visits')->insertGetId([
                ...$d,
                'client_draft_id' => $draftId,
                'customer_master_id' => $masterId,
                'staff_user_id' => $this->user()->id,
                'device_recorded_at' => $d['device_recorded_at'] ?? null,
                'server_recorded_at' => $serverRecordedAt,
                'gps_permission_status' => $d['gps_permission_status'] ?? (isset($d['checkin_latitude']) ? 'granted' : null),
                'checkin_at' => isset($d['checkin_latitude']) ? $serverRecordedAt : null,
                'created_at' => $serverRecordedAt,
                'updated_at' => $serverRecordedAt,
            ]);
            if (! empty($d['crm_lead_id'])) {
                DB::table('crm_leads')->where('id', $d['crm_lead_id'])->where('pipeline_stage', 'new_lead')->update([
                    'pipeline_stage' => 'visited', 'updated_by' => $this->user()->id, 'updated_at' => $serverRecordedAt,
                ]);
            }
            if (isset($d['checkin_latitude'], $d['checkin_longitude'])) {
                $this->upsertCustomerLocation(
                    ! empty($d['erp_customer_id']) ? (int) $d['erp_customer_id'] : null,
                    ! empty($d['crm_lead_id']) ? (int) $d['crm_lead_id'] : null,
                    (float) $d['checkin_latitude'],
                    (float) $d['checkin_longitude'],
                    isset($d['checkin_accuracy_m']) ? (int) $d['checkin_accuracy_m'] : null,
                    'gps',
                    $this->user()->id,
                    null,
                    $serverRecordedAt,
                    preserveManualPin: true
                );
            }
            if (!empty($d['follow_up_action'])) {
                DB::table('crm_follow_up_tasks')->insert([
                    'visit_id' => $id,
                    'crm_lead_id' => $d['crm_lead_id'] ?? null,
                    'assigned_to' => $this->user()->id,
                    'title' => $d['follow_up_action'],
                    'due_date' => $d['follow_up_date'] ?? null,
                    'created_at' => $serverRecordedAt,
                    'updated_at' => $serverRecordedAt,
                ]);
            }
            if (!empty($d['plan_stop_id'])) {
                DB::table('crm_visit_plan_stops')->where('id', $d['plan_stop_id'])->update([
                    'status' => 'visited',
                    'updated_at' => $serverRecordedAt,
                ]);
            }
            $this->recordAudit('crm_visit', $id, 'created', [
                'result' => $d['result'],
                'has_gps' => isset($d['checkin_latitude']),
                'gps_permission_status' => $d['gps_permission_status'] ?? null,
                'client_draft_id' => $draftId,
            ]);
            return $id;
        });

        return response()->json([
            'ok' => true,
            'id' => $id,
            'deduplicated' => false,
            'server_recorded_at' => $serverRecordedAt->toIso8601String(),
            'device_recorded_at' => $d['device_recorded_at'] ?? null,
        ], 201);
    }

    private function visitAllowed(int $id): bool
    {
        $v = DB::table('crm_customer_visits as v')
            ->leftJoin('erp_customers as c', 'c.id', '=', 'v.erp_customer_id')
            ->leftJoin('crm_leads as l', 'l.id', '=', 'v.crm_lead_id')
            ->where('v.id', $id);
        if (!$this->manager()) {
            $v->where('v.staff_user_id', $this->user()->id);
        }
        return $v->exists();
    }

    public function visitPhotos(int $visitId): JsonResponse
    {
        abort_unless($this->visitAllowed($visitId), 403);
        $rows = DB::table('crm_visit_photos')
            ->where('visit_id', $visitId)
            ->orderByDesc('id')
            ->get();

        return response()->json($rows);
    }

    public function uploadPhoto(Request $r, int $visitId): JsonResponse
    {
        abort_unless($this->visitAllowed($visitId), 403);
        $d = $r->validate([
            'photo' => 'required|image|mimes:jpeg,png,webp|max:5120',
            'category' => ['required', Rule::in(['storefront', 'interior', 'products', 'competitor', 'customer_discussion', 'other'])],
            'consent_confirmed' => 'nullable',
            'captured_at' => 'nullable|date',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy_m' => 'nullable|integer|min:0|max:100000',
            'image_width' => 'nullable|integer|min:1|max:20000',
            'image_height' => 'nullable|integer|min:1|max:20000',
            'compressed_on_client' => 'nullable',
        ]);

        $consent = filter_var($r->input('consent_confirmed', false), FILTER_VALIDATE_BOOLEAN);
        if ($d['category'] === 'customer_discussion' && ! $consent) {
            throw ValidationException::withMessages([
                'consent_confirmed' => 'กรุณายืนยันว่าได้รับความยินยอมจากลูกค้าก่อนอัปโหลดภาพถ่ายคู่กับลูกค้า',
            ]);
        }

        $hasLat = isset($d['latitude']) && isset($d['longitude']);
        $file = $r->file('photo');
        $mimeType = $file->getMimeType();
        $sizeBytes = $file->getSize();
        $path = $file->store('visits/' . $visitId, 'local');

        $id = DB::table('crm_visit_photos')->insertGetId([
            'visit_id' => $visitId,
            'storage_path' => $path,
            'category' => $d['category'],
            'original_name' => $file->getClientOriginalName(),
            'consent_confirmed' => $consent,
            'captured_at' => $d['captured_at'] ?? now(),
            'latitude' => $hasLat ? (float) $d['latitude'] : null,
            'longitude' => $hasLat ? (float) $d['longitude'] : null,
            'accuracy_m' => ($hasLat && isset($d['accuracy_m'])) ? (int) $d['accuracy_m'] : null,
            'mime_type' => $mimeType,
            'file_size_bytes' => $sizeBytes,
            'image_width' => $d['image_width'] ?? null,
            'image_height' => $d['image_height'] ?? null,
            'compressed_on_client' => filter_var($r->input('compressed_on_client', false), FILTER_VALIDATE_BOOLEAN),
            'uploaded_by' => $this->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'id' => $id,
            'visit_id' => $visitId,
            'category' => $d['category'],
            'consent_confirmed' => $consent,
            'mime_type' => $mimeType,
            'file_size_bytes' => $sizeBytes,
        ], 201);
    }

    public function downloadPhoto(Request $r, int $photoId)
    {
        $p = DB::table('crm_visit_photos')->where('id', $photoId)->first();
        abort_unless($p, 404);
        abort_unless($this->visitAllowed((int) $p->visit_id), 403);
        abort_unless(Storage::disk('local')->exists($p->storage_path), 404);
        if ($r->boolean('inline')) {
            return response()->file(Storage::disk('local')->path($p->storage_path), [
                'Cache-Control' => 'private, max-age=300',
            ]);
        }
        return Storage::disk('local')->download($p->storage_path);
    }

    public function tasks(): JsonResponse
    {
        $q = DB::table('crm_follow_up_tasks as t')
            ->leftJoin('crm_leads as l', 'l.id', '=', 't.crm_lead_id')
            ->select('t.*', 'l.shop_name as lead_shop_name');
        if (!$this->manager()) {
            $q->where('t.assigned_to', $this->user()->id);
        }
        return response()->json($q->orderBy('t.due_date')->limit(200)->get());
    }

    public function completeTask(Request $r, int $taskId): JsonResponse
    {
        $q = DB::table('crm_follow_up_tasks')->where('id', $taskId);
        $task = $q->first();
        abort_unless($task, 404);
        if (!$this->manager() && (int) $task->assigned_to !== (int) $this->user()->id) {
            abort(403);
        }
        $q->update(['status' => 'done', 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function fieldVisitReport(Request $r)
    {
        $filters = $r->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'integer'],
            'format' => ['nullable', 'in:json,csv,print'],
        ]);

        $q = DB::table('crm_customer_visits as v')
            ->leftJoin('staff_users as s', 's.id', '=', 'v.staff_user_id')
            ->leftJoin('erp_customers as c', 'c.id', '=', 'v.erp_customer_id')
            ->leftJoin('crm_leads as l', 'l.id', '=', 'v.crm_lead_id')
            ->select(
                'v.id',
                'v.visited_at',
                'v.checkin_at',
                'v.checkout_at',
                'v.checkin_latitude',
                'v.checkin_longitude',
                'v.result',
                'v.shop_type',
                'v.shop_phone',
                'v.supplier_name',
                'v.product_types',
                'v.customer_issues',
                'v.customer_feedback',
                'v.follow_up_action',
                'v.follow_up_date',
                'v.note',
                's.id as staff_id',
                's.name as staff_name',
                'c.erp_customer_code',
                DB::raw('COALESCE(c.customer_name, l.shop_name) as shop_name'),
                DB::raw('CASE WHEN v.erp_customer_id IS NOT NULL THEN \'erp_customer\' ELSE \'crm_lead\' END as target_type'),
                DB::raw('(SELECT COUNT(*) FROM crm_visit_photos vp WHERE vp.visit_id = v.id) as photo_count')
            );

        if (! $this->manager()) {
            $q->where('v.staff_user_id', $this->user()->id);
        } elseif (! empty($filters['staff_id'])) {
            $q->where('v.staff_user_id', (int) $filters['staff_id']);
        }

        if (! empty($filters['from'])) {
            $q->whereDate('v.visited_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('v.visited_at', '<=', $filters['to']);
        }

        $rows = $q->orderByDesc('v.visited_at')->limit(1000)->get();
        $format = $filters['format'] ?? 'json';

        if ($format === 'csv') {
            $csvSafe = static function ($value): string {
                $str = (string) ($value ?? '');
                if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $str = "'" . $str;
                }

                return $str;
            };

            $fh = fopen('php://temp', 'w+b');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, [
                'Visit ID', 'Visited At', 'Staff', 'Target Type', 'ERP Code', 'Shop Name',
                'Shop Type', 'Phone', 'Result', 'Q1 Supplier', 'Q2 Product Types',
                'Q3 Issues', 'Q4 Feedback', 'Q5 Follow Up', 'Follow Up Date', 'Q6 Photos',
                'Check-in Lat', 'Check-in Lng', 'Notes',
            ]);
            foreach ($rows as $row) {
                fputcsv($fh, [
                    $row->id,
                    $csvSafe($row->visited_at),
                    $csvSafe($row->staff_name),
                    $csvSafe($row->target_type),
                    $csvSafe($row->erp_customer_code),
                    $csvSafe($row->shop_name),
                    $csvSafe($row->shop_type),
                    $csvSafe($row->shop_phone),
                    $csvSafe($row->result),
                    $csvSafe($row->supplier_name),
                    $csvSafe($row->product_types),
                    $csvSafe($row->customer_issues),
                    $csvSafe($row->customer_feedback),
                    $csvSafe($row->follow_up_action),
                    $csvSafe($row->follow_up_date),
                    (int) $row->photo_count,
                    $csvSafe($row->checkin_latitude),
                    $csvSafe($row->checkin_longitude),
                    $csvSafe($row->note),
                ]);
            }
            rewind($fh);
            $csv = (string) stream_get_contents($fh);
            fclose($fh);

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="field-visit-report.csv"',
            ]);
        }

        if ($format === 'print') {
            $esc = static fn ($v): string => htmlspecialchars((string) ($v ?? '-'), ENT_QUOTES, 'UTF-8');
            $trs = '';
            foreach ($rows as $row) {
                $trs .= '<tr>'
                    . '<td>' . $esc($row->visited_at) . '</td>'
                    . '<td>' . $esc($row->staff_name) . '</td>'
                    . '<td>' . $esc($row->shop_name) . '</td>'
                    . '<td>' . $esc($row->result) . '</td>'
                    . '<td>' . $esc($row->supplier_name) . '</td>'
                    . '<td>' . $esc($row->product_types) . '</td>'
                    . '<td>' . $esc($row->customer_issues) . '</td>'
                    . '<td>' . $esc($row->customer_feedback) . '</td>'
                    . '<td>' . $esc($row->follow_up_action) . '</td>'
                    . '<td>' . (int) $row->photo_count . '</td>'
                    . '</tr>';
            }
            $html = '<!doctype html><html lang="th"><head><meta charset="utf-8"><title>Field Visit Report</title>'
                . '<style>body{font-family:sans-serif;font-size:12px;margin:20px;color:#0f172a}table{width:100%;border-collapse:collapse}th,td{border:1px solid #cbd5e1;padding:6px;text-align:left;vertical-align:top}th{background:#f1f5f9}</style></head><body>'
                . '<h2>Popstar Field Visit Report (6 Questions)</h2>'
                . '<p>Generated: ' . $esc(now()->toDateTimeString()) . ' | Total Visits: ' . count($rows) . '</p>'
                . '<table><thead><tr><th>Visited At</th><th>Sales Rep</th><th>Shop</th><th>Result</th><th>Supplier</th><th>Products</th><th>Issues</th><th>Feedback</th><th>Follow Up</th><th>Photos</th></tr></thead><tbody>'
                . $trs
                . '</tbody></table></body></html>';

            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        return response()->json([
            'filters' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'staff_id' => $filters['staff_id'] ?? null,
            ],
            'summary' => [
                'total_visits' => $rows->count(),
                'by_result' => $rows->groupBy('result')->map->count(),
            ],
            'rows' => $rows,
        ]);
    }
}
