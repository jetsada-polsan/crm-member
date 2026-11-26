<?php

namespace App\Modules\LINE;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class LineWebhookController
{
    private function linkInstructions(): string
    {
        $url = trim((string) config('popstar.member_portal_url', 'https://crm.popstarmember.com/m/'));
        if ($url === '') {
            $url = 'https://crm.popstarmember.com/m/';
        }

        return 'ผูกบัญชี LINE ได้ที่ '.$url.' เข้าสู่ระบบแล้วกด "สร้างรหัสเชื่อม LINE" จากนั้นพิมพ์ LINK-รหัส ในแชทนี้';
    }

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

            $replyMessage = null;
            $isPointOrBindAction = false;

            if ($eventType === 'message' && ($event['message']['type'] ?? '') === 'text' && $lineUserId) {
                $text = trim((string) ($event['message']['text'] ?? ''));
                if (preg_match('/^LINK[:\-\s]+([A-Za-z0-9\-]+)$/i', $text, $m)) {
                    $isPointOrBindAction = true;
                    $rawCode = strtoupper(trim($m[1]));
                    $bound = $this->consumeLinkCode($rawCode, $lineUserId);
                    if ($bound) {
                        $boundCount++;
                        $replyMessage = ['type' => 'text', 'text' => 'ผูกบัญชี LINE กับ Popstar Member สำเร็จแล้ว'];
                    } else {
                        $replyMessage = ['type' => 'text', 'text' => 'รหัสเชื่อมบัญชีไม่ถูกต้องหรือหมดอายุ กรุณาสร้างรหัสใหม่จากหน้าสมาชิก'];
                    }
                } elseif ($this->isCheckPointsCommand($text)) {
                    $isPointOrBindAction = true;
                    $member = $this->findMemberByLineUserId($lineUserId);
                    if ($member !== null) {
                        $replyMessage = $this->buildPointsFlexMessage($member);
                    } else {
                        $replyMessage = [
                            'type' => 'text',
                            'text' => $this->linkInstructions(),
                        ];
                    }
                } else {
                    $phoneDigits = $this->extractTenDigitPhone($text);
                    if ($phoneDigits !== null && $this->findMemberByLineUserId($lineUserId) === null) {
                        $isPointOrBindAction = true;
                        // A phone number alone proves nothing, so binding needs a code from the signed-in member portal.
                        $replyMessage = ['type' => 'text', 'text' => $this->linkInstructions()];
                    } else {
                        $replyMessage = [
                            'type' => 'text',
                            'text' => $this->customerReply($text, $lineUserId),
                        ];
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
                $isFirstThreadMessage = $this->recordIncomingChat(
                    $lineUserId,
                    $event,
                    $isPointOrBindAction ? '' : $accessToken
                );
            }

            if ($eventType !== 'message') {
                continue;
            }

            if (($event['message']['type'] ?? '') !== 'text' && $lineUserId) {
                $replyMessage = [
                    'type' => 'text',
                    'text' => 'ได้รับข้อความแล้วครับ ทีมงาน POPSTAR จะตอบกลับในแชทนี้',
                ];
            }

            if ($replyMessage === null && $isFirstThreadMessage) {
                $replyMessage = [
                    'type' => 'text',
                    'text' => LineConfig::welcomeMessage(),
                ];
            }

            if ($replyMessage === null) {
                $replyMessage = [
                    'type' => 'text',
                    'text' => 'พิมพ์ “เช็คแต้ม” เพื่อดูคะแนนและของรางวัล หรือพิมพ์ “ติดต่อเรา” เพื่อฝากข้อความถึงทีมงาน',
                ];
            }

            $replyToken = $event['replyToken'] ?? null;
            if ($replyToken && $replyMessage !== null && $accessToken !== '') {
                $deliveryStatus = 'failed';
                $deliveryError = null;

                try {
                    $response = Http::withToken($accessToken)->acceptJson()->timeout(8)
                        ->post('https://api.line.me/v2/bot/message/reply', [
                            'replyToken' => $replyToken,
                            'messages' => [$replyMessage],
                        ]);
                    $deliveryStatus = $response->successful() ? 'sent' : 'failed';
                    if (! $response->successful()) {
                        $deliveryError = 'LINE reply failed with HTTP '.$response->status();
                    }
                } catch (Throwable $e) {
                    $deliveryError = 'LINE reply request failed: '.mb_substr($e->getMessage(), 0, 160);
                }

                if ($lineUserId && Schema::hasTable('line_chat_threads')) {
                    $thread = DB::table('line_chat_threads')->where('line_user_id', $lineUserId)->first();
                    if ($thread) {
                        $outgoingBody = ($replyMessage['type'] ?? 'text') === 'flex'
                            ? (string) ($replyMessage['altText'] ?? '[การ์ดคะแนนสะสม]')
                            : (string) ($replyMessage['text'] ?? '');

                        DB::table('line_chat_messages')->insert([
                            'thread_id' => $thread->id,
                            'direction' => 'outgoing',
                            'message_type' => (string) ($replyMessage['type'] ?? 'text'),
                            'body' => $outgoingBody,
                            'delivery_status' => $deliveryStatus,
                            'delivery_error' => $deliveryError,
                            'created_at' => now(),
                        ]);
                    }
                }
            }
        }

        return response()->json(['ok' => true, 'bound' => $boundCount]);
    }

    private function isCheckPointsCommand(string $text): bool
    {
        $normalized = mb_strtolower(preg_replace('/[\s\p{P}\p{S}]+/u', '', trim($text)) ?? trim($text));

        return in_array($normalized, ['เช็คแต้ม', 'เช็กแต้ม', 'แต้ม', 'คะแนน', 'เช็คคะแนน', 'เช็กคะแนน', 'คะแนนสะสม'], true);
    }

    private function extractTenDigitPhone(string $text): ?string
    {
        $trimmed = trim($text);
        if (! preg_match('/^0[\d\-\s]{9,12}$/', $trimmed)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        return preg_match('/^0\d{9}$/', $digits) === 1 ? $digits : null;
    }

    private function findMemberByLineUserId(string $lineUserId): ?object
    {
        if ($lineUserId === '') {
            return null;
        }

        if (Schema::hasColumn('member_accounts', 'line_user_id')) {
            $direct = DB::table('member_accounts')
                ->where('line_user_id', $lineUserId)
                ->first();
            if ($direct) {
                return $direct;
            }
        }

        if (Schema::hasTable('member_line_accounts')) {
            $linked = DB::table('member_line_accounts as mla')
                ->join('member_accounts as m', 'm.id', '=', 'mla.member_account_id')
                ->where('mla.line_user_id', $lineUserId)
                ->where('mla.binding_status', 'verified')
                ->whereNull('mla.revoked_at')
                ->select('m.*')
                ->first();
            if ($linked) {
                return $linked;
            }
        }

        return null;
    }

    public static function maskMemberName(string $name): string
    {
        $clean = trim($name);
        if ($clean === '') {
            return 'สมาชิก***';
        }

        $firstPart = preg_split('/\s+/u', $clean)[0] ?? $clean;
        preg_match_all('/.[\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}-\x{0E4E}]*/u', $firstPart, $matches);
        $clusters = $matches[0] ?? [];

        if ($clusters === []) {
            return 'สมาชิก***';
        }

        if (count($clusters) === 1) {
            return $clusters[0].'***';
        }

        return $clusters[0].$clusters[1].'***';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPointsFlexMessage(object $member): array
    {
        $memberId = (int) $member->id;
        $maskedName = self::maskMemberName((string) ($member->name ?? ''));

        $hasLedger = DB::table('loyalty_points_ledger')
            ->where('member_account_id', $memberId)
            ->exists();
        $ledgerBalance = (int) DB::table('loyalty_points_ledger')
            ->where('member_account_id', $memberId)
            ->sum('points_delta');

        $erpMember = null;
        if (Schema::hasTable('member_erp_links') && Schema::hasTable('erp_members')) {
            $erpMember = DB::table('member_erp_links as l')
                ->join('erp_customers as c', 'c.id', '=', 'l.erp_customer_id')
                ->leftJoin('erp_members as em', function ($join): void {
                    $join->on('em.source_system', '=', 'c.source_system')
                        ->on('em.member_code', '=', 'l.erp_member_code');
                })
                ->where('l.member_account_id', $memberId)
                ->where('l.verification_status', 'verified')
                ->whereNotNull('l.verified_at')
                ->select('em.erp_point_balance', 'em.member_expire')
                ->first();
        }

        $balance = $hasLedger
            ? $ledgerBalance
            : ($erpMember && $erpMember->erp_point_balance !== null ? (int) $erpMember->erp_point_balance : 0);

        $expiringPoints = null;
        $expiryDateFormatted = null;

        if (Schema::hasColumn('loyalty_points_ledger', 'expires_at')) {
            $now = now();
            $earliestExpiry = DB::table('loyalty_points_ledger')
                ->where('member_account_id', $memberId)
                ->where('points_delta', '>', 0)
                ->whereNotNull('expires_at')
                ->where('expires_at', '>=', $now)
                ->orderBy('expires_at')
                ->value('expires_at');

            if ($earliestExpiry !== null) {
                $expDate = Carbon::parse($earliestExpiry)->timezone('Asia/Bangkok');
                $expiringSum = (int) DB::table('loyalty_points_ledger')
                    ->where('member_account_id', $memberId)
                    ->where('points_delta', '>', 0)
                    ->whereDate('expires_at', $expDate->toDateString())
                    ->sum('points_delta');
                $expiringPoints = $balance > 0 ? min($balance, $expiringSum) : $expiringSum;
                $expiryDateFormatted = $expDate->format('d/m/Y');
            }
        }

        if ($expiringPoints === null && $erpMember && ! empty($erpMember->member_expire) && $balance > 0) {
            $expiringPoints = $balance;
            $expiryDateFormatted = Carbon::parse($erpMember->member_expire)->timezone('Asia/Bangkok')->format('d/m/Y');
        }

        $recentEntries = DB::table('loyalty_points_ledger')
            ->where('member_account_id', $memberId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['points_delta', 'reason', 'created_at']);

        $bodyContents = [
            [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'สมาชิก',
                        'size' => 'sm',
                        'color' => '#64748B',
                    ],
                    [
                        'type' => 'text',
                        'text' => $maskedName,
                        'size' => 'sm',
                        'weight' => 'bold',
                        'color' => '#0F172A',
                        'align' => 'end',
                    ],
                ],
            ],
            [
                'type' => 'box',
                'layout' => 'vertical',
                'margin' => 'md',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'แต้มคงเหลือ',
                        'size' => 'xs',
                        'color' => '#64748B',
                    ],
                    [
                        'type' => 'text',
                        'text' => number_format($balance).' แต้ม',
                        'size' => 'xxl',
                        'weight' => 'bold',
                        'color' => '#0284C7',
                    ],
                ],
            ],
        ];

        if ($expiringPoints !== null && $expiringPoints > 0 && $expiryDateFormatted !== null) {
            $bodyContents[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'margin' => 'sm',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'แต้มที่จะหมดอายุ',
                        'size' => 'xs',
                        'color' => '#D97706',
                        'flex' => 0,
                    ],
                    [
                        'type' => 'text',
                        'text' => number_format($expiringPoints).' แต้ม ('.$expiryDateFormatted.')',
                        'size' => 'xs',
                        'weight' => 'bold',
                        'color' => '#D97706',
                        'align' => 'end',
                    ],
                ],
            ];
        }

        $bodyContents[] = [
            'type' => 'separator',
            'margin' => 'md',
        ];

        $bodyContents[] = [
            'type' => 'text',
            'text' => 'รายการแต้มล่าสุด',
            'size' => 'xs',
            'weight' => 'bold',
            'color' => '#475569',
            'margin' => 'md',
        ];

        if ($recentEntries->isEmpty()) {
            $bodyContents[] = [
                'type' => 'text',
                'text' => 'ยังไม่มีประวัติรายการแต้ม',
                'size' => 'xs',
                'color' => '#94A3B8',
                'margin' => 'sm',
            ];
        } else {
            foreach ($recentEntries as $entry) {
                $dateStr = $entry->created_at
                    ? Carbon::parse($entry->created_at)->timezone('Asia/Bangkok')->format('d/m/Y')
                    : '-';
                $delta = (int) $entry->points_delta;
                $sign = $delta >= 0 ? '+' : '-';
                $formattedDelta = $sign.number_format(abs($delta));

                $bodyContents[] = [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'sm',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => $dateStr,
                            'size' => 'xs',
                            'color' => '#64748B',
                        ],
                        [
                            'type' => 'text',
                            'text' => $formattedDelta,
                            'size' => 'xs',
                            'weight' => 'bold',
                            'align' => 'end',
                            'color' => $delta >= 0 ? '#059669' : '#E11D48',
                        ],
                    ],
                ];
            }
        }

        return [
            'type' => 'flex',
            'altText' => 'แต้มสะสมของ '.$maskedName.': '.number_format($balance).' แต้ม',
            'contents' => [
                'type' => 'bubble',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => 'POPSTAR MEMBER',
                            'weight' => 'bold',
                            'size' => 'xs',
                            'color' => '#0284C7',
                        ],
                        [
                            'type' => 'text',
                            'text' => 'บัตรคะแนนสมาชิก',
                            'weight' => 'bold',
                            'size' => 'lg',
                            'color' => '#0F172A',
                        ],
                    ],
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => $bodyContents,
                ],
            ],
        ];
    }

    private function customerReply(string $text, string $lineUserId): string
    {
        $normalized = mb_strtolower(preg_replace('/[\s\p{P}\p{S}]+/u', '', trim($text)) ?? trim($text));

        if (in_array($normalized, ['ของรางวัล', 'ของขวัญ', 'รางวัล', 'โปรโมชั่น', 'โปรโมชัน'], true)) {
            return $this->activeRewardsReply();
        }

        if (in_array($normalized, ['ติดต่อ', 'ติดต่อเรา', 'แอดมิน', 'ติดต่อแอดมิน', 'ติดต่อแอดมินpopstar', 'เจ้าหน้าที่', 'คุยกับเจ้าหน้าที่'], true)) {
            return 'รับข้อความแล้วครับ ทีมงาน POPSTAR จะตอบกลับในแชทนี้';
        }

        if (in_array($normalized, ['ผูกสมาชิก', 'ผูกบัญชี', 'สมัครสมาชิก'], true)) {
            if ($this->findMemberByLineUserId($lineUserId) !== null) {
                return 'บัญชี LINE ของคุณผูกกับสมาชิก POPSTAR แล้วครับ พิมพ์ “เช็คแต้ม” เพื่อดูคะแนนสะสมได้เลย';
            }

            return $this->linkInstructions()."\nหากยังไม่เคยสมัครสมาชิก พิมพ์ชื่อร้านค้าและเบอร์โทรศัพท์ในแชทนี้เพื่อให้แอดมินลงทะเบียนให้ครับ";
        }

        if (in_array($normalized, ['วิธีใช้', 'ช่วยเหลือ', 'help', 'start', 'เมนู'], true)) {
            return 'พิมพ์ “เช็คแต้ม” เพื่อดูคะแนนและของรางวัล พิมพ์ “ของรางวัล” เพื่อดูรายการ หรือ “ติดต่อเรา” เพื่อฝากข้อความถึงทีมงาน';
        }

        return 'ขออภัย ยังไม่เข้าใจข้อความนี้ครับ พิมพ์ “เช็คแต้ม”, “ของรางวัล”, “วิธีใช้” หรือ “ติดต่อเรา” ได้เลย';
    }

    private function activeRewardsReply(): string
    {
        $now = now();
        $rewards = DB::table('loyalty_rewards')
            ->where('is_active', true)
            ->where(function ($q) use ($now): void {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now): void {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now);
            })
            ->select('title', 'points_cost', 'stock_qty')
            ->orderBy('points_cost')
            ->limit(6)
            ->get();

        if ($rewards->isEmpty()) {
            return 'ตอนนี้ยังไม่มีรายการของรางวัลที่เปิดให้แลกครับ';
        }

        $lines = ['ของรางวัลที่เปิดให้แลก:'];
        foreach ($rewards as $reward) {
            $line = '• '.$reward->title.' — '.number_format((int) $reward->points_cost).' คะแนน';
            if ($reward->stock_qty !== null) {
                $line .= ' (คงเหลือ '.number_format((int) $reward->stock_qty).')';
            }
            $lines[] = $line;
        }

        if ($rewards->count() === 6) {
            $lines[] = 'ดูรายการทั้งหมดได้ในหน้าสมาชิก';
        }

        return implode("\n", $lines);
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
        $boundMember = $this->findMemberByLineUserId($lineUserId);
        $memberId = $boundMember?->id
            ? (int) $boundMember->id
            : ($lineAccount?->member_account_id ? (int) $lineAccount->member_account_id : null);
        $erpCustomerId = null;
        if ($memberId !== null) {
            $erpLink = DB::table('member_erp_links')->where('member_account_id', $memberId)->first();
            $erpCustomerId = $erpLink?->erp_customer_id ? (int) $erpLink->erp_customer_id : null;
        }

        $thread = DB::table('line_chat_threads')->where('line_user_id', $lineUserId)->first();
        $isFirst = ! $thread;
        $displayName = $thread->display_name ?? ($lineAccount->line_display_name ?? ($boundMember->name ?? null));
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

            if (Schema::hasColumn('member_accounts', 'line_user_id')) {
                DB::table('member_accounts')
                    ->where('id', $token->member_account_id)
                    ->update([
                        'line_user_id' => $lineUserId,
                        'updated_at' => $now,
                    ]);
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
