<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySubscription extends Model
{
    protected $fillable = ['company_id', 'plan_id', 'started_by', 'status', 'billing_cycle', 'is_free_trial', 'starts_at', 'ends_at', 'trial_started_at', 'trial_ends_at'];

    protected $casts = [
        'is_free_trial' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'trial_started_at' => 'datetime',
        'trial_ends_at' => 'datetime',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function plan() { return $this->belongsTo(SubscriptionPlan::class, 'plan_id'); }
    public function startedBy() { return $this->belongsTo(User::class, 'started_by'); }
}
