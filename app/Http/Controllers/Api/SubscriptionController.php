<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\PaymobService;
use App\Services\SubscriptionService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

class SubscriptionController extends Controller
{
    public function plans()
    {
        return response()->json(['data' => SubscriptionPlan::active()->orderBy('position')->get()->map(fn ($plan) => [
            'id' => $plan->id, 'slug' => $plan->slug, 'name' => $plan->name, 'description' => $plan->description,
            'monthly_price_minor' => $plan->monthly_price_minor, 'yearly_price_minor' => $plan->yearly_price_minor,
            'team_member_limit' => $plan->team_member_limit,
            'currency' => $plan->currency, 'features' => $plan->features, 'featured' => $plan->is_featured,
            'trial_days' => $plan->isFreeTrial() ? SubscriptionService::TRIAL_DAYS : null,
        ])]);
    }

    public function status(Request $request, TenantContext $tenant, SubscriptionService $subscriptions, PaymobService $paymob)
    {
        $company = $tenant->company();
        return response()->json([
            'subscription' => $subscriptions->snapshot($company), 'paymob_available' => $paymob->isConfigured(),
            'company_suspended' => $company->is_suspended, 'suspension_reason' => $company->suspension_reason,
            'payments' => $company->subscriptionPayments()->with('plan')->latest()->limit(10)->get()->map(fn ($payment) => [
                'id' => $payment->id, 'plan' => $payment->plan->name, 'cycle' => $payment->billing_cycle,
                'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency, 'status' => $payment->status,
                'paid_at' => $payment->paid_at?->toIso8601String(), 'created_at' => $payment->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function startFreeTrial(Request $request, TenantContext $tenant, SubscriptionService $subscriptions)
    {
        $this->authorizeBilling($request);
        abort_if($tenant->company()->is_suspended, 403, 'This company is suspended. Contact Exousia support before changing plans.');
        $subscription = $subscriptions->startFreeTrial($tenant->company(), $request->user()->id);
        return response()->json(['message' => 'Your 14-day free plan is active.', 'subscription' => [
            'status' => $subscription->status, 'starts_at' => $subscription->starts_at->toIso8601String(), 'ends_at' => $subscription->ends_at->toIso8601String(),
        ]], 201);
    }

    public function checkout(Request $request, TenantContext $tenant, PaymobService $paymob)
    {
        $this->authorizeBilling($request);
        abort_if($tenant->company()->is_suspended, 403, 'This company is suspended. Contact Exousia support before starting a payment.');
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('subscription_plans', 'id')->where(fn ($query) => $query->where('is_active', true)->where('slug', '!=', 'free-trial'))],
            'billing_cycle' => ['required', Rule::in(['month', 'year'])],
        ]);
        $plan = SubscriptionPlan::active()->where('slug', '!=', 'free-trial')->findOrFail($data['plan_id']);
        if (! $plan->priceFor($data['billing_cycle'])) return response()->json(['message' => 'This billing option is not available.'], 422);
        try {
            return response()->json($paymob->createCheckout($tenant->company(), $request->user(), $plan, $data['billing_cycle']));
        } catch (RuntimeException $exception) {
            Log::warning('Subscription checkout could not be started.', ['company_id' => $tenant->id(), 'user_id' => $request->user()->id, 'reason' => $exception->getMessage()]);
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }

    public function paymobCallback(Request $request, PaymobService $paymob, SubscriptionService $subscriptions)
    {
        $data = $request->validate([
            'type' => ['required', 'in:TRANSACTION'], 'obj' => ['required', 'array'], 'obj.id' => ['required', 'integer'],
            'obj.amount_cents' => ['required', 'integer', 'min:0'], 'obj.currency' => ['required', 'string', 'size:3'],
            'obj.integration_id' => ['required', 'integer'], 'obj.order.id' => ['required'], 'obj.success' => ['required', 'boolean'],
            'obj.pending' => ['required', 'boolean'], 'obj.is_refunded' => ['sometimes', 'boolean'], 'obj.is_voided' => ['sometimes', 'boolean'],
            'hmac' => ['required', 'string', 'size:128', 'regex:/^[a-fA-F0-9]+$/'],
        ]);
        $object = $request->input('obj');
        if (! $paymob->validCallbackHmac($object, $data['hmac'])) abort(403);
        $integrations = $paymob->paymentMethods();
        if (! in_array((int) $object['integration_id'], array_map('intval', $integrations), true)) abort(403);
        $payment = SubscriptionPayment::where('gateway_order_id', (string) data_get($object, 'order.id'))->firstOrFail();
        if ((int) $object['amount_cents'] !== $payment->amount_minor || strtoupper($object['currency']) !== $payment->currency) {
            Log::warning('Rejected Paymob callback with a mismatched amount or currency.', ['payment_id' => $payment->id, 'transaction_id' => $object['id']]);
            abort(422);
        }
        if ($payment->status !== 'pending') return response()->noContent();
        if (! $object['success'] || $object['pending'] || ($object['is_refunded'] ?? false) || ($object['is_voided'] ?? false)) {
            $payment->update(['status' => $object['pending'] ? 'pending' : ((($object['is_refunded'] ?? false) || ($object['is_voided'] ?? false)) ? 'cancelled' : 'failed')]);
            return response()->noContent();
        }
        DB::transaction(function () use ($payment, $object, $subscriptions) {
            $locked = SubscriptionPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'pending') $subscriptions->activatePaidPayment($locked, (string) $object['id']);
        });
        return response()->noContent();
    }

    private function authorizeBilling(Request $request): void
    {
        abort_unless($request->attributes->get('membership')?->can('manage_billing'), 403, 'Only company owners and admins can manage billing.');
    }
}
