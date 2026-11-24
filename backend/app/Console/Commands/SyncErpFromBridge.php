<?php

namespace App\Console\Commands;

use App\Modules\ERPIntegration\LocalBridgeSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('popstar:sync-erp
    {--kind=* : Kinds to sync (salespersons, customers, products, members, invoices); default is all}
    {--max-pages=50 : Upper bound on bridge pages per kind for one run}')]
#[Description('Pull ERP data from the local Bplus bridge into the CRM mirror (skips quietly when the office SQL Server is unreachable)')]
class SyncErpFromBridge extends Command
{
    /**
     * Masters first so invoices can bind to fresh customers and salespeople.
     */
    private const DEFAULT_ORDER = ['salespersons', 'customers', 'products', 'members', 'invoices'];

    /**
     * Largest page sizes the bridge sync accepts.
     */
    private const PAGE_LIMITS = ['invoices' => 500];

    private const DEFAULT_PAGE_LIMIT = 5000;

    public function handle(LocalBridgeSync $bridgeSync): int
    {
        $kinds = $this->option('kind') ?: self::DEFAULT_ORDER;
        $unknown = array_diff($kinds, LocalBridgeSync::DATA_KINDS);
        if ($unknown !== []) {
            $this->error('Unknown kind: '.implode(', ', $unknown));

            return self::INVALID;
        }

        if (! $bridgeSync->isOfficeSqlReachable()) {
            $this->warn(now()->toDateTimeString().' skipped: office SQL Server is unreachable (VPN/LAN down).');

            return self::SUCCESS;
        }

        $maxPages = max(1, (int) $this->option('max-pages'));
        $allOk = true;

        foreach (array_values(array_intersect(self::DEFAULT_ORDER, $kinds)) as $kind) {
            $limit = self::PAGE_LIMITS[$kind] ?? self::DEFAULT_PAGE_LIMIT;
            $totals = $bridgeSync->syncAll($kind, $maxPages, $limit);
            $summary = sprintf(
                '%s %s: pages %d, fetched %d, new %d, updated %d, skipped %d',
                now()->toDateTimeString(),
                $kind,
                $totals['pages'],
                $totals['fetched'],
                $totals['inserted'],
                $totals['updated'],
                $totals['skipped'],
            );

            if ($totals['ok']) {
                $this->info($summary);

                continue;
            }

            $allOk = false;
            $this->error($summary.' — failed: '.$totals['error']);
            Log::warning('Scheduled ERP sync failed', ['kind' => $kind, 'error' => $totals['error']]);
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }
}
