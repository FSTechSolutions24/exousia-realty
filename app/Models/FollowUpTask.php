<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FollowUpTask extends Model
{
    protected $fillable = ['company_id', 'lead_id', 'assigned_to', 'created_by', 'title', 'description', 'priority', 'due_at', 'completed_at'];
    protected $casts = ['due_at' => 'datetime', 'completed_at' => 'datetime'];

    public function lead() { return $this->belongsTo(Lead::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
}
