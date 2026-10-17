<?php

namespace App\Services;

use App\Models\Company;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\PlatformPaymentGatewaySetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PaymobService
{
    public function isConfigured(): bool
    {
        $settings = $this->settings();
        $hasMethods = count($settings['payment_methods']) > 0;
        $hasUnifiedCheckout = filled($settings['public_key']);
        $hasClassicCheckout = filled($settings['iframe_id']);
        return filled($settings['secret_key']) && filled($settings['hmac_secret']) && $hasMethods
            && ($hasUnifiedCheckout || $hasClassicCheckout);
    }

    public function usesEnvironmentSettings(): bool
    {
        return ! PlatformPaymentGatewaySetting::query()->exists() && $this->isConfigured();
    }

    public function paymentMethods(): array
    {
        return $this->settings()['payment_methods'];
    }

    private function settings(): array
    {
        $stored = PlatformPaymentGatewaySetting::first();
        if ($stored) {
            return [
                'api_base' => $stored->api_base,
                'checkout_base' => $stored->checkout_base,
                'secret_key' => $stored->secret_key,
                'public_key' => $stored->public_key,
                'hmac_secret' => $stored->hmac_secret,
                'payment_methods' => $stored->payment_methods ?? [],
                'iframe_id' => $stored->iframe_id,
            ];
        }

        return [
            'api_base' => config('services.paymob.api_base'),
            'checkout_base' => config('services.paymob.checkout_base'),
            'secret_key' => config('services.paymob.secret_key'),
            'public_key' => config('services.paymob.public_key'),
            'hmac_secret' => config('services.paymob.hmac_secret'),
            'payment_methods' => config('services.paymob.payment_methods', []),
            'iframe_id' => config('services.paymob.iframe_id'),
        ];
    }

    public function platformConfiguration(): array
    {
        return $this->settings();
    }

    public function createCheckout(Company $company, User $user, SubscriptionPlan $plan, string $cycle): array
    {
        if (! $this->isConfigured()) throw new RuntimeException('Paymob checkout is not available yet. Please contact Exousia support.');
        $settings = $this->settings();
        $amount = $plan->priceFor($cycle);
        if (! $amount || $amount < 1) throw new RuntimeException('The selected billing price is not available.');
        $reference = (string) Str::uuid();
        $payment = SubscriptionPayment::create([
            'company_id' => $company->id, 'initiated_by' => $user->id, 'plan_id' => $plan->id,
            'reference' => $reference, 'status' => 'pending', 'billing_cycle' => $cycle,
            'amount_minor' => $amount, 'currency' => 'EGP',
        ]);
        $names = preg_split('/\s+/', trim($user->name), 2) ?: ['Customer'];
        $payload = [
            'amount' => $amount,
            'currency' => 'EGP',
            'payment_methods' => $settings['payment_methods'],
            'items' => [[
                'name' => 'Exousia Realty - '.$plan->name.' ('.($cycle === 'year' ? 'Annual' : 'Monthly').')',
                'amount' => $amount, 'description' => 'Company workspace subscription for '.$company->name, 'quantity' => 1,
            ]],
            'billing_data' => [
                'first_name' => $names[0] ?: 'Customer', 'last_name' => $names[1] ?? 'Account',
                'phone_number' => $user->phone ?: 'N/A', 'email' => $user->email,
                'street' => 'Not provided', 'building' => '0', 'floor' => '0', 'apartment' => '0',
                'city' => 'Cairo', 'state' => 'Cairo', 'country' => 'EG', 'postal_code' => 'NA',
            ],
            'special_reference' => $reference, 'expiration' => 1800,
            'notification_url' => route('subscriptions.paymob-callback'),
            'redirection_url' => url('/billing?payment_return=1'),
            'extras' => ['subscription_reference' => $reference],
        ];

        try {
            if (filled($settings['iframe_id'])) {
                return $this->createIframeCheckout($payment, $payload, $amount, $settings);
            }

            $response = Http::acceptJson()->withHeaders([
                'Authorization' => 'Token '.$settings['secret_key'], 'Content-Type' => 'application/json',
            ])->timeout(20)->post(rtrim($settings['api_base'], '/').'/v1/intention/', $payload)->throw()->json();
            $orderId = data_get($response, 'intention_order_id') ?? data_get($response, 'order.id');
            $clientSecret = data_get($response, 'client_secret');
            if (! $orderId || ! $clientSecret) throw new RuntimeException('Paymob did not return checkout details.');

            $payment->update(['gateway_order_id' => (string) $orderId, 'gateway_intention_id' => data_get($response, 'id')]);
            $query = http_build_query(['publicKey' => $settings['public_key'], 'clientSecret' => $clientSecret]);
            return ['payment_id' => $payment->id, 'checkout_url' => rtrim($settings['checkout_base'], '/').'/?'.$query];
        } catch (\Throwable $exception) {
            $payment->update(['status' => 'failed']);
            Log::error('Paymob subscription intention failed.', ['payment_id' => $payment->id, 'company_id' => $company->id, 'exception' => $exception::class]);
            throw new RuntimeException('Unable to start Paymob checkout. Please try again later.');
        }
    }

    private function createIframeCheckout(SubscriptionPayment $payment, array $payload, int $amount, array $settings): array
    {
        $auth = Http::acceptJson()->timeout(20)->post(rtrim($settings['api_base'], '/').'/api/auth/tokens', [
            'api_key' => $settings['secret_key'],
        ])->throw()->json();
        $token = data_get($auth, 'token');
        if (! $token) throw new RuntimeException('Paymob did not return an authentication token.');

        $order = Http::acceptJson()->timeout(20)->post(rtrim($settings['api_base'], '/').'/api/ecommerce/orders', [
            'auth_token' => $token,
            'delivery_needed' => false,
            'amount_cents' => $amount,
            'currency' => 'EGP',
            'merchant_order_id' => $payment->reference,
            'items' => collect($payload['items'])->map(fn (array $item) => [
                'name' => $item['name'], 'amount_cents' => $item['amount'],
                'description' => $item['description'], 'quantity' => $item['quantity'],
            ])->all(),
        ])->throw()->json();
        $orderId = data_get($order, 'id');
        if (! $orderId) throw new RuntimeException('Paymob did not return an order ID.');

        $paymentKey = Http::acceptJson()->timeout(20)->post(rtrim($settings['api_base'], '/').'/api/acceptance/payment_keys', [
            'auth_token' => $token,
            'amount_cents' => $amount,
            'expiration' => 1800,
            'order_id' => $orderId,
            'billing_data' => $payload['billing_data'],
            'currency' => 'EGP',
            'integration_id' => $settings['payment_methods'][0],
        ])->throw()->json();
        $paymentToken = data_get($paymentKey, 'token');
        if (! $paymentToken) throw new RuntimeException('Paymob did not return a payment token.');

        $payment->update(['gateway_order_id' => (string) $orderId]);
        $query = http_build_query(['payment_token' => $paymentToken]);
        $base = 'https://accept.paymobsolutions.com/api/acceptance/iframes/'.rawurlencode((string) $settings['iframe_id']);
        return ['payment_id' => $payment->id, 'checkout_url' => $base.'?'.$query];
    }

    public function validCallbackHmac(array $object, string $provided): bool
    {
        $paths = ['amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment', 'is_voided', 'order.id', 'owner', 'pending', 'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success'];
        $message = collect($paths)->map(function ($path) use ($object) {
            $value = data_get($object, $path, '');
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        })->implode('');
        $secret = $this->settings()['hmac_secret'];
        return filled($secret) && hash_equals(hash_hmac('sha512', $message, $secret), strtolower($provided));
    }
}
