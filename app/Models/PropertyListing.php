<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PropertyListing extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'reference_code', 'title', 'description', 'listing_type', 'property_type', 'status',
        'location', 'preferred_location_id', 'address', 'price_minor_units', 'currency', 'bedrooms', 'bathrooms', 'area_sqm', 'listed_by',
    ];

    protected $casts = ['price_minor_units' => 'integer', 'bedrooms' => 'integer', 'bathrooms' => 'integer', 'area_sqm' => 'decimal:2'];

    public function company() { return $this->belongsTo(Company::class); }
    public function preferredLocation() { return $this->belongsTo(PreferredLocation::class); }
    public function lister() { return $this->belongsTo(User::class, 'listed_by'); }
    public function photos() { return $this->hasMany(PropertyPhoto::class)->orderBy('position'); }
    public function activities() { return $this->hasMany(PropertyListingActivity::class)->latest('occurred_at'); }
    public function deals() { return $this->hasMany(Deal::class); }
}
