<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformPaymentGatewaySetting;
use App\Services\PaymobService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformPaymentSettingsController extends Controller
{
    public function show(PaymobService $paymob)
    {
        $setting = PlatformPaymentGatewaySetting::first();
        $configuration = $paymob->platformConfiguration();

        return response()->json([
            'configured' => $paymob->isConfigured(),
            'source' => $setting ? 'dashboard' : ($paymob->usesEnvironmentSettings() ? 'environment' : 'none'),
            'has_secret_key' => (bool) $configuration['secret_key'],
            'has_public_key' => (bool) $configuration['public_key'],
            'has_hmac_secret' => (bool) $configuration['hmac_secret'],
            'payment_methods' => $configuration['payment_methods'],
            'iframe_id' => $configuration['iframe_id'],
            'secret_key' => $configuration['secret_key'],
            'public_key' => $configuration['public_key'],
            'hmac_secret' => $configuration['hmac_secret'],
            'api_base' => $configuration['api_base'],
            'checkout_base' => $configuration['checkout_base'],
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'secret_key' => ['nullable', 'string', 'max:4000'],
            'public_key' => ['nullable', 'string', 'max:4000'],
            'hmac_secret' => ['nullable', 'string', 'max:4000'],
            'iframe_id' => ['nullable', 'string', 'max:100'],
            'payment_methods' => ['required', 'array', 'min:1', 'max:20'],
            'payment_methods.*' => ['required', 'integer', 'min:1'],
            'api_base' => ['required', 'url', 'max:255'],
            'checkout_base' => ['required', 'url', 'max:255'],
        ]);

        $existing = PlatformPaymentGatewaySetting::first();
        foreach (['secret_key', 'public_key', 'hmac_secret', 'iframe_id'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            $fallback = $existing ? $existing->$key : config('services.paymob.'.$key);
            $data[$key] = $value !== '' ? $value : $fallback;
            if ($key !== 'iframe_id' && $key !== 'public_key' && ! filled($data[$key])) {
                throw ValidationException::withMessages([$key => 'Enter this credential before saving Paymob settings.']);
            }
        }
        if (! filled($data['public_key']) && ! filled($data['iframe_id'])) {
            throw ValidationException::withMessages(['public_key' => 'Enter a public key for Unified Checkout or an iframe ID for Classic Checkout.']);
        }

        DB::transaction(function () use ($data, $request) {
            $setting = PlatformPaymentGatewaySetting::query()->lockForUpdate()->firstOrNew(['id' => 1]);
            $setting->fill([
                'secret_key' => $data['secret_key'], 'public_key' => $data['public_key'], 'hmac_secret' => $data['hmac_secret'],
                'payment_methods' => array_values(array_unique(array_map('intval', $data['payment_methods']))),
                'iframe_id' => $data['iframe_id'],
                'api_base' => rtrim($data['api_base'], '/'), 'checkout_base' => rtrim($data['checkout_base'], '/'),
                'updated_by' => $request->user()->id,
            ])->save();
        });

        return response()->json(['message' => 'Paymob settings saved securely.', 'configured' => true]);
    }
}
