<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Laravel writes dates as offset-less "Y-m-d H:i:s" strings and PostgreSQL reads them in its
 * session timezone. These tests pin that a written timestamp names the same instant once
 * PostgreSQL interprets it, so app-written times line up with Bplus times such as sold_at.
 */
class TimestampTimezoneTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const INSTANT_UTC = '2026-11-24 11:00:00';

    private const BANGKOK_WALL_CLOCK = '2026-11-24 18:00:00';

    public function test_app_timezone_matches_the_postgres_session_timezone(): void
    {
        $this->assertSame('Asia/Bangkok', config('app.timezone'));
        $this->assertSame(config('app.timezone'), config('database.connections.pgsql.timezone'));
    }

    public function test_query_builder_timestamp_names_the_same_instant_in_the_postgres_session(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::INSTANT_UTC, 'UTC'));

        $syncRunId = DB::table('sync_runs')->insertGetId([
            'kind' => 'invoices',
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $storedStartedAt = DB::table('sync_runs')->where('id', $syncRunId)->value('started_at');

        $this->assertSame(self::BANGKOK_WALL_CLOCK, $storedStartedAt);
        $this->assertTrue($this->asReadByPostgres($storedStartedAt)->equalTo(now()));
    }

    public function test_eloquent_timestamps_name_the_same_instant_in_the_postgres_session(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::INSTANT_UTC, 'UTC'));

        $staff = StaffUser::create([
            'name' => 'Timezone Probe',
            'email' => 'timezone-probe@example.test',
            'password' => 'not-used-in-this-test',
            'role' => 'sales',
        ]);

        $storedCreatedAt = DB::table('staff_users')->where('id', $staff->id)->value('created_at');

        $this->assertSame(self::BANGKOK_WALL_CLOCK, $storedCreatedAt);
        $this->assertTrue($this->asReadByPostgres($storedCreatedAt)->equalTo(now()));
        $this->assertTrue($staff->fresh()->created_at->equalTo(now()));
    }

    /**
     * Interpret an offset-less stored timestamp the way PostgreSQL does for timestamptz columns.
     */
    private function asReadByPostgres(string $storedTimestamp): CarbonImmutable
    {
        return CarbonImmutable::parse($storedTimestamp, config('database.connections.pgsql.timezone'));
    }
}
