<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class StaffUser extends Authenticatable
{
    protected $table = 'staff_users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'salesperson_code',
        'reservation_doc_types',
        'is_active',
    ];

    protected $hidden = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'must_change_password' => 'boolean',
        ];
    }
}
