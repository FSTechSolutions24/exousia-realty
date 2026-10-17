<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PipelineStage;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_without_company_membership_can_bootstrap_and_view_global_reporting(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $company = $this->workspace('Report Realty');

        $this->actingAs($admin)->getJson('/api/v1/platform/bootstrap')->assertOk()
            ->assertJsonPath('is_platform_admin', true)->assertJsonPath('user.id', $admin->id);
        $this->getJson('/api/v1/platform/overview')->assertOk()
            ->assertJsonPath('metrics.companies', 1)->assertJsonPath('metrics.trial_companies', 1)
            ->assertJsonPath('companies.0.name', $company->name);
    }

    public function test_regular_company_admin_cannot_access_platform_admin_routes(): void
    {
        $user = User::factory()->create(['is_platform_admin' => false]);
        $this->actingAs($user)->getJson('/api/v1/platform/overview')->assertForbidden();
        $this->getJson('/api/v1/platform/paymob-settings')->assertForbidden();
        $this->putJson('/api/v1/platform/paymob-settings', [])->assertForbidden();
    }

    public function test_platform_owner_can_store_encrypted_paymob_settings_and_read_them_in_setup_page(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->putJson('/api/v1/platform/paymob-settings', [
            'secret_key' => 'live-secret-value', 'public_key' => 'live-public-value', 'hmac_secret' => 'private-hmac-value',
            'payment_methods' => [123456, 789012], 'api_base' => 'https://accept.paymob.com',
            'checkout_base' => 'https://accept.paymob.com/unifiedcheckout',
        ])->assertOk()->assertJsonPath('configured', true);

        $stored = DB::table('platform_payment_gateway_settings')->first();
        $this->assertNotSame('live-secret-value', $stored->secret_key);
        $this->assertNotSame('live-public-value', $stored->public_key);
        $this->assertNotSame('private-hmac-value', $stored->hmac_secret);

        $this->getJson('/api/v1/platform/paymob-settings')->assertOk()
            ->assertJsonPath('configured', true)->assertJsonPath('payment_methods', [123456, 789012])
            ->assertJsonPath('secret_key', 'live-secret-value')->assertJsonPath('public_key', 'live-public-value')
            ->assertJsonPath('hmac_secret', 'private-hmac-value');
    }

    public function test_platform_owner_setup_form_prefills_classic_paymob_values_from_environment_config(): void
    {
        config()->set('services.paymob.secret_key', 'photo-rent-api-key');
        config()->set('services.paymob.public_key', null);
        config()->set('services.paymob.hmac_secret', 'photo-rent-hmac');
        config()->set('services.paymob.payment_methods', [654321]);
        config()->set('services.paymob.iframe_id', '1234567');

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->getJson('/api/v1/platform/paymob-settings')->assertOk()
            ->assertJsonPath('source', 'environment')->assertJsonPath('configured', true)
            ->assertJsonPath('secret_key', 'photo-rent-api-key')->assertJsonPath('hmac_secret', 'photo-rent-hmac')
            ->assertJsonPath('payment_methods', [654321])->assertJsonPath('iframe_id', '1234567');
    }

    public function test_cli_can_create_a_dedicated_platform_owner_without_a_company(): void
    {
        $this->artisan('platform-admin:create', ['name' => 'Portal Owner', 'email' => 'portal-owner@example.test'])
            ->expectsQuestion('Password (at least 12 characters)', 'A-strong-password-123')
            ->expectsQuestion('Confirm password', 'A-strong-password-123')
            ->assertExitCode(0);

        $user = User::where('email', 'portal-owner@example.test')->firstOrFail();
        $this->assertTrue($user->is_platform_admin);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseCount('company_memberships', 0);
    }

    public function test_platform_admin_can_suspend_reactivate_and_audit_company_access(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        [$company, $owner] = $this->workspaceWithOwner('Controlled Realty');

        $this->actingAs($admin)->postJson('/api/v1/platform/companies/'.$company->id.'/suspend', ['reason' => 'Billing dispute review'])
            ->assertOk();
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'is_suspended' => true, 'suspension_reason' => 'Billing dispute review']);

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)->getJson('/api/v1/dashboard')
            ->assertForbidden()->assertJsonPath('code', 'company_suspended');

        $this->actingAs($admin)->postJson('/api/v1/platform/companies/'.$company->id.'/activate')->assertOk();
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'is_suspended' => false, 'suspension_reason' => null]);
        $this->assertDatabaseHas('platform_admin_actions', ['company_id' => $company->id, 'action' => 'company_suspended', 'admin_user_id' => $admin->id]);
        $this->assertDatabaseHas('platform_admin_actions', ['company_id' => $company->id, 'action' => 'company_reactivated', 'admin_user_id' => $admin->id]);
    }

    public function test_platform_admin_can_grant_audited_subscription_without_falsely_recording_payment(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $company = $this->workspace('Renewal Realty');
        $professional = SubscriptionPlan::where('slug', 'professional')->firstOrFail();

        $this->actingAs($admin)->postJson('/api/v1/platform/companies/'.$company->id.'/renew', [
            'plan_id' => $professional->id, 'billing_cycle' => 'month', 'periods' => 2,
            'renewal_mode' => 'extend', 'reason' => 'Goodwill extension after service interruption',
        ])->assertCreated()->assertJsonPath('subscription.plan', 'Professional');

        $this->assertDatabaseHas('company_subscriptions', [
            'company_id' => $company->id, 'plan_id' => $professional->id, 'billing_cycle' => 'month', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('platform_admin_actions', [
            'company_id' => $company->id, 'action' => 'subscription_granted', 'reason' => 'Goodwill extension after service interruption',
        ]);
        $this->assertDatabaseCount('subscription_payments', 0);
    }

    private function workspace(string $name): Company
    {
        $company = Company::create(['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name))]);
        $this->enableCompanyTrial($company);
        return $company;
    }

    private function workspaceWithOwner(string $name): array
    {
        $company = $this->workspace($name);
        $owner = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        PipelineStage::create(['company_id' => $company->id, 'name' => 'New lead', 'position' => 0]);
        return [$company, $owner];
    }
}
