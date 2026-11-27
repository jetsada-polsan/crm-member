<?php

namespace App\Modules\Member;

use App\Models\MemberAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class MemberAuthController
{
    public function csrf(Request $request): JsonResponse
    {
        return response()->json(['csrf_token' => $request->session()->token()]);
    }

    public function register(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255', 'unique:member_accounts,email'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'consent' => ['required', 'accepted'],
        ]);
        $phone = isset($d['phone']) ? preg_replace('/\\D+/', '', $d['phone']) : null;
        if ($phone !== null && (strlen($phone) < 8 || strlen($phone) > 15)) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid phone number.']);
        }
        $phoneExists = $phone !== null && DB::table('member_accounts')->whereNotNull('phone')->get(['phone'])
            ->contains(fn ($row) => preg_replace('/\D+/', '', (string) $row->phone) === $phone);
        if ($phoneExists) {
            throw ValidationException::withMessages(['phone' => 'This phone number is already registered.']);
        }

        $member = MemberAccount::create([
            'name' => $d['name'],
            'email' => $d['email'] ?? null,
            'phone' => $phone,
            'password' => Hash::make($d['password']),
            'status' => 'active',
        ]);
        Auth::guard('member')->login($member);
        $request->session()->regenerate();

        return response()->json([
            'ok' => true,
            'id' => $member->id,
            'portal' => 'member',
            'redirect_path' => '/overview',
            'identity_status' => 'member_verification_pending',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $d = $request->validate([
            'email' => ['nullable', 'required_without:phone', 'email'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:40'],
            'password' => ['required', 'string'],
        ]);
        $credentials = ['password' => $d['password']];
        if (! empty($d['email'])) {
            $credentials['email'] = $d['email'];
        } else {
            $credentials['phone'] = preg_replace('/\\D+/', '', $d['phone']);
        }
        if (! Auth::guard('member')->attempt($credentials)) {
            return response()->json(['error' => 'Invalid credentials'], 422);
        }
        if (Auth::guard('member')->user()->status !== 'active') {
            Auth::guard('member')->logout();

            return response()->json(['error' => 'Disabled account'], 403);
        }
        $request->session()->regenerate();

        return response()->json([
            'ok' => true,
            'portal' => 'member',
            'redirect_path' => '/overview',
        ]);
    }

    public function me(): JsonResponse
    {
        $member = Auth::guard('member')->user();

        return response()->json([
            'id' => $member->id, 'name' => $member->name, 'email' => $member->email,
            'phone' => $member->phone, 'status' => $member->status,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('member')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }
}
