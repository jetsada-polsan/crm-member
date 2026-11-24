<?php
namespace App\Modules\CRM;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class StaffAuthController
{
    public function csrf(Request $request): JsonResponse
    {
        return response()->json(['csrf_token' => $request->session()->token()]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|string|max:180',
            'password' => 'required|string',
        ]);

        $ident = trim($credentials['email']);
        $resolvedEmail = $this->resolveStaffLoginEmail($ident);

        if (! Auth::guard('staff')->attempt(['email' => $resolvedEmail, 'password' => $credentials['password']])) {
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
            'salesperson_code' => CustomerAccess::salespersonCode($user),
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
            'salesperson_code' => CustomerAccess::salespersonCode($u),
            'reservation_doc_types' => CustomerAccess::reservationDocTypes($u),
        ]);
    }

    /**
     * Allow staff to sign in using email, reservation doc_type (e.g. B16), salesperson_code (e.g. 01), or name.
     */
    private function resolveStaffLoginEmail(string $ident): string
    {
        if (str_contains($ident, '@')) {
            return strtolower($ident);
        }

        $lower = mb_strtolower($ident, 'UTF-8');
        foreach (CustomerAccess::DEFAULT_EXECUTIVES as $exec) {
            if ($lower === $exec['slug'] || in_array($lower, array_map(static fn ($a) => mb_strtolower($a, 'UTF-8'), $exec['aliases']), true)) {
                return $exec['email'];
            }
        }

        $upper = strtoupper($ident);
        $byCode = DB::table('staff_users')
            ->where('salesperson_code', $ident)
            ->orWhere('salesperson_code', str_pad($ident, 2, '0', STR_PAD_LEFT))
            ->orWhere('reservation_doc_types', $upper)
            ->orWhere('reservation_doc_types', 'LIKE', $upper.',%')
            ->orWhere('reservation_doc_types', 'LIKE', '%,'.$upper)
            ->orWhere('reservation_doc_types', 'LIKE', '%,'.$upper.',%')
            ->orWhere('name', $ident)
            ->orWhere('name', 'LIKE', $ident.' (%')
            ->first();

        if ($byCode) {
            return (string) $byCode->email;
        }

        return strtolower($ident);
    }
}
