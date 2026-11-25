<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinePointsWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const LINE_REPLY_URL = 'https://api.line.me/v2/bot/message/reply';

    private const TEST_SECRET = 'line-webhook-test-secret';

    private const TEST_TOKEN = 'line-webhook-test-access-token';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('popstar.line_channel_secret', self::TEST_SECRET);
        config()->set('popstar.line_channel_access_token', self::TEST_TOKEN);
        Http::preventStrayRequests();
    }

    private function createMember(array $overrides = []): int
    {
        return DB::table('member_accounts')->insertGetId(array_merge([
            'name' => 'สมชาย ใจดี',
            'email' => uniqid('member_', true).'@popstar.local',
            'password' => Hash::make('MemberPass123456!'),
            'phone' => '0812345678',
            'line_user_id' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function postLineTextWebhook(
        string $lineUserId,
        string $text,
        ?string $overrideSignature = null,
        ?string $eventId = null
    ) {
        $body = json_encode([
            'events' => [[
                'webhookEventId' => $eventId ?? ('evt-'.uniqid('', true)),
                'type' => 'message',
                'replyToken' => 'reply-tok-'.uniqid(),
                'source' => ['type' => 'user', 'userId' => $lineUserId],
                'message' => ['id' => 'msg-'.uniqid(), 'type' => 'text', 'text' => $text],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $signature = $overrideSignature ?? base64_encode(hash_hmac('sha256', (string) $body, self::TEST_SECRET, true));

        return $this->call('POST', '/api/line/webhook', [], [], [], [
            'HTTP_X_LINE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], (string) $body);
    }

    public function test_invalid_signature_is_rejected_and_sends_no_reply(): void
    {
        Http::fake();

        $res = $this->postLineTextWebhook('U_BAD_SIG', 'เช็คแต้ม', 'invalid-signature');

        $this->assertTrue(in_array($res->status(), [401, 403], true));
        Http::assertNothingSent();
    }

    public function test_check_points_from_bound_user_replies_with_flex_card_and_latest_five_transactions(): void
    {
        Http::fake([
            self::LINE_REPLY_URL => Http::response(['sentMessages' => []], 200),
        ]);

        $memberId = $this->createMember([
            'name' => 'สมชาย สมาชิก',
            'phone' => '0812345678',
            'line_user_id' => 'U_BOUND_001',
        ]);

        // Seed 6 ledger entries so we can verify balance calculation and that only the latest 5 are shown
        $entries = [
            ['event_key' => 'E1', 'points_delta' => 100, 'created_at' => '2026-11-10 10:00:00', 'expires_at' => null],
            ['event_key' => 'E2', 'points_delta' => 200, 'created_at' => '2026-11-12 10:00:00', 'expires_at' => '2026-12-31 23:59:59'],
            ['event_key' => 'E3', 'points_delta' => -50, 'created_at' => '2026-11-15 10:00:00', 'expires_at' => null],
            ['event_key' => 'E4', 'points_delta' => 80,  'created_at' => '2026-11-18 10:00:00', 'expires_at' => null],
            ['event_key' => 'E5', 'points_delta' => -30, 'created_at' => '2026-11-20 10:00:00', 'expires_at' => null],
            ['event_key' => 'E6', 'points_delta' => 150, 'created_at' => '2026-11-24 10:00:00', 'expires_at' => null],
        ];
        foreach ($entries as $idx => $e) {
            DB::table('loyalty_points_ledger')->insert([
                'member_account_id' => $memberId,
                'event_key' => $e['event_key'],
                'points_delta' => $e['points_delta'],
                'reason' => 'Test entry '.($idx + 1),
                'entry_type' => 'manual',
                'expires_at' => $e['expires_at'],
                'created_at' => $e['created_at'],
            ]);
        }
        // Expected balance: 100 + 200 - 50 + 80 - 30 + 150 = 450

        foreach (['  เช็คแต้ม  ', 'เช็กแต้ม', 'แต้ม'] as $keyword) {
            $this->postLineTextWebhook('U_BOUND_001', $keyword)->assertOk();
        }

        Http::assertSentCount(3);
        Http::assertSent(function (HttpRequest $request): bool {
            if ($request->url() !== self::LINE_REPLY_URL) {
                return false;
            }
            $msg = $request['messages'][0] ?? null;
            if (! is_array($msg) || ($msg['type'] ?? '') !== 'flex') {
                return false;
            }
            $bubble = $msg['contents'] ?? [];
            if (($bubble['type'] ?? '') !== 'bubble') {
                return false;
            }
            $json = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Masked name "สม***", balance "450 แต้ม", expiring "200 แต้ม (31/12/2026)", and latest 5 items (+150, -30, +80, -50, +200; oldest E1 on 10/11/2026 excluded)
            return str_contains($json, 'สม***')
                && str_contains($json, '450 แต้ม')
                && str_contains($json, '200 แต้ม (31/12/2026)')
                && str_contains($json, '24/11/2026')
                && str_contains($json, '+150')
                && str_contains($json, '-30')
                && str_contains($json, '+80')
                && str_contains($json, '-50')
                && str_contains($json, '+200')
                && ! str_contains($json, '10/11/2026');
        });
    }

    public function test_check_points_from_unbound_user_explains_how_to_link_with_a_portal_code(): void
    {
        Http::fake([
            self::LINE_REPLY_URL => Http::response(['sentMessages' => []], 200),
        ]);

        $this->postLineTextWebhook('U_UNBOUND_001', 'เช็คแต้ม')->assertOk();

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::LINE_REPLY_URL
            && str_contains((string) ($request['messages'][0]['text'] ?? ''), 'LINK-รหัส'));
    }

    public function test_typing_a_members_phone_number_does_not_bind_line_or_reveal_points(): void
    {
        Http::fake([
            self::LINE_REPLY_URL => Http::response(['sentMessages' => []], 200),
        ]);
        $memberId = $this->createMember(['name' => 'สมศรี ร้านค้า', 'phone' => '0891234567']);
        DB::table('loyalty_points_ledger')->insert([
            'member_account_id' => $memberId, 'event_key' => 'OPEN:A', 'points_delta' => 250,
            'reason' => 'Initial points', 'created_at' => '2026-11-22 09:00:00',
        ]);

        $this->postLineTextWebhook('U_STRANGER', '089-123-4567')
            ->assertOk()
            ->assertJson(['ok' => true, 'bound' => 0]);

        $this->assertNull(DB::table('member_accounts')->where('id', $memberId)->value('line_user_id'));
        $this->assertDatabaseMissing('member_line_accounts', ['line_user_id' => 'U_STRANGER']);
        Http::assertSent(fn (HttpRequest $request) => ($request['messages'][0]['type'] ?? '') === 'text'
            && str_contains((string) $request['messages'][0]['text'], 'LINK-รหัส'));
        Http::assertNotSent(fn (HttpRequest $request) => str_contains(json_encode($request['messages'], JSON_UNESCAPED_UNICODE), '250'));
    }

    public function test_bplus_points_are_shown_only_after_staff_verify_the_shop_link(): void
    {
        Http::fake([
            self::LINE_REPLY_URL => Http::response(['sentMessages' => []], 200),
        ]);
        $memberId = $this->createMember(['line_user_id' => 'U_BOUND_ERP']);
        $customerId = DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS', 'erp_customer_code' => 'AR001', 'customer_name' => 'Shop',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_members')->insert([
            'source_system' => 'BPLUS', 'member_code' => 'M001', 'full_name' => 'Shop',
            'erp_point_balance' => 4321, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('member_erp_links')->insert([
            'member_account_id' => $memberId, 'erp_customer_id' => $customerId, 'erp_member_code' => 'M001',
            'verification_status' => 'pending', 'verified_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postLineTextWebhook('U_BOUND_ERP', 'เช็คแต้ม')->assertOk();
        Http::assertSent(fn (HttpRequest $request) => ($request['messages'][0]['type'] ?? '') === 'flex'
            && str_contains($request['messages'][0]['altText'], ': 0 แต้ม'));

        DB::table('member_erp_links')->where('member_account_id', $memberId)
            ->update(['verification_status' => 'verified', 'verified_at' => now()]);
        $this->postLineTextWebhook('U_BOUND_ERP', 'เช็คแต้ม')->assertOk();
        Http::assertSent(fn (HttpRequest $request) => ($request['messages'][0]['type'] ?? '') === 'flex'
            && str_contains($request['messages'][0]['altText'], '4,321 แต้ม'));
    }

    public function test_other_messages_still_record_into_line_inbox(): void
    {
        Http::fake([
            'https://api.line.me/*' => Http::response(['displayName' => 'ลูกค้าทดสอบ'], 200),
        ]);

        $this->postLineTextWebhook('U_CHAT_USER', 'สวัสดีครับ สอบถามราคาสินค้า')->assertOk();

        $this->assertDatabaseHas('line_chat_threads', [
            'line_user_id' => 'U_CHAT_USER',
            'last_message_preview' => 'สวัสดีครับ สอบถามราคาสินค้า',
            'status' => 'open',
        ]);

        $thread = DB::table('line_chat_threads')->where('line_user_id', 'U_CHAT_USER')->first();
        $this->assertNotNull($thread);

        $this->assertDatabaseHas('line_chat_messages', [
            'thread_id' => $thread->id,
            'direction' => 'incoming',
            'message_type' => 'text',
            'body' => 'สวัสดีครับ สอบถามราคาสินค้า',
        ]);
    }

    public function test_rich_menu_register_and_contact_admin_buttons_reply_cleanly(): void
    {
        Http::fake([
            'https://api.line.me/*' => Http::response(['displayName' => 'ลูกค้าทดสอบ'], 200),
        ]);

        // Button C: ติดต่อแอดมิน
        $this->postLineTextWebhook('U_MENU_USER', 'ติดต่อแอดมิน')->assertOk();
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::LINE_REPLY_URL
            && ($request['messages'][0]['text'] ?? '') === 'รับข้อความแล้วครับ ทีมงาน POPSTAR จะตอบกลับในแชทนี้');

        // Button A: สมัครสมาชิก (unbound user)
        $this->postLineTextWebhook('U_MENU_USER', 'สมัครสมาชิก')->assertOk();
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::LINE_REPLY_URL
            && str_contains((string) ($request['messages'][0]['text'] ?? ''), 'LINK-รหัส'));
    }
}
