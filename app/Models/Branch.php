<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class Branch extends Model { protected $fillable=['company_id','name','code','phone','address','is_main','is_active'];protected $casts=['is_main'=>'boolean','is_active'=>'boolean'];public function memberships(){return $this->belongsToMany(CompanyMembership::class,'branch_memberships')->withTimestamps();} }
