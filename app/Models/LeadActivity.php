<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadActivity extends Model
{
    protected $fillable = ['company_id', 'lead_id', 'user_id', 'type', 'title', 'description', 'metadata', 'occurred_at'];
    protected $casts = ['metadata' => 'array', 'occurred_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
    public function lead() { return $this->belongsTo(Lead::class); }
}
