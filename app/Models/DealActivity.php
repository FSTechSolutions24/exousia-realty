<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealActivity extends Model
{
    protected $fillable = [
        'company_id', 'deal_id', 'user_id', 'event', 'old_values', 'new_values', 'occurred_at',
    ];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'occurred_at' => 'datetime'];

    public function deal() { return $this->belongsTo(Deal::class); }
    public function user() { return $this->belongsTo(User::class); }
}
