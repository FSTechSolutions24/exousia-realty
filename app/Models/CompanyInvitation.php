<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyInvitation extends Model
{
    protected $fillable = ['company_id', 'invited_by', 'name', 'email', 'role', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at'];

    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function company() { return $this->belongsTo(Company::class); }
    public function inviter() { return $this->belongsTo(User::class, 'invited_by'); }
}
