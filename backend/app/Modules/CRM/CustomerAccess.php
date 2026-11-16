<?php

namespace App\Modules\CRM;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Single rule for which ERP customers, reservations (ใบจอง), and invoices a staff user may see:
 * - Admins/managers see everything in the system.
 * - Sales reps see ONLY data tied to their bound salesperson_code and/or bound reservation_doc_types (ใบจอง).
 */
final class CustomerAccess
{
    public static function isManager(?object $user = null): bool
    {
        $u = $user ?? Auth::guard('staff')->user();

        return $u !== null && in_array($u->role, ['admin', 'manager'], true);
    }

    public static function salespersonCode(?object $user = null): ?string
    {
        $u = $user ?? Auth::guard('staff')->user();
        if (! $u) {
            return null;
        }
        $code = trim((string) ($u->salesperson_code ?? ''));

        return $code !== '' ? $code : null;
    }

    /**
     * @return list<string>
     */
    public static function reservationDocTypes(?object $user = null): array
    {
        $u = $user ?? Auth::guard('staff')->user();
        if (! $u) {
            return [];
        }

        return self::parseDocTypes($u->reservation_doc_types ?? null);
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

    public static function query(string $alias = 'erp_customers', ?object $user = null): Builder
    {
        $query = DB::table('erp_customers as '.$alias);

        return self::applyCustomerScope($query, $alias, $user);
    }

    /**
     * Restrict an erp_customers query to customers belonging to the salesperson's
     * salesperson_code OR appearing in their bound reservation_doc_types (ใบจอง).
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
                $w->orWhereIn("{$invAlias}.doc_type", $docTypes)
                    ->orWhereExists(function ($sub) use ($invAlias, $docTypes): void {
                        $sub->select(DB::raw(1))
                            ->from('erp_sales_invoices as r_inv')
                            ->whereColumn('r_inv.source_system', "{$invAlias}.source_system")
                            ->whereColumn('r_inv.erp_customer_code', "{$invAlias}.erp_customer_code")
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
                    ->whereIn('r_inv.doc_type', $docTypes);
            });
        }
    }
}
