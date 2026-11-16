<?php

namespace App\Modules\LINE;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class LineWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('popstar.line_channel_secret', '');
        $signature = (string) $request->header('x-line-signature', '');
        $body = $request->getContent();

        if ($secret === '' || $signature === '' || !hash_equals(
            base64_encode(hash_hmac('sha256', $body, $secret, true)),
            $signature
        )) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return response()->json(['error' => 'Invalid JSON'], 400);
        }

        $accessToken = (string) config('popstar.line_channel_access_token', '');
        $boundCount = 0;

        foreach (($payload['events'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventId = isset($event['webhookEventId']) ? trim((string) $event['webhookEventId']) : null;
            $lineUserId = isset($event['source']['userId']) ? trim((string) $event['source']['userId']) : null;
            $eventType = (string) ($event['type'] ?? 'unknown');

            if ($eventId !== null && $eventId !== '' && DB::table('line_webhook_events')->where('webhook_event_id', $eventId)->exists()) {
                continue; // Idempotent webhook replay protection
            }

            $replyText = 'ยินดีต้อนรับสู่ Popstar สมาชิก กรุณาเข้าสู่ระบบและยืนยันการผูกบัญชีก่อนดูยอดซื้อและคะแนน';

            if ($eventType === 'message' && ($event['message']['type'] ?? '') === 'text' && $lineUserId) {
                $text = trim((string) ($event['message']['text'] ?? ''));
                if (preg_match('/^LINK[:\-\s]+([A-Za-z0-9\-]+)$/i', $text, $m)) {
                    $rawCode = strtoupper(trim($m[1]));
                    $bound = $this->consumeLinkCode($rawCode, $lineUserId);
                    if ($bound) {
                        $boundCount++;
                        $replyText = 'ผูกบัญชี LINE กับ Popstar Member สำเร็จแล้ว';
                    } else {
                        $replyText = 'รหัสเชื่อมบัญชีไม่ถูกต้องหรือหมดอายุ กรุณาสร้างรหัสใหม่จากหน้าสมาชิก';
                    }
                }
            }

            DB::table('line_webhook_events')->insert([
                'webhook_event_id' => $eventId ?: null,
                'event_type' => $eventType,
                'line_user_id' => $lineUserId ?: null,
                'signature_valid' => true,
                'payload' => json_encode($event, JSON_UNESCAPED_UNICODE),
                'processed_at' => now(),
                'created_at' => now(),
            ]);

            if ($eventType !== 'message' || ($event['message']['type'] ?? '') !== 'text') {
                continue;
            }

            $replyToken = $event['replyToken'] ?? null;
            if ($replyToken && $accessToken !== '') {
                Http::withToken($accessToken)->acceptJson()->post('https://api.line.me/v2/bot/message/reply', [
                    'replyToken' => $replyToken,
                    'messages' => [['type' => 'text', 'text' => $replyText]],
                ]);
            }
        }

        return response()->json(['ok' => true, 'bound' => $boundCount]);
    }

    private function consumeLinkCode(string $rawCode, string $lineUserId): bool
    {
        $hash = hash('sha256', $rawCode);
        $now = now();

        return DB::transaction(function () use ($hash, $lineUserId, $now): bool {
            $token = DB::table('line_link_tokens')
                ->where('token_hash', $hash)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', $now)
                ->lockForUpdate()
                ->first();

            if (!$token) {
                return false;
            }

            $existsLine = DB::table('member_line_accounts')->where('line_user_id', $lineUserId)->exists();
            DB::table('member_line_accounts')->updateOrInsert(
                ['line_user_id' => $lineUserId],
                array_merge($existsLine ? [] : ['created_at' => $now], [
                    'member_account_id' => $token->member_account_id,
                    'binding_status' => 'verified',
                    'verified_at' => $now,
                    'revoked_at' => null,
                    'last_webhook_at' => $now,
                    'updated_at' => $now,
                ])
            );

            DB::table('line_link_tokens')->where('id', $token->id)->update([
                'consumed_at' => $now,
                'line_user_id' => $lineUserId,
            ]);

            return true;
        });
    }
}
