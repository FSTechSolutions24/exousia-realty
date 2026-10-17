<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformPaymentGatewaySetting extends Model
{
    protected $fillable = ['secret_key', 'public_key', 'hmac_secret', 'payment_methods', 'iframe_id', 'api_base', 'checkout_base', 'updated_by'];

    protected $casts = [
        'secret_key' => 'encrypted',
        'public_key' => 'encrypted',
        'hmac_secret' => 'encrypted',
        'payment_methods' => 'encrypted:array',
    ];
}
