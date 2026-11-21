<?php

namespace App\Modules\LINE;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class LineWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = LineConfig::channelSecret();
        $signature = (string) $request->header('x-line-signature', '');
        $body = $request->getContent();

        if ($secret === '' || $signature === '' || ! hash_equals(
            base64_encode(hash_hmac('sha256', $body, $secret, true)),
            $signature
        )) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'Invalid JSON'], 400);
        }

        $accessToken = LineConfig::channelAccessToken();
        $boundCount = 0;

        foreach (($payload['events'] ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $eventId = isset($event['webhookEventId']) ? trim((string) $event['webhookEventId']) : null;
            $lineUserId = isset($event['source']['userId']) ? trim((string) $event['source']['userId']) : null;
            $eventType = (string) ($event['type'] ?? 'unknown');

            if ($eventId !== null && $eventId !== '' && DB::table('line_webhook_events')->where('webhook_event_id', $eventId)->exists()) {
                continue; // Idempotent webhook replay protection
            }

            $replyText = null;
            $isLinkCommand = false;

            if ($eventType === 'message' && ($event['message']['type'] ?? '') === 'text' && $lineUserId) {
                $text = trim((string) ($event['message']['text'] ?? ''));
                if (preg_match('/^LINK[:\-\s]+([A-Za-z0-9\-]+)$/i', $text, $m)) {
                    $isLinkCommand = true;
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

            $isFirstThreadMessage = false;
            if ($lineUserId && $eventType === 'message') {
                $isFirstThreadMessage = $this->recordIncomingChat($lineUserId, $event, $accessToken);
            }

            if ($eventType !== 'message' || ($event['message']['type'] ?? '') !== 'text') {
                continue;
            }

            if ($replyText === null && $isFirstThreadMessage) {
                $replyText = LineConfig::welcomeMessage();
            }

            $replyToken = $event['replyToken'] ?? null;
            if ($replyToken && $replyText !== null && $accessToken !== '') {
                Http::withToken($accessToken)->acceptJson()->post('https://api.line.me/v2/bot/message/reply', [
                    'replyToken' => $replyToken,
                    'messages' => [['type' => 'text', 'text' => $replyText]],
                ]);
                if ($lineUserId && Schema::hasTable('line_chat_threads')) {
                    $thread = DB::table('line_chat_threads')->where('line_user_id', $lineUserId)->first();
                    if ($thread) {
                        DB::table('line_chat_messages')->insert([
                            'thread_id' => $thread->id,
                            'direction' => 'outgoing',
                            'message_type' => 'text',
                            'body' => $replyText,
                            'delivery_status' => 'sent',
                            'created_at' => now(),
                        ]);
                    }
                }
            }
        }

        return response()->json(['ok' => true, 'bound' => $boundCount]);
    }

    private function recordIncomingChat(string $lineUserId, array $event, string $accessToken): bool
    {
        if (! Schema::hasTable('line_chat_threads') || ! Schema::hasTable('line_chat_messages')) {
            return false;
        }

        $now = now();
        $msgType = (string) ($event['message']['type'] ?? 'text');
        $msgId = isset($event['message']['id']) ? (string) $event['message']['id'] : null;
        $body = match ($msgType) {
            'text' => (string) ($event['message']['text'] ?? ''),
            'image' => '[รูปภาพจากลูกค้า]',
            'sticker' => '[สติกเกอร์]',
            'location' => '[ตำแหน่งพิกัด] '.($event['message']['address'] ?? ''),
            'file' => '[ไฟล์แนบ] '.($event['message']['fileName'] ?? ''),
            default => "[ข้อความประเภท {$msgType}]",
        };

        $lineAccount = DB::table('member_line_accounts')
            ->where('line_user_id', $lineUserId)
            ->whereNull('revoked_at')
            ->first();
        $memberId = $lineAccount?->member_account_id ? (int) $lineAccount->member_account_id : null;
        $erpCustomerId = null;
        if ($memberId !== null) {
            $erpLink = DB::table('member_erp_links')->where('member_account_id', $memberId)->first();
            $erpCustomerId = $erpLink?->erp_customer_id ? (int) $erpLink->erp_customer_id : null;
        }

        $thread = DB::table('line_chat_threads')->where('line_user_id', $lineUserId)->first();
        $isFirst = ! $thread;
        $displayName = $thread->display_name ?? ($lineAccount->line_display_name ?? null);
        $pictureUrl = $thread->picture_url ?? null;

        if (($displayName === null || $displayName === '') && $accessToken !== '') {
            try {
                $prof = Http::withToken($accessToken)->acceptJson()->timeout(3)
                    ->get('https://api.line.me/v2/bot/profile/'.urlencode($lineUserId));
                if ($prof->successful()) {
                    $pJson = $prof->json() ?? [];
                    $displayName = $pJson['displayName'] ?? $displayName;
                    $pictureUrl = $pJson['pictureUrl'] ?? $pictureUrl;
                }
            } catch (Throwable) {
                // Keep fallback display name if LINE profile endpoint is unreachable
            }
        }

        if ($isFirst) {
            $threadId = DB::table('line_chat_threads')->insertGetId([
                'line_user_id' => $lineUserId,
                'display_name' => $displayName ?: ('ลูกค้า LINE '.substr($lineUserId, -6)),
                'picture_url' => $pictureUrl,
                'erp_customer_id' => $erpCustomerId,
                'member_account_id' => $memberId,
                'status' => 'open',
                'unread_count' => 1,
                'last_message_preview' => mb_substr($body, 0, 280),
                'last_message_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $threadId = (int) $thread->id;
            DB::table('line_chat_threads')->where('id', $threadId)->update([
                'display_name' => $displayName ?: $thread->display_name,
                'picture_url' => $pictureUrl ?: $thread->picture_url,
                'erp_customer_id' => $thread->erp_customer_id ?: $erpCustomerId,
                'member_account_id' => $thread->member_account_id ?: $memberId,
                'status' => 'open',
                'unread_count' => (int) $thread->unread_count + 1,
                'last_message_preview' => mb_substr($body, 0, 280),
                'last_message_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('line_chat_messages')->insert([
            'thread_id' => $threadId,
            'line_message_id' => $msgId,
            'direction' => 'incoming',
            'message_type' => $msgType,
            'body' => $body,
            'delivery_status' => 'received',
            'raw_payload' => json_encode($event, JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
        ]);

        return $isFirst;
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

            if (! $token) {
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
