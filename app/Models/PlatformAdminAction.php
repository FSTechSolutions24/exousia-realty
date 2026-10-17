<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformAdminAction extends Model
{
    public $timestamps = false;

    protected $fillable = ['admin_user_id', 'company_id', 'action', 'reason', 'details', 'ip_address', 'user_agent', 'created_at'];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public function admin() { return $this->belongsTo(User::class, 'admin_user_id'); }
    public function company() { return $this->belongsTo(Company::class); }
}
