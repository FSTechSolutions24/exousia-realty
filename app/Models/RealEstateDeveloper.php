<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealEstateDeveloper extends Model
{
    protected $fillable = ['company_id', 'name', 'contact_name', 'email', 'phone', 'website', 'notes', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function company() { return $this->belongsTo(Company::class); }
    public function listings() { return $this->hasMany(PropertyListing::class, 'developer_id')->where('company_id', $this->company_id); }
}
