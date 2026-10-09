<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealCommissionDocument extends Model
{
    protected $fillable = [
        'company_id', 'deal_commission_id', 'uploaded_by', 'original_name', 'storage_path',
        'mime_type', 'size_bytes', 'is_current',
    ];

    protected $casts = ['size_bytes' => 'integer', 'is_current' => 'boolean'];

    public function commission() { return $this->belongsTo(DealCommission::class, 'deal_commission_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
}
