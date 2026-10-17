<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class DealInstallmentPayment extends Model
{
    use BelongsToBranch;

    protected $fillable = ['company_id', 'branch_id', 'deal_id', 'deal_installment_id', 'recorded_by', 'amount_minor_units', 'paid_on', 'method', 'reference', 'notes'];

    protected $casts = ['amount_minor_units' => 'integer', 'paid_on' => 'date:Y-m-d'];

    public function installment() { return $this->belongsTo(DealInstallment::class, 'deal_installment_id'); }
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
}
