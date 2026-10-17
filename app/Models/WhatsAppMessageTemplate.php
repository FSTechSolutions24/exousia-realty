<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessageTemplate extends Model
{
    protected $table = 'whatsapp_message_templates';

    protected $fillable = ['company_id', 'key', 'name', 'meta_template_name', 'language_code', 'body', 'parameter_keys', 'requires_property', 'is_active'];

    protected $casts = ['parameter_keys' => 'array', 'requires_property' => 'boolean', 'is_active' => 'boolean'];
}
