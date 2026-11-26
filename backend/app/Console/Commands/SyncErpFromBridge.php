<?php

namespace App\Console\Commands;

use App\Modules\ERPIntegration\LocalBridgeSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('popstar:sync-erp
    {--kind=* : Kinds to sync (salespersons, customers, products, members, invoices); default is all}
    {--max-pages=50 : Upper bound on bridge pages per kind for one run}
    {--rescan-days= : Invoices only: re-read every bill dated within this many days so Bplus edits are refreshed}
    {--after-key= : Invoices only: re-read bills from this Bplus DI_KEY onward (bootstrap a rescan)}')]
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

        $rescanDays = $this->option('rescan-days');
        $afterKeyOption = $this->option('after-key');
        foreach (['rescan-days' => $rescanDays, 'after-key' => $afterKeyOption] as $name => $value) {
            if ($value !== null && ! ctype_digit((string) $value)) {
                $this->error("--{$name} must be a whole number.");

                return self::INVALID;
            }
        }

        if (! $bridgeSync->isOfficeSqlReachable()) {
            $this->warn(now()->toDateTimeString().' skipped: office SQL Server is unreachable (VPN/LAN down).');

            return self::SUCCESS;
        }

        $maxPages = max(1, (int) $this->option('max-pages'));
        $allOk = true;

        foreach (array_values(array_intersect(self::DEFAULT_ORDER, $kinds)) as $kind) {
            $limit = self::PAGE_LIMITS[$kind] ?? self::DEFAULT_PAGE_LIMIT;
            $label = $kind;
            $startAfterKey = null;

            if ($kind === 'invoices' && $afterKeyOption !== null) {
                $startAfterKey = (int) $afterKeyOption;
                $label = "invoices (from key {$startAfterKey})";
            } elseif ($kind === 'invoices' && $rescanDays !== null) {
                $startAfterKey = $bridgeSync->recentInvoiceRescanAfterKey((int) $rescanDays);
                if ($startAfterKey === null) {
                    $this->warn("invoices: no Bplus document keys stored for the last {$rescanDays} days yet; run once with --after-key to bootstrap the rescan.");

                    continue;
                }
                $label = "invoices (rescan {$rescanDays} days from key {$startAfterKey})";
            }

            $totals = $bridgeSync->syncAll($kind, $maxPages, $limit, null, $startAfterKey);
            $summary = sprintf(
                '%s %s: pages %d, fetched %d, new %d, updated %d, skipped %d',
                now()->toDateTimeString(),
                $label,
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
