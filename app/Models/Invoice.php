<?php
namespace App\Models;
use App\Models\Concerns\BelongsToBranch; use Illuminate\Database\Eloquent\Model;
class Invoice extends Model
{
    use BelongsToBranch;
    protected $fillable = ['company_id','branch_id','deal_id','lead_id','created_by','invoice_number','status','issue_date','due_date','billing_name','billing_phone','subtotal_minor_units','tax_minor_units','total_minor_units','amount_paid_minor_units','currency','notes','issued_at','paid_at'];
    protected $casts = ['issue_date'=>'date:Y-m-d','due_date'=>'date:Y-m-d','issued_at'=>'datetime','paid_at'=>'datetime','subtotal_minor_units'=>'integer','tax_minor_units'=>'integer','total_minor_units'=>'integer','amount_paid_minor_units'=>'integer'];
    public function items(){return $this->hasMany(InvoiceItem::class)->orderBy('position');} public function payments(){return $this->hasMany(InvoicePayment::class)->latest('paid_on');} public function lead(){return $this->belongsTo(Lead::class)->withTrashed();} public function deal(){return $this->belongsTo(Deal::class);}
}
