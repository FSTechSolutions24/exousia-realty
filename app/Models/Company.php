<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'phone', 'timezone', 'currency', 'locale', 'brand_color'];

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
}
