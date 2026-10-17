<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InvoiceItem extends Model { protected $fillable=['company_id','invoice_id','description','quantity','unit_price_minor_units','line_total_minor_units','position']; protected $casts=['quantity'=>'integer','unit_price_minor_units'=>'integer','line_total_minor_units'=>'integer']; }
