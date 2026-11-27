<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the CRM API for staff who still have to replace a temporary or leaked password.
 * Only the endpoints needed to change the password (or leave) stay open.
 */
final class EnsureStaffPasswordChanged
{
    /** @var list<string> */
    private const ALLOWED_PATHS = ['api/crm/me', 'api/crm/me/password', 'api/crm/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('staff')->user();

        if ($user && $user->must_change_password && ! $request->is(...self::ALLOWED_PATHS)) {
            return response()->json([
                'error' => 'password_change_required',
                'message' => 'กรุณาตั้งรหัสผ่านใหม่ก่อนใช้งานระบบ',
            ], 423);
        }

        return $next($request);
    }
}
