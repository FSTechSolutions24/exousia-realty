<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InvoicePayment extends Model { protected $fillable=['company_id','invoice_id','recorded_by','amount_minor_units','paid_on','method','reference','notes']; protected $casts=['amount_minor_units'=>'integer','paid_on'=>'date:Y-m-d']; public function recorder(){return $this->belongsTo(User::class,'recorded_by');} }
