<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealDocument extends Model
{
    protected $fillable = [
        'company_id', 'deal_id', 'uploaded_by', 'category', 'original_name', 'storage_path', 'mime_type', 'size_bytes',
    ];

    public function deal() { return $this->belongsTo(Deal::class); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
}
