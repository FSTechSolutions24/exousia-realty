<?php
namespace App\Models;
use App\Models\Concerns\BelongsToBranch; use Illuminate\Database\Eloquent\Model;
class DealInstallment extends Model { use BelongsToBranch; protected $fillable=['company_id','branch_id','deal_id','sequence','due_date','amount_minor_units','paid_minor_units','status','paid_at','payment_method','payment_reference','notes']; protected $casts=['due_date'=>'date:Y-m-d','paid_at'=>'datetime','amount_minor_units'=>'integer','paid_minor_units'=>'integer']; public function deal(){return $this->belongsTo(Deal::class);} public function payments(){return $this->hasMany(DealInstallmentPayment::class,'deal_installment_id')->latest('paid_on');} }
