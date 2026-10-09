<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'pipeline_stage_id', 'assigned_to', 'created_by', 'name', 'phone_original',
        'phone_normalized', 'email', 'source', 'intent', 'budget_min', 'budget_max', 'currency',
        'preferred_locations', 'property_type', 'bedrooms', 'notes', 'next_follow_up_at', 'last_contacted_at',
    ];

    protected $casts = [
        'preferred_locations' => 'array',
        'budget_min' => 'integer',
        'budget_max' => 'integer',
        'next_follow_up_at' => 'datetime',
        'last_contacted_at' => 'datetime',
    ];

    public function stage() { return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id'); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function activities() { return $this->hasMany(LeadActivity::class)->latest(); }
    public function tasks() { return $this->hasMany(FollowUpTask::class); }
    public function deals() { return $this->hasMany(Deal::class); }
}
