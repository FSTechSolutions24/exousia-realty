<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = ['company_id', 'lead_id', 'property_listing_id', 'whatsapp_business_account_id', 'whatsapp_message_template_id', 'sent_by', 'recipient_phone', 'rendered_body', 'provider_message_id', 'status', 'provider_response', 'error_message', 'sent_at', 'failed_at'];

    protected $casts = ['provider_response' => 'array', 'sent_at' => 'datetime', 'failed_at' => 'datetime'];

    public function template() { return $this->belongsTo(WhatsAppMessageTemplate::class, 'whatsapp_message_template_id'); }
    public function account() { return $this->belongsTo(WhatsAppBusinessAccount::class, 'whatsapp_business_account_id'); }
    public function sender() { return $this->belongsTo(User::class, 'sent_by'); }
    public function propertyListing() { return $this->belongsTo(PropertyListing::class); }
}
