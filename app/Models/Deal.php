<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deal extends Model
{
    protected $fillable = [
        'company_id', 'lead_id', 'property_listing_id', 'created_by', 'status',
        'expected_close_date', 'agreed_price_minor_units', 'currency', 'notes',
        'closed_at', 'salesperson_membership_id', 'salesperson_name',
        'amount_received_minor_units', 'payment_method', 'payment_reference', 'payment_received_on', 'payment_terms',
    ];

    protected $casts = [
        'expected_close_date' => 'date:Y-m-d',
        'agreed_price_minor_units' => 'integer',
        'amount_received_minor_units' => 'integer',
        'payment_received_on' => 'date:Y-m-d',
        'closed_at' => 'datetime',
    ];

    public function lead() { return $this->belongsTo(Lead::class)->withTrashed(); }
    public function propertyListing() { return $this->belongsTo(PropertyListing::class)->withTrashed(); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function activities() { return $this->hasMany(DealActivity::class)->latest('occurred_at'); }
    public function commissions() { return $this->hasMany(DealCommission::class); }
    public function documents() { return $this->hasMany(DealDocument::class); }
    public function salespersonMembership() { return $this->belongsTo(CompanyMembership::class, 'salesperson_membership_id'); }
}
