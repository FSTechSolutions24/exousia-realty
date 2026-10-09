<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealCommission extends Model
{
    protected $fillable = [
        'company_id', 'deal_id', 'company_membership_id', 'payee_name', 'amount_minor_units',
        'currency', 'status', 'due_on', 'paid_at', 'reference', 'notes', 'created_by',
        'property_listing_id', 'unit_reference', 'calculation_type', 'rate_basis_points', 'base_amount_minor_units',
    ];

    protected $casts = ['amount_minor_units' => 'integer', 'base_amount_minor_units' => 'integer', 'rate_basis_points' => 'integer', 'due_on' => 'date:Y-m-d', 'paid_at' => 'datetime'];

    public function deal() { return $this->belongsTo(Deal::class); }
    public function membership() { return $this->belongsTo(CompanyMembership::class, 'company_membership_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function propertyListing() { return $this->belongsTo(PropertyListing::class, 'property_listing_id')->withTrashed(); }
    public function signedDocuments() { return $this->hasMany(DealCommissionDocument::class, 'deal_commission_id')->latest(); }
    public function currentSignedDocument() { return $this->hasOne(DealCommissionDocument::class, 'deal_commission_id')->where('is_current', true)->where('mime_type', 'application/pdf')->latestOfMany(); }
}
