<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'monthly_price_minor', 'yearly_price_minor', 'team_member_limit', 'currency', 'features', 'is_active', 'is_featured', 'position'];

    protected $casts = [
        'monthly_price_minor' => 'integer',
        'yearly_price_minor' => 'integer',
        'team_member_limit' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'position' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function priceFor(string $cycle): ?int
    {
        return $cycle === 'year' ? $this->yearly_price_minor : $this->monthly_price_minor;
    }

    public function isFreeTrial(): bool
    {
        return $this->slug === 'free-trial';
    }
}
