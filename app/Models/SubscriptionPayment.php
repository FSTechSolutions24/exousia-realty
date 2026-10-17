<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    protected $fillable = ['company_id', 'initiated_by', 'plan_id', 'company_subscription_id', 'reference', 'gateway', 'status', 'billing_cycle', 'amount_minor', 'currency', 'gateway_order_id', 'gateway_intention_id', 'transaction_id', 'paid_at', 'gateway_metadata'];

    protected $casts = ['amount_minor' => 'integer', 'paid_at' => 'datetime', 'gateway_metadata' => 'array'];

    public function company() { return $this->belongsTo(Company::class); }
    public function plan() { return $this->belongsTo(SubscriptionPlan::class); }
}
