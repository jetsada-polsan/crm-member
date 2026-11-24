<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ErpAutoSyncCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const BRIDGE = 'http://host.docker.internal:18900';

    /** @var resource|null */
    private $fakeSqlServer = null;

    protected function tearDown(): void
    {
        if (is_resource($this->fakeSqlServer)) {
            fclose($this->fakeSqlServer);
        }

        parent::tearDown();
    }

    /**
     * Point the office SQL probe at a local socket that accepts connections.
     */
    private function officeSqlIsReachable(): void
    {
        $this->fakeSqlServer = stream_socket_server('tcp://127.0.0.1:0');
        [, $port] = explode(':', stream_socket_get_name($this->fakeSqlServer, false));
        config(['popstar.office_sql_host' => '127.0.0.1', 'popstar.office_sql_port' => (int) $port]);
    }

    /**
     * Point the office SQL probe at a local port that nothing listens on.
     */
    private function officeSqlIsUnreachable(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        [, $port] = explode(':', stream_socket_get_name($server, false));
        fclose($server);
        config(['popstar.office_sql_host' => '127.0.0.1', 'popstar.office_sql_port' => (int) $port]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePage(int $diKey, string $invoiceNo, bool $hasMore): array
    {
        return [
            'ok' => true,
            'source' => 'BPLUS',
            'next_cursor' => $diKey,
            'has_more' => $hasMore,
            'records' => [[
                'di_key' => $diKey,
                'branch_code' => '001',
                'doc_type' => 'IV',
                'dt_properties' => 307,
                'invoice_no' => $invoiceNo,
                'erp_customer_code' => 'AR-AUTO-1',
                'sold_at' => '2026-11-23 10:00:00',
                'before_vat_amount' => 100,
                'vat_amount' => 7,
                'gross_amount' => 107,
                'status' => 'posted',
                'items_complete' => true,
                'items' => [[
                    'line_no' => 1,
                    'erp_product_code' => 'P-AUTO-1',
                    'product_name' => 'Auto product',
                    'qty' => 1,
                ]],
            ]],
        ];
    }

    public function test_skips_without_calling_the_bridge_when_office_sql_is_unreachable(): void
    {
        $this->officeSqlIsUnreachable();
        Http::preventStrayRequests();
        Http::fake();

        $this->artisan('popstar:sync-erp')
            ->expectsOutputToContain('skipped: office SQL Server is unreachable')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('sync_runs')->count());
    }

    public function test_follows_the_invoice_cursor_across_pages_until_the_bridge_has_no_more(): void
    {
        $this->officeSqlIsReachable();
        Http::preventStrayRequests();
        Http::fake([
            self::BRIDGE.'/export/invoices*' => Http::sequence()
                ->push($this->invoicePage(1001, 'IV-AUTO-1', true), 200)
                ->push($this->invoicePage(1002, 'IV-AUTO-2', false), 200),
        ]);

        $this->artisan('popstar:sync-erp', ['--kind' => ['invoices']])
            ->expectsOutputToContain('invoices: pages 2, fetched 2, new 2, updated 0')
            ->assertSuccessful();

        $requestedAfterKeys = Http::recorded()->map(fn (array $pair): string => $pair[0]['after_key'])->all();
        $this->assertSame(['0', '1001'], $requestedAfterKeys);
        $this->assertSame(
            ['IV-AUTO-1', 'IV-AUTO-2'],
            DB::table('erp_sales_invoices')->orderBy('invoice_no')->pluck('invoice_no')->all(),
        );
        $this->assertDatabaseHas('erp_sync_cursors', ['source_system' => 'BPLUS', 'kind' => 'invoices', 'cursor_value' => 1002]);
        $this->assertSame(2, DB::table('sync_runs')->where('kind', 'invoices')->where('status', 'success')->whereNull('triggered_by')->count());
    }

    public function test_fails_and_stops_paging_when_the_bridge_cursor_does_not_advance(): void
    {
        $this->officeSqlIsReachable();
        Http::preventStrayRequests();
        Http::fake([
            self::BRIDGE.'/export/salespersons*' => Http::response([
                'ok' => true,
                'source' => 'BPLUS',
                'next_cursor' => 0,
                'has_more' => true,
                'records' => [['salesperson_code' => 'SL01', 'salesperson_name' => 'สมชาย']],
            ], 200),
        ]);

        $this->artisan('popstar:sync-erp', ['--kind' => ['salespersons']])
            ->expectsOutputToContain('cursor did not advance')
            ->assertFailed();

        Http::assertSentCount(1);
    }

    public function test_reports_failure_when_the_bridge_returns_an_error(): void
    {
        $this->officeSqlIsReachable();
        Http::preventStrayRequests();
        Http::fake([
            self::BRIDGE.'/export/customers*' => Http::response([
                'ok' => false,
                'error' => 'Login failed; Password=TopSecretPass!',
            ], 500),
        ]);

        $this->artisan('popstar:sync-erp', ['--kind' => ['customers']])
            ->expectsOutputToContain('customers: pages 1, fetched 0')
            ->doesntExpectOutputToContain('TopSecretPass!')
            ->assertFailed();

        $this->assertDatabaseHas('sync_runs', ['kind' => 'customers', 'status' => 'failed']);
    }

    public function test_rejects_an_unknown_kind_without_syncing(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->artisan('popstar:sync-erp', ['--kind' => ['stock']])
            ->expectsOutputToContain('Unknown kind: stock')
            ->assertExitCode(2);

        Http::assertNothingSent();
    }

    public function test_schedules_invoices_every_fifteen_minutes_and_masters_hourly(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'popstar:sync-erp'))
            ->mapWithKeys(fn ($event): array => [trim(strstr((string) $event->command, 'popstar:sync-erp')) => $event->expression])
            ->all();

        $this->assertSame([
            'popstar:sync-erp --kind=invoices' => '*/15 * * * *',
            'popstar:sync-erp --kind=salespersons --kind=customers --kind=products --kind=members' => '7 * * * *',
        ], $events);
    }
}
