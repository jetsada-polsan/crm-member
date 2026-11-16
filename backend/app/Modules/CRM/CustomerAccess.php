<?php

namespace App\Modules\CRM;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Single rule for which ERP customers a staff user may see:
 * admins/managers see everyone, sales reps only customers assigned to their salesperson code.
 */
final class CustomerAccess
{
    public static function query(string $alias = 'erp_customers'): Builder
    {
        $user = Auth::guard('staff')->user();
        $query = DB::table('erp_customers as '.$alias);

        if (! self::isManager($user)) {
            // If salesperson_code is NULL, this intentionally returns no data.
            $query->where($alias.'.salesperson_code', $user->salesperson_code ?? '__unassigned__');
        }

        return $query;
    }

    public static function isManager(?object $user): bool
    {
        return $user !== null && in_array($user->role, ['admin', 'manager'], true);
    }
}
