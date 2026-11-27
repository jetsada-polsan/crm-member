<?php

namespace App\Modules\CRM;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
            'must_change_password' => (bool) $user->must_change_password,
        ]);
    }

    /**
     * Staff replace their own password; required after an admin reset or a leaked default password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = Auth::guard('staff')->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:staff'],
            'password' => ['required', 'string', 'min:10', 'max:120', 'confirmed', 'different:current_password'],
        ], [
            'current_password.current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
            'password.different' => 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม',
            'password.min' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 10 ตัวอักษร',
            'password.confirmed' => 'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน',
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ])->save();

        DB::table('crm_audit_logs')->insert([
            'entity_type' => 'staff_user',
            'entity_id' => $user->id,
            'action' => 'password_changed',
            'actor_staff_id' => $user->id,
            'changes' => json_encode(['self_service' => true]),
            'created_at' => now(),
        ]);

        $request->session()->regenerate();

        return response()->json(['ok' => true]);
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
            'must_change_password' => (bool) $u->must_change_password,
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
