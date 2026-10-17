<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'phone', 'timezone', 'currency', 'locale', 'brand_color', 'logo_path', 'logo_mime_type', 'is_suspended', 'suspended_at', 'suspension_reason'];

    protected $casts = ['is_suspended' => 'boolean', 'suspended_at' => 'datetime'];

    public function memberships()
    {
        return $this->hasMany(CompanyMembership::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'company_memberships')
            ->withPivot(['role', 'status', 'permissions'])
            ->withTimestamps();
    }

    public function subscriptions() { return $this->hasMany(CompanySubscription::class); }
    public function latestSubscription() { return $this->hasOne(CompanySubscription::class)->latestOfMany('starts_at'); }
    public function subscriptionPayments() { return $this->hasMany(SubscriptionPayment::class); }
    public function leads() { return $this->hasMany(Lead::class); }
    public function propertyListings() { return $this->hasMany(PropertyListing::class); }
    public function developers() { return $this->hasMany(RealEstateDeveloper::class); }
    public function deals() { return $this->hasMany(Deal::class); }
}
