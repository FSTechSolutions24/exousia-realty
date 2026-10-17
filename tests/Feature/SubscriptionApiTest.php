<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PipelineStage;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_plans_include_monthly_yearly_and_one_free_trial(): void
    {
        $this->getJson('/api/v1/subscription-plans')->assertOk()
            ->assertJsonPath('data.0.slug', 'free-trial')->assertJsonPath('data.0.trial_days', 14)
            ->assertJsonPath('data.1.monthly_price_minor', 29900)->assertJsonPath('data.1.yearly_price_minor', 299000);
    }

    public function test_company_can_start_its_free_trial_once_and_trial_expiry_blocks_workspace_apis(): void
    {
        [$company, $owner, $stage] = $this->workspace();
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/subscriptions/free-trial')->assertCreated();
        $subscription = $company->subscriptions()->firstOrFail();
        $this->assertTrue($subscription->is_free_trial);
        $this->assertEquals(now()->addDays(14)->timestamp, $subscription->ends_at->timestamp);
        $this->postJson('/api/v1/subscriptions/free-trial')->assertUnprocessable()->assertJsonValidationErrors('plan');

        $subscription->update(['ends_at' => now()->subMinute(), 'trial_ends_at' => now()->subMinute()]);
        $this->getJson('/api/v1/dashboard')->assertStatus(402)->assertJsonPath('code', 'subscription_required');
    }

    public function test_only_company_billing_managers_can_start_a_trial(): void
    {
        [$company, , $stage] = $this->workspace();
        $agent = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $agent->id, 'role' => 'agent', 'status' => 'active']);
        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/subscriptions/free-trial')->assertForbidden();
        $this->assertDatabaseCount('company_subscriptions', 0);
    }

    public function test_paymob_callback_activates_the_paid_plan_after_hmac_and_amount_validation(): void
    {
        [$company, $owner] = $this->workspace();
        config()->set('services.paymob.secret_key', 'test-secret');
        config()->set('services.paymob.public_key', 'test-public');
        config()->set('services.paymob.hmac_secret', 'test-hmac-secret');
        config()->set('services.paymob.payment_methods', [501]);
        config()->set('services.paymob.iframe_id', null);
        Http::fake(['https://accept.paymob.com/v1/intention/' => Http::response(['intention_order_id' => 9876, 'id' => 'pi_test', 'client_secret' => 'client-test'], 201)]);
        $professional = SubscriptionPlan::where('slug', 'professional')->firstOrFail();

        $response = $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/subscriptions/checkout', [
            'plan_id' => $professional->id, 'billing_cycle' => 'month',
        ])->assertOk()->assertJsonStructure(['payment_id', 'checkout_url']);
        $payment = SubscriptionPayment::findOrFail($response->json('payment_id'));
        $this->assertSame('pending', $payment->status);
        $this->assertSame(59900, $payment->amount_minor);

        $object = [
            'id' => 321, 'amount_cents' => 59900, 'currency' => 'EGP', 'error_occured' => false,
            'has_parent_transaction' => false, 'integration_id' => 501, 'is_3d_secure' => true,
            'is_auth' => false, 'is_capture' => false, 'is_refunded' => false, 'is_standalone_payment' => true,
            'is_voided' => false, 'order' => ['id' => 9876], 'owner' => 1, 'pending' => false,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
            'success' => true, 'created_at' => '2026-10-17T12:00:00Z',
        ];
        $paths = ['amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment', 'is_voided', 'order.id', 'owner', 'pending', 'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success'];
        $value = collect($paths)->map(function ($path) use ($object) { $v = data_get($object, $path, ''); return is_bool($v) ? ($v ? 'true' : 'false') : (string) $v; })->implode('');
        $hmac = hash_hmac('sha512', $value, 'test-hmac-secret');
        $this->assertTrue(app(\App\Services\PaymobService::class)->validCallbackHmac($object, $hmac));

        $callback = $this->postJson('/api/v1/subscriptions/paymob/callback', ['type' => 'TRANSACTION', 'obj' => $object, 'hmac' => $hmac]);
        $this->assertSame(204, $callback->getStatusCode(), $callback->getContent());
        $this->assertDatabaseHas('subscription_payments', ['id' => $payment->id, 'status' => 'paid', 'transaction_id' => '321']);
        $this->assertDatabaseHas('company_subscriptions', ['company_id' => $company->id, 'plan_id' => $professional->id, 'status' => 'active', 'is_free_trial' => false]);
        $this->postJson('/api/v1/subscriptions/paymob/callback', ['type' => 'TRANSACTION', 'obj' => $object, 'hmac' => $hmac])->assertNoContent();
        $this->assertDatabaseCount('company_subscriptions', 1);
    }

    public function test_paymob_classic_iframe_credentials_can_start_checkout_without_a_phone_form_field(): void
    {
        [$company, $owner] = $this->workspace();
        config()->set('services.paymob.secret_key', 'classic-api-key');
        config()->set('services.paymob.public_key', null);
        config()->set('services.paymob.hmac_secret', 'classic-hmac');
        config()->set('services.paymob.payment_methods', [501]);
        config()->set('services.paymob.iframe_id', '1234567');
        Http::fake([
            'https://accept.paymob.com/api/auth/tokens' => Http::response(['token' => 'auth-token'], 201),
            'https://accept.paymob.com/api/ecommerce/orders' => Http::response(['id' => 9877], 201),
            'https://accept.paymob.com/api/acceptance/payment_keys' => Http::response(['token' => 'payment-token'], 201),
        ]);
        $professional = SubscriptionPlan::where('slug', 'professional')->firstOrFail();

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/subscriptions/checkout', [
            'plan_id' => $professional->id, 'billing_cycle' => 'month',
        ])->assertOk()->assertJsonPath('checkout_url', 'https://accept.paymobsolutions.com/api/acceptance/iframes/1234567?payment_token=payment-token');
    }

    private function workspace(): array
    {
        $company = Company::create(['name' => 'Subscription Realty', 'slug' => 'sub-'.uniqid()]);
        $owner = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        $stage = PipelineStage::create(['company_id' => $company->id, 'name' => 'New lead', 'position' => 0]);
        return [$company, $owner, $stage];
    }
}
