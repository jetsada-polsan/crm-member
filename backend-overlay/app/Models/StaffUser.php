<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class StaffUser extends Authenticatable
{
    protected $table = 'staff_users';
    protected $fillable = ['name','email','password','role','salesperson_code','is_active'];
    protected $hidden = ['password','remember_token'];
    protected function casts(): array { return ['password'=>'hashed', 'is_active'=>'boolean']; }
}
