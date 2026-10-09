<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PreferredLocation extends Model
{
    protected $fillable = ['company_id', 'name', 'name_ar', 'is_active', 'position'];

    protected $casts = [
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
