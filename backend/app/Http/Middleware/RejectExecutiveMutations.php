<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RejectExecutiveMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('staff')->user();
        if (
            $user &&
            $user->role === 'executive' &&
            ! $request->isMethodSafe() &&
            ! $request->is('api/crm/logout')
        ) {
            return response()->json([
                'error' => 'บัญชีผู้บริหารสามารถดูข้อมูลของทุกคนได้ แต่ไม่สามารถแก้ไขข้อมูลหรือตั้งค่าระบบได้',
            ], 403);
        }

        return $next($request);
    }
}
