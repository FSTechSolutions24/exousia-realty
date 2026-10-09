<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealCommission extends Model
{
    protected $fillable = [
        'company_id', 'deal_id', 'company_membership_id', 'payee_name', 'amount_minor_units',
        'currency', 'status', 'due_on', 'paid_at', 'reference', 'notes', 'created_by',
    ];

    protected $casts = ['amount_minor_units' => 'integer', 'due_on' => 'date:Y-m-d', 'paid_at' => 'datetime'];

    public function deal() { return $this->belongsTo(Deal::class); }
    public function membership() { return $this->belongsTo(CompanyMembership::class, 'company_membership_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
