<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PipelineStage extends Model
{
    protected $fillable = ['company_id', 'name', 'name_ar', 'color', 'position', 'is_won', 'is_lost'];
    protected $casts = ['is_won' => 'boolean', 'is_lost' => 'boolean'];
}
