<?php

use App\Models\MemberAccount;
use App\Models\StaffUser;

return [
    'defaults' => ['guard' => 'staff', 'passwords' => 'staff_users'],
    'guards' => [
        'staff' => ['driver' => 'session', 'provider' => 'staff_users'],
        'member' => ['driver' => 'session', 'provider' => 'member_accounts'],
    ],
    'providers' => [
        'staff_users' => ['driver' => 'eloquent', 'model' => StaffUser::class],
        'member_accounts' => ['driver' => 'eloquent', 'model' => MemberAccount::class],
    ],
    'passwords' => [
        'staff_users' => ['provider' => 'staff_users', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60],
        'member_accounts' => ['provider' => 'member_accounts', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60],
    ],
    'password_timeout' => 10800,
];
