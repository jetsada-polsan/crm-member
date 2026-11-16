<?php

namespace App\Modules\LINE;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class LineConfig
{
    public const DEFAULT_WELCOME_MESSAGE = 'ยินดีต้อนรับสู่ Popstar สมาชิก กรุณาเข้าสู่ระบบและยืนยันการผูกบัญชีก่อนดูยอดซื้อและคะแนน';

    /**
     * @return array<string, ?string>
     */
    public static function allRaw(): array
    {
        $out = [
            'line_channel_id' => null,
            'line_channel_secret' => null,
            'line_channel_access_token' => null,
            'line_bot_basic_id' => null,
            'line_liff_id' => null,
            'line_welcome_message' => null,
        ];

        try {
            if (! Schema::hasTable('crm_platform_settings')) {
                return $out;
            }
            $rows = DB::table('crm_platform_settings')
                ->whereIn('key', array_keys($out))
                ->get();
            foreach ($rows as $row) {
                $val = $row->value;
                if ($val !== null && $val !== '' && $row->is_secret) {
                    try {
                        $val = Crypt::decryptString($val);
                    } catch (Throwable) {
                        // Fallback if stored plain
                    }
                }
                $out[$row->key] = $val !== '' ? $val : null;
            }
        } catch (Throwable) {
            // Ignore DB read issues and fall back to config()
        }

        return $out;
    }

    public static function channelSecret(): string
    {
        $dbVal = self::allRaw()['line_channel_secret'] ?? null;
        if ($dbVal !== null && trim($dbVal) !== '') {
            return trim($dbVal);
        }

        return trim((string) config('popstar.line_channel_secret', ''));
    }

    public static function channelAccessToken(): string
    {
        $dbVal = self::allRaw()['line_channel_access_token'] ?? null;
        if ($dbVal !== null && trim($dbVal) !== '') {
            return trim($dbVal);
        }

        return trim((string) config('popstar.line_channel_access_token', ''));
    }

    public static function welcomeMessage(): string
    {
        $dbVal = self::allRaw()['line_welcome_message'] ?? null;
        if ($dbVal !== null && trim($dbVal) !== '') {
            return trim($dbVal);
        }

        return self::DEFAULT_WELCOME_MESSAGE;
    }

    public static function maskSecret(?string $secret): ?string
    {
        if ($secret === null || trim($secret) === '') {
            return null;
        }
        $clean = trim($secret);
        $len = strlen($clean);
        if ($len <= 6) {
            return str_repeat('•', $len);
        }

        return substr($clean, 0, 3).str_repeat('•', min(12, $len - 6)).substr($clean, -3);
    }

    public static function save(array $inputs, ?int $actorStaffId = null): void
    {
        $now = now();
        $secretKeys = ['line_channel_secret', 'line_channel_access_token'];
        $plainKeys = ['line_channel_id', 'line_bot_basic_id', 'line_liff_id', 'line_welcome_message'];

        foreach ($plainKeys as $key) {
            if (! array_key_exists($key, $inputs)) {
                continue;
            }
            $val = trim((string) ($inputs[$key] ?? ''));
            DB::table('crm_platform_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => $val !== '' ? $val : null,
                    'is_secret' => false,
                    'updated_by' => $actorStaffId,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        foreach ($secretKeys as $key) {
            if (! array_key_exists($key, $inputs)) {
                continue;
            }
            $raw = trim((string) ($inputs[$key] ?? ''));
            if ($raw === '') {
                continue; // Empty secret input preserves existing saved secret unless explicitly cleared
            }
            DB::table('crm_platform_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => Crypt::encryptString($raw),
                    'is_secret' => true,
                    'updated_by' => $actorStaffId,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
