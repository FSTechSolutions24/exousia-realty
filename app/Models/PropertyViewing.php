<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyViewing extends Model
{
    protected $fillable = [
        'company_id', 'lead_id', 'property_listing_id', 'scheduled_by', 'scheduled_at',
        'listing_title', 'listing_reference_code', 'listing_location', 'listing_price_minor_units',
        'status', 'buyer_rating', 'buyer_feedback',
    ];

    protected $casts = ['scheduled_at' => 'datetime', 'buyer_rating' => 'integer', 'listing_price_minor_units' => 'integer'];

    public function lead() { return $this->belongsTo(Lead::class); }
    public function listing() { return $this->belongsTo(PropertyListing::class, 'property_listing_id'); }
    public function scheduler() { return $this->belongsTo(User::class, 'scheduled_by'); }
}
