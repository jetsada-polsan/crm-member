<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LineChatInboxTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const LINE_PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('popstar.line_channel_access_token', 'line-test-token');
        Http::preventStrayRequests();
    }

    private function createStaff(string $role): StaffUser
    {
        $id = DB::table('staff_users')->insertGetId([
            'name' => 'Staff '.$role,
            'email' => $role.uniqid().'@popstar.local',
            'password' => Hash::make('Secret123!'),
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StaffUser::query()->findOrFail($id);
    }

    private function thread(string $lineUserId = 'U_REAL_CUSTOMER'): int
    {
        return DB::table('line_chat_threads')->insertGetId([
            'line_user_id' => $lineUserId, 'display_name' => 'ร้านทดสอบ', 'status' => 'open',
            'unread_count' => 3, 'last_message_preview' => 'สวัสดี', 'last_message_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_reply_pushes_the_message_to_line_and_marks_the_thread_read(): void
    {
        Http::fake([self::LINE_PUSH_URL => Http::response(['sentMessages' => []], 200)]);
        $threadId = $this->thread();
        $staff = $this->createStaff('sales');
        $this->actingAs($staff, 'staff');

        $this->postJson("/api/crm/line-chat/threads/{$threadId}/reply", ['body' => '  ขอบคุณครับ  '])
            ->assertCreated()
            ->assertJson(['ok' => true, 'delivery_status' => 'sent']);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::LINE_PUSH_URL
            && $request->hasHeader('Authorization', 'Bearer line-test-token')
            && $request['to'] === 'U_REAL_CUSTOMER'
            && $request['messages'] === [['type' => 'text', 'text' => 'ขอบคุณครับ']]);
        $this->assertDatabaseHas('line_chat_messages', [
            'thread_id' => $threadId, 'direction' => 'outgoing', 'sender_staff_id' => $staff->id,
            'body' => 'ขอบคุณครับ', 'delivery_status' => 'sent',
        ]);
        $thread = DB::table('line_chat_threads')->where('id', $threadId)->first();
        $this->assertSame(0, (int) $thread->unread_count);
        $this->assertSame($staff->id, (int) $thread->assigned_staff_id);
    }

    public function test_reply_records_a_failed_delivery_when_line_rejects_the_push(): void
    {
        Http::fake([self::LINE_PUSH_URL => Http::response(['message' => 'Invalid reply token'], 400)]);
        $threadId = $this->thread();
        $this->actingAs($this->createStaff('sales'), 'staff');

        $this->postJson("/api/crm/line-chat/threads/{$threadId}/reply", ['body' => 'hello'])
            ->assertCreated()
            ->assertJson(['ok' => false, 'delivery_status' => 'failed']);

        $message = DB::table('line_chat_messages')->where('thread_id', $threadId)->first();
        $this->assertSame('failed', $message->delivery_status);
        $this->assertStringStartsWith('LINE HTTP 400', $message->delivery_error);
    }

    public function test_reply_to_a_simulated_customer_never_calls_line(): void
    {
        Http::fake();
        $threadId = $this->thread('DEMO_abc123');
        $this->actingAs($this->createStaff('sales'), 'staff');

        $this->postJson("/api/crm/line-chat/threads/{$threadId}/reply", ['body' => 'hello'])
            ->assertCreated()
            ->assertJson(['ok' => true, 'delivery_status' => 'simulated']);

        Http::assertNothingSent();
    }

    public function test_reply_is_refused_for_executives_blank_messages_and_unknown_threads(): void
    {
        Http::fake();
        $threadId = $this->thread();

        $this->actingAs($this->createStaff('executive'), 'staff');
        $this->postJson("/api/crm/line-chat/threads/{$threadId}/reply", ['body' => 'hello'])->assertForbidden();

        $this->actingAs($this->createStaff('sales'), 'staff');
        $this->postJson("/api/crm/line-chat/threads/{$threadId}/reply", ['body' => '   '])->assertUnprocessable();
        $this->postJson('/api/crm/line-chat/threads/999999/reply', ['body' => 'hello'])->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('line_chat_messages', 0);
    }

    public function test_staff_link_a_thread_to_an_erp_customer_and_resolve_it(): void
    {
        $threadId = $this->thread();
        $customerId = DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS', 'erp_customer_code' => 'AR001', 'customer_name' => 'Shop',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->createStaff('manager'), 'staff');

        $this->postJson("/api/crm/line-chat/threads/{$threadId}/link-customer", ['erp_customer_id' => $customerId])->assertOk();
        $this->assertSame($customerId, (int) DB::table('line_chat_threads')->where('id', $threadId)->value('erp_customer_id'));

        $this->patchJson("/api/crm/line-chat/threads/{$threadId}", ['status' => 'resolved'])->assertOk();
        $this->assertSame('resolved', DB::table('line_chat_threads')->where('id', $threadId)->value('status'));
        $this->patchJson("/api/crm/line-chat/threads/{$threadId}", ['status' => 'archived'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->getJson('/api/crm/line-chat/threads?filter=resolved')->assertOk();
        $this->getJson("/api/crm/line-chat/threads/{$threadId}/messages")->assertOk();
    }

    public function test_only_admins_and_managers_can_simulate_incoming_messages(): void
    {
        $this->actingAs($this->createStaff('sales'), 'staff');
        $this->postJson('/api/crm/line-chat/simulate-incoming', ['display_name' => 'Demo', 'body' => 'hi'])->assertForbidden();

        $this->actingAs($this->createStaff('manager'), 'staff');
        $threadId = $this->postJson('/api/crm/line-chat/simulate-incoming', ['display_name' => 'Demo', 'body' => 'hi'])
            ->assertCreated()
            ->json('thread_id');

        $this->assertStringStartsWith('DEMO_', DB::table('line_chat_threads')->where('id', $threadId)->value('line_user_id'));
    }
}
