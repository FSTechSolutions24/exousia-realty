<?php

namespace App\Services;

use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessageTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppCloudApi
{
    public function sendTemplate(WhatsAppBusinessAccount $account, WhatsAppMessageTemplate $template, string $recipient, array $parameters): array
    {
        $driver = config('services.whatsapp.driver', 'log');
        if ($driver === 'log') {
            Log::info('WhatsApp template message', ['account_id' => $account->id, 'recipient' => $recipient, 'template' => $template->meta_template_name]);
            return ['driver' => 'log', 'message_id' => null, 'response' => ['logged' => true]];
        }
        if ($driver !== 'meta') throw new RuntimeException('Unsupported WhatsApp driver.');
        if (! $account->access_token || ! $account->phone_number_id) throw new RuntimeException('The selected WhatsApp Business account is incomplete.');

        $version = $account->graph_version ?: config('services.whatsapp.graph_version', 'v25.0');
        $response = Http::asJson()->withToken($account->access_token)->withOptions(['connect_timeout' => 5])
            ->timeout((int) config('services.whatsapp.timeout', 15))
            ->post("https://graph.facebook.com/{$version}/{$account->phone_number_id}/messages", [
                'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => ltrim($recipient, '+'), 'type' => 'template',
                'template' => ['name' => $template->meta_template_name, 'language' => ['code' => $template->language_code], 'components' => [[
                    'type' => 'body', 'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => (string) $value], $parameters),
                ]]],
            ]);
        if ($response->failed()) {
            Log::warning('Meta rejected a WhatsApp message.', ['account_id' => $account->id, 'status' => $response->status(), 'response' => $response->json()]);
            throw new RuntimeException((string) ($response->json('error.message') ?: 'Meta could not deliver this WhatsApp message.'));
        }
        return ['driver' => 'meta', 'message_id' => $response->json('messages.0.id'), 'response' => $response->json()];
    }
}
