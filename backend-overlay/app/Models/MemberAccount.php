<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class MemberAccount extends Authenticatable
{
    protected $table = 'member_accounts';
    protected $fillable = ['name','email','password','phone','status','line_user_id'];
    protected $hidden = ['password','remember_token'];
    protected function casts(): array { return ['password'=>'hashed']; }
}
