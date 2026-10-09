<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PropertyPhoto extends Model
{
    use SoftDeletes;

    protected $fillable = ['company_id', 'property_listing_id', 'storage_path', 'original_name', 'mime_type', 'size_bytes', 'position'];

    protected $casts = ['size_bytes' => 'integer', 'position' => 'integer'];

    public function listing() { return $this->belongsTo(PropertyListing::class, 'property_listing_id'); }
    public function company() { return $this->belongsTo(Company::class); }
}
