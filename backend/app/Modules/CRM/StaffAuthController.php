<?php
namespace App\Modules\CRM;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class StaffAuthController
{
    public function csrf(Request $request): JsonResponse
    {
        return response()->json(['csrf_token' => $request->session()->token()]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);
        if (! Auth::guard('staff')->attempt($credentials)) {
            return response()->json(['error' => 'Invalid credentials'], 422);
        }
        $user = Auth::guard('staff')->user();
        if (! $user->is_active) {
            Auth::guard('staff')->logout();

            return response()->json(['error' => 'Disabled account'], 403);
        }
        $request->session()->regenerate();

        return response()->json([
            'ok' => true,
            'portal' => 'crm',
            'redirect_path' => '/dashboard',
            'name' => $user->name,
            'role' => $user->role,
            'salesperson_code' => $user->salesperson_code,
            'reservation_doc_types' => CustomerAccess::reservationDocTypes($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('staff')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(): JsonResponse
    {
        $u = Auth::guard('staff')->user();

        return response()->json([
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'salesperson_code' => $u->salesperson_code,
            'reservation_doc_types' => CustomerAccess::reservationDocTypes($u),
        ]);
    }
}
