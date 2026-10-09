<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyListingActivity extends Model
{
    protected $fillable = ['company_id', 'property_listing_id', 'user_id', 'event', 'old_values', 'new_values', 'occurred_at'];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'occurred_at' => 'datetime'];

    public function listing() { return $this->belongsTo(PropertyListing::class, 'property_listing_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
