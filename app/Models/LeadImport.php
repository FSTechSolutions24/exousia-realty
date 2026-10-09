<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadImport extends Model
{
    protected $fillable = ['company_id', 'imported_by', 'file_hash', 'row_count', 'created_count', 'skipped_count'];

    public function company() { return $this->belongsTo(Company::class); }
    public function user() { return $this->belongsTo(User::class, 'imported_by'); }
}
