<?php

use App\Models\StaffUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

/*
 * ERP auto-sync through the local Bplus bridge. The Windows task "POPSTAR schedule-run" fires
 * schedule:run every minute; each run skips quietly when the office SQL Server is unreachable.
 */
Schedule::command('popstar:sync-erp --kind=invoices')
    ->everyFifteenMinutes()
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/erp-sync.log'));

Schedule::command('popstar:sync-erp --kind=salespersons --kind=customers --kind=products --kind=members')
    ->hourlyAt(7)
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
