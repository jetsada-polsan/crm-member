<?php

use App\Models\StaffUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

/*
 * Optional local VPN/LAN ERP pull through the local Bplus bridge.
 * Disabled by default (POPSTAR_VPN_PULL_ENABLED=false) because syncing runs via HTTPS Push API
 * without VPN. When enabled, runs 3 times daily: morning (08:00), afternoon (13:00), evening (18:00).
 */
$vpnPullEnabled = static fn (): bool => (bool) config('popstar.vpn_pull_enabled', false);

Schedule::command('popstar:sync-erp --kind=invoices')
    ->cron('0 8,13,18 * * *')
    ->when($vpnPullEnabled)
    ->createMutexNameUsing('popstar-erp-sync-invoices')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/erp-sync.log'));

/*
 * Evening rescan refreshes bills edited or cancelled in Bplus over the last 3 days.
 * Shares the invoice mutex so it never overlaps with the regular invoice pull.
 */
Schedule::command('popstar:sync-erp --kind=invoices --rescan-days=3 --max-pages=20')
    ->dailyAt('18:20')
    ->when($vpnPullEnabled)
    ->createMutexNameUsing('popstar-erp-sync-invoices')
    ->withoutOverlapping(90)
    ->appendOutputTo(storage_path('logs/erp-sync.log'));

Schedule::command('popstar:sync-erp --kind=salespersons --kind=customers --kind=products --kind=members')
    ->cron('5 8,13,18 * * *')
    ->when($vpnPullEnabled)
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/erp-sync.log'));

Artisan::command('popstar:create-staff-admin {email}', function (string $email) {
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email');

        return 1;
    }
    $password = $this->secret('New staff password (min 12 chars)');
    if (strlen((string) $password) < 12) {
        $this->error('Password too short');

        return 1;
    }
    $name = $this->ask('Name', 'Administrator');
    StaffUser::updateOrCreate(['email' => $email], [
        'name' => $name, 'password' => Hash::make($password), 'role' => 'admin', 'is_active' => true,
    ]);
    $this->info('Staff admin saved.');
})->purpose('Create a staff administrator account');

Artisan::command('popstar:link-member {member_id} {source} {customer_code} {--verified}', function (
    string $member_id, string $source, string $customer_code
) {
    if (! $this->option('verified')) {
        $this->error('Verify customer ownership/consent first and use --verified.');

        return 1;
    }
    $customer = DB::table('erp_customers')->where('source_system', $source)
        ->where('erp_customer_code', $customer_code)->first();
    $member = DB::table('member_accounts')->where('id', $member_id)->first();
    if (! $customer || ! $member) {
        $this->error('Member or ERP customer not found.');

        return 1;
    }
    if (! $this->confirm('Confirm identity/consent verification completed?', false)) {
        return 1;
    }
    DB::table('member_erp_links')->updateOrInsert(['erp_customer_id' => $customer->id], [
        'member_account_id' => $member->id, 'verified_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->info('Verified link saved.');
})->purpose('Explicitly link a verified member to an ERP customer (admin only)');
