<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncPushApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'push-api-test-token-0123456789abcdef';

    /**
     * Bplus keeps several numbers in one phone field; the LAN pull already stores up to the
     * 128-char column, so the push path must accept the same customer rows.
     */
    public function test_customer_push_accepts_phone_lists_up_to_the_column_length_and_rejects_longer(): void
    {
        config()->set('popstar.sync_api_token', self::TOKEN);
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];
        $phoneList = '02-123-4567, 081-234-5678, 089-876-5432, 086-111-2222';

        $this->postJson('/api/sync/v1/customers', [
            'source' => 'BPLUS',
            'batch_id' => (string) Str::uuid(),
            'records' => [['erp_customer_code' => 'AR-PHONE', 'customer_name' => 'Many phones', 'phone' => $phoneList]],
        ], $headers)->assertOk();

        $this->assertDatabaseHas('erp_customers', ['erp_customer_code' => 'AR-PHONE', 'phone' => $phoneList]);

        $this->postJson('/api/sync/v1/customers', [
            'source' => 'BPLUS',
            'batch_id' => (string) Str::uuid(),
            'records' => [['erp_customer_code' => 'AR-TOO-LONG', 'customer_name' => 'Too long', 'phone' => str_repeat('9', 129)]],
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('records.0.phone');
    }
}
