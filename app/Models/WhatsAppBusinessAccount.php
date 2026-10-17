<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppBusinessAccount extends Model
{
    protected $table = 'whatsapp_business_accounts';

    protected $fillable = ['company_id', 'user_id', 'display_name', 'phone_number_id', 'business_account_id', 'access_token', 'graph_version', 'is_active'];

    protected $hidden = ['access_token'];

    protected $casts = ['access_token' => 'encrypted', 'is_active' => 'boolean'];

    public function company() { return $this->belongsTo(Company::class); }
    public function user() { return $this->belongsTo(User::class); }
}
