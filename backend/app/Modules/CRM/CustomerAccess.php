<?php

namespace App\Modules\CRM;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Single rule for which ERP customers, reservations (ใบจอง), and invoices a staff user may see:
 * - Admins/managers see everything in the system.
 * - Sales reps see ONLY data tied to their salesperson_code and auto-linked reservation_doc_types (ใบจอง)
 *   discovered directly from erp_sales_invoices (DT_PROPERTIES = 207).
 */
final class CustomerAccess
{
    public const DEFAULT_EXECUTIVES = [
        ['slug' => 'sia', 'email' => 'sia@popstar.local', 'name' => 'เสี่ย (ผู้บริหารสูงสุด)', 'aliases' => ['เสี่ย', 'sia']],
        ['slug' => 'jae', 'email' => 'jae@popstar.local', 'name' => 'เจ๊ (ผู้บริหารสูงสุด)', 'aliases' => ['เจ๊', 'jae']],
        ['slug' => 'gm', 'email' => 'gm@popstar.local', 'name' => 'ผู้จัดการทั่วไป', 'aliases' => ['ผู้จัดการทั่วไป', 'gm']],
        ['slug' => 'wh-mgr', 'email' => 'wh-mgr@popstar.local', 'name' => 'ผู้จัดการคลัง', 'aliases' => ['ผู้จัดการคลัง', 'wh-mgr', 'wh']],
        ['slug' => 'branch-mgr', 'email' => 'branch-mgr@popstar.local', 'name' => 'ผู้จัดการสาขา', 'aliases' => ['ผู้จัดการสาขา', 'branch-mgr', 'branch']],
    ];

    public static function isManager(?object $user = null): bool
    {
        $u = $user ?? Auth::guard('staff')->user();

        return $u !== null && in_array($u->role, ['admin', 'manager', 'executive'], true);
    }

    public static function isAdmin(?object $user = null): bool
    {
        $u = $user ?? Auth::guard('staff')->user();

        return $u !== null && $u->role === 'admin';
    }

    public static function isReadOnlyExecutive(?object $user = null): bool
    {
        $u = $user ?? Auth::guard('staff')->user();

        return $u !== null && $u->role === 'executive';
    }

    /**
     * Discover reservation document types only when their salesperson mapping is unambiguous.
     *
     * @return array{doc_to_sp: array<string, string>, sp_to_docs: array<string, list<string>>, doc_to_name: array<string, string>, ambiguous_docs: list<string>}
     */
    public static function discoverReservationSalespersonMap(): array
    {
        $rows = DB::table('erp_sales_invoices')
            ->where('dt_properties', 207)
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('doc_type')
            ->where('doc_type', '!=', '')
            ->whereNotNull('salesperson_code')
            ->where('salesperson_code', '!=', '')
            ->where('salesperson_code', '!=', '0')
            ->groupBy('doc_type', 'salesperson_code')
            ->select(
                'doc_type',
                'salesperson_code',
                DB::raw('MAX(salesperson_name) as salesperson_name'),
                DB::raw('COUNT(*) as doc_count')
            )
            ->orderBy('doc_type')
            ->orderByDesc('doc_count')
            ->get();

        $docToSp = [];
        $docToName = [];
        $spToDocs = [];
        $candidates = [];

        foreach ($rows as $row) {
            $doc = strtoupper(trim((string) $row->doc_type));
            $sp = trim((string) $row->salesperson_code);
            if ($doc === '' || $sp === '') {
                continue;
            }
            $candidates[$doc][$sp] = trim((string) ($row->salesperson_name ?? ''));
        }
        $ambiguousDocs = [];
        foreach ($candidates as $doc => $salespeople) {
            if (count($salespeople) !== 1) {
                $ambiguousDocs[] = $doc;

                continue;
            }
            $sp = (string) array_key_first($salespeople);
            $docToSp[$doc] = $sp;
            $docToName[$doc] = $salespeople[$sp];
            $spToDocs[$sp][] = $doc;
        }

        return [
            'doc_to_sp' => $docToSp,
            'sp_to_docs' => $spToDocs,
            'doc_to_name' => $docToName,
            'ambiguous_docs' => $ambiguousDocs,
        ];
    }

    public static function salespersonCode(?object $user = null): ?string
    {
        $u = $user ?? Auth::guard('staff')->user();
        if (! $u) {
            return null;
        }
        $code = trim((string) ($u->salesperson_code ?? ''));
        if ($code !== '' && $code !== '0') {
            return $code;
        }

        // Auto-resolve salesperson_code from bound reservation_doc_types if salesperson_code was not explicitly set
        $explicitDocs = self::parseDocTypes($u->reservation_doc_types ?? null);
        if ($explicitDocs !== []) {
            $map = self::discoverReservationSalespersonMap();
            foreach ($explicitDocs as $doc) {
                if (isset($map['doc_to_sp'][$doc])) {
                    return $map['doc_to_sp'][$doc];
                }
            }
        }

        return null;
    }

    /**
     * Automatically combines explicit reservation_doc_types with reservation doc_types
     * discovered from erp_sales_invoices for the user's salesperson_code.
     *
     * @return list<string>
     */
    public static function reservationDocTypes(?object $user = null): array
    {
        $u = $user ?? Auth::guard('staff')->user();
        if (! $u) {
            return [];
        }

        $docs = [];
        $map = self::discoverReservationSalespersonMap();
        foreach (self::parseDocTypes($u->reservation_doc_types ?? null) as $d) {
            if (! in_array($d, $map['ambiguous_docs'], true)) {
                $docs[$d] = $d;
            }
        }

        $spCode = trim((string) ($u->salesperson_code ?? ''));
        if ($spCode !== '' && $spCode !== '0') {
            foreach ($map['sp_to_docs'][$spCode] ?? [] as $autoDoc) {
                $docs[$autoDoc] = $autoDoc;
            }
        }

        return array_values($docs);
    }

    /**
     * Automatically synchronize and bind staff_users with erp_salespersons and 207 reservation doc_types.
     *
     * @return array{created: int, updated: int, total_sales_staff: int}
     */
    public static function syncStaffFromErp(string $defaultPassword = 'Popstar2026!'): array
    {
        $map = self::discoverReservationSalespersonMap();
        $spToDocs = $map['sp_to_docs'];
        $docToSp = $map['doc_to_sp'];

        $erpSalespersons = DB::table('erp_salespersons')
            ->where('is_active', true)
            ->where('salesperson_code', '!=', '0')
            ->where('salesperson_code', '!=', '')
            ->orderBy('salesperson_code')
            ->get();

        $staffRows = DB::table('staff_users')->orderBy('id')->get();
        $staffBySp = [];
        $staffByName = [];
        foreach ($staffRows as $s) {
            $sp = trim((string) ($s->salesperson_code ?? ''));
            if ($sp !== '') {
                $staffBySp[$sp] = $s;
            }
            $nm = mb_strtolower(trim((string) $s->name), 'UTF-8');
            if ($nm !== '') {
                $staffByName[$nm] = $s;
            }
        }

        $created = 0;
        $updated = 0;
        $now = now();

        // 0. Ensure the 5 executive read-only accounts exist (เสี่ย, เจ๊, ผู้จัดการทั่วไป, ผู้จัดการคลัง, ผู้จัดการสาขา)
        foreach (self::DEFAULT_EXECUTIVES as $exec) {
            $exists = DB::table('staff_users')->where('email', $exec['email'])->first();
            if (! $exists) {
                DB::table('staff_users')->insert([
                    'name' => $exec['name'],
                    'email' => $exec['email'],
                    'password' => Hash::make($defaultPassword),
                    'role' => 'executive',
                    'salesperson_code' => null,
                    'reservation_doc_types' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created++;
            }
        }

        // 1. Auto-update any existing sales staff missing reservation_doc_types or salesperson_code
        foreach ($staffRows as $s) {
            if ($s->role !== 'sales') {
                continue;
            }
            $sp = trim((string) ($s->salesperson_code ?? ''));
            $docs = self::parseDocTypes($s->reservation_doc_types ?? null);

            if ($sp === '' && $docs !== []) {
                foreach ($docs as $d) {
                    if (isset($docToSp[$d])) {
                        $sp = $docToSp[$d];
                        break;
                    }
                }
            }

            if ($sp !== '' && isset($spToDocs[$sp])) {
                foreach ($spToDocs[$sp] as $autoDoc) {
                    if (! in_array($autoDoc, $docs, true)) {
                        $docs[] = $autoDoc;
                    }
                }
            }

            $normalizedDocs = self::normalizeReservationDocTypes($docs);
            $newSp = $sp !== '' ? $sp : null;

            if ($newSp !== $s->salesperson_code || $normalizedDocs !== $s->reservation_doc_types) {
                DB::table('staff_users')->where('id', $s->id)->update([
                    'salesperson_code' => $newSp,
                    'reservation_doc_types' => $normalizedDocs,
                    'updated_at' => $now,
                ]);
                if ($newSp !== null) {
                    $staffBySp[$newSp] = $s;
                }
                $updated++;
            }
        }

        // 2. Auto-provision staff_users accounts for every active ERP salesperson not yet in staff_users
        foreach ($erpSalespersons as $spRow) {
            $code = trim((string) $spRow->salesperson_code);
            $name = trim((string) $spRow->salesperson_name);
            $autoDocs = $spToDocs[$code] ?? [];
            $normalizedDocs = self::normalizeReservationDocTypes($autoDocs);

            if (isset($staffBySp[$code])) {
                continue;
            }

            // Check if an existing sales staff has the exact same Thai name
            $nameKey = mb_strtolower($name, 'UTF-8');
            if (isset($staffByName[$nameKey]) && $staffByName[$nameKey]->role === 'sales') {
                $existing = $staffByName[$nameKey];
                DB::table('staff_users')->where('id', $existing->id)->update([
                    'salesperson_code' => $code,
                    'reservation_doc_types' => $normalizedDocs ?? $existing->reservation_doc_types,
                    'updated_at' => $now,
                ]);
                $staffBySp[$code] = $existing;
                $updated++;

                continue;
            }

            $primaryDoc = $autoDocs[0] ?? null;
            $emailSlug = strtolower($primaryDoc ?: ('sp'.$code));
            $candidateEmail = "{$emailSlug}@popstar.local";
            if (DB::table('staff_users')->where('email', $candidateEmail)->exists()) {
                $candidateEmail = 'sp'.strtolower($code).'@popstar.local';
            }
            if (DB::table('staff_users')->where('email', $candidateEmail)->exists()) {
                continue;
            }

            $displayName = $primaryDoc ? "{$name} ({$primaryDoc})" : $name;
            DB::table('staff_users')->insert([
                'name' => $displayName,
                'email' => $candidateEmail,
                'password' => Hash::make($defaultPassword),
                'role' => 'sales',
                'salesperson_code' => $code,
                'reservation_doc_types' => $normalizedDocs,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $created++;
        }

        $totalSalesStaff = DB::table('staff_users')->where('role', 'sales')->count();
        $totalExecutiveStaff = DB::table('staff_users')->where('role', 'executive')->count();

        return [
            'created' => $created,
            'updated' => $updated,
            'total_sales_staff' => $totalSalesStaff,
            'total_executive_staff' => $totalExecutiveStaff,
        ];
    }

    /**
     * @return list<string>
     */
    public static function parseDocTypes(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } elseif (is_string($raw)) {
            $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        } else {
            return [];
        }

        $clean = [];
        foreach ($parts as $part) {
            $code = strtoupper(trim((string) $part));
            if ($code !== '' && preg_match('/^[A-Z0-9_-]{1,32}$/', $code)) {
                $clean[$code] = $code;
            }
        }

        return array_values($clean);
    }

    public static function normalizeReservationDocTypes(mixed $raw): ?string
    {
        $list = self::parseDocTypes($raw);

        return $list === [] ? null : implode(',', $list);
    }

    public const CLOSED_CUSTOMER_KEYWORDS = [
        'ยกเลิก',
        'ปิดกิจการ',
        'เลิกกิจการ',
        'ห้ามเปิดบิล',
        'ห้ามขาย',
        'ไม่ใช้งาน',
        'ไม่ใช้แล้ว',
        'เปลี่ยนใช้รหัส',
        'เปลี่ยนรหัสใหม่',
    ];

    /**
     * Exclude ERP customers that are closed/disabled in Bplus (AR_ENABLE <> 'Y' or cancelled/closed name markers).
     */
    public static function applyActiveCustomerFilter(Builder $query, string $alias = 'erp_customers', bool $allowNull = false): Builder
    {
        $likeOp = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $colEnable = "{$alias}.ar_enable";
        $colName = "{$alias}.customer_name";
        $colId = "{$alias}.id";

        $applyChecks = static function (Builder $w) use ($colEnable, $colName, $likeOp): void {
            $w->where(function (Builder $en) use ($colEnable): void {
                $en->whereNull($colEnable)
                    ->orWhereRaw("UPPER(TRIM({$colEnable})) = 'Y'")
                    ->orWhereRaw("TRIM({$colEnable}) = ''");
            });

            foreach (self::CLOSED_CUSTOMER_KEYWORDS as $kw) {
                $w->where(function (Builder $nw) use ($colName, $likeOp, $kw): void {
                    $nw->whereNull($colName)
                        ->orWhere($colName, 'not '.$likeOp, '%'.$kw.'%');
                });
            }
        };

        if ($allowNull) {
            return $query->where(function (Builder $outer) use ($colId, $applyChecks): void {
                $outer->whereNull($colId)
                    ->orWhere(function (Builder $inner) use ($applyChecks): void {
                        $applyChecks($inner);
                    });
            });
        }

        return $query->where($applyChecks);
    }

    public static function isClosedCustomerRecord(?string $arEnable, ?string $customerName): bool
    {
        if ($arEnable !== null && trim($arEnable) !== '' && strtoupper(trim($arEnable)) !== 'Y') {
            return true;
        }
        $name = (string) ($customerName ?? '');
        if ($name !== '') {
            foreach (self::CLOSED_CUSTOMER_KEYWORDS as $kw) {
                if (mb_stripos($name, $kw, 0, 'UTF-8') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function query(string $alias = 'erp_customers', ?object $user = null): Builder
    {
        $query = DB::table('erp_customers as '.$alias);
        self::applyActiveCustomerFilter($query, $alias, false);

        return self::applyCustomerScope($query, $alias, $user);
    }

    /**
     * Restrict an erp_customers query to customers belonging to the salesperson's
     * salesperson_code OR appearing in their bound/auto-linked reservation_doc_types (ใบจอง).
     */
    public static function applyCustomerScope(Builder $query, string $alias = 'erp_customers', ?object $user = null): Builder
    {
        $u = $user ?? Auth::guard('staff')->user();
        if (self::isManager($u)) {
            return $query;
        }

        $spCode = self::salespersonCode($u);
        $docTypes = self::reservationDocTypes($u);

        if ($spCode === null && $docTypes === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($w) use ($alias, $spCode, $docTypes): void {
            self::addErpCustomerOwnershipClauses($w, $alias, $spCode, $docTypes);
        });
    }

    /**
     * Scope a joined query that has both an erp_customers alias and a crm_leads alias.
     */
    public static function applyCustomerAndLeadScope(
        Builder $query,
        string $erpAlias,
        string $leadAlias,
        ?object $user = null
    ): Builder {
        $u = $user ?? Auth::guard('staff')->user();
        if (self::isManager($u)) {
            return $query;
        }

        if (! $u) {
            return $query->whereRaw('1 = 0');
        }

        $spCode = self::salespersonCode($u);
        $docTypes = self::reservationDocTypes($u);
        $userId = (int) $u->id;

        return $query->where(function ($w) use ($erpAlias, $leadAlias, $spCode, $docTypes, $userId): void {
            $w->where("{$leadAlias}.owner_staff_id", $userId);
            if ($spCode !== null) {
                $w->orWhere("{$leadAlias}.assigned_salesperson_code", $spCode);
            }
            if ($spCode !== null || $docTypes !== []) {
                $w->orWhere(function ($erpW) use ($erpAlias, $spCode, $docTypes): void {
                    $erpW->whereNotNull("{$erpAlias}.id")
                        ->where(function ($inner) use ($erpAlias, $spCode, $docTypes): void {
                            self::addErpCustomerOwnershipClauses($inner, $erpAlias, $spCode, $docTypes);
                        });
                });
            }
        });
    }

    public static function canAccessCustomerAndLead(?object $erp, ?object $lead, ?object $user = null): bool
    {
        $u = $user ?? Auth::guard('staff')->user();
        if (self::isManager($u)) {
            return true;
        }
        if (! $u) {
            return false;
        }

        $spCode = self::salespersonCode($u);
        if ($lead && ((int) $lead->owner_staff_id === (int) $u->id || ($spCode !== null && $lead->assigned_salesperson_code === $spCode))) {
            return true;
        }

        if ($erp && isset($erp->id)) {
            return self::query('c', $u)->where('c.id', (int) $erp->id)->exists();
        }

        return false;
    }

    /**
     * Restrict an erp_sales_invoices query so salespersons only see their own sales and reservations.
     */
    public static function applyInvoiceScope(
        Builder $query,
        string $invAlias = 'erp_sales_invoices',
        ?object $user = null
    ): Builder {
        $u = $user ?? Auth::guard('staff')->user();
        if (self::isManager($u)) {
            return $query;
        }

        $spCode = self::salespersonCode($u);
        $docTypes = self::reservationDocTypes($u);

        if ($spCode === null && $docTypes === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($w) use ($invAlias, $spCode, $docTypes): void {
            if ($spCode !== null) {
                $w->orWhere("{$invAlias}.salesperson_code", $spCode)
                    ->orWhereExists(function ($sub) use ($invAlias, $spCode): void {
                        $sub->select(DB::raw(1))
                            ->from('erp_customers as sc_sp')
                            ->whereColumn('sc_sp.source_system', "{$invAlias}.source_system")
                            ->whereColumn('sc_sp.erp_customer_code', "{$invAlias}.erp_customer_code")
                            ->where('sc_sp.salesperson_code', $spCode);
                    });
            }

            if ($docTypes !== []) {
                $w->orWhereExists(function ($sub) use ($invAlias, $docTypes): void {
                    $sub->select(DB::raw(1))
                        ->from('erp_sales_invoices as r_inv')
                        ->whereColumn('r_inv.source_system', "{$invAlias}.source_system")
                        ->whereColumn('r_inv.erp_customer_code', "{$invAlias}.erp_customer_code")
                        ->where('r_inv.dt_properties', 207)
                        ->where('r_inv.status', '!=', 'cancelled')
                        ->whereIn('r_inv.doc_type', $docTypes);
                });
            }
        });
    }

    /**
     * @param  list<string>  $docTypes
     */
    private static function addErpCustomerOwnershipClauses(
        Builder $w,
        string $alias,
        ?string $spCode,
        array $docTypes
    ): void {
        if ($spCode !== null) {
            $w->orWhere("{$alias}.salesperson_code", $spCode)
                ->orWhereExists(function ($sub) use ($alias, $spCode): void {
                    $sub->select(DB::raw(1))
                        ->from('erp_sales_invoices as s_inv')
                        ->whereColumn('s_inv.source_system', "{$alias}.source_system")
                        ->whereColumn('s_inv.erp_customer_code', "{$alias}.erp_customer_code")
                        ->where('s_inv.salesperson_code', $spCode);
                });
        }

        if ($docTypes !== []) {
            $w->orWhereExists(function ($sub) use ($alias, $docTypes): void {
                $sub->select(DB::raw(1))
                    ->from('erp_sales_invoices as r_inv')
                    ->whereColumn('r_inv.source_system', "{$alias}.source_system")
                    ->whereColumn('r_inv.erp_customer_code', "{$alias}.erp_customer_code")
                    ->where('r_inv.dt_properties', 207)
                    ->where('r_inv.status', '!=', 'cancelled')
                    ->whereIn('r_inv.doc_type', $docTypes);
            });
        }
    }
}
