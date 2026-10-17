<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\User;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WhatsAppIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_tokens_are_encrypted_and_never_returned(): void
    {
        [$company, $owner] = $this->workspace('alpha');
        $response = $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->putJson('/api/v1/whatsapp/accounts/company', $this->accountPayload('company-secret-token'))
            ->assertOk()->assertJsonMissing(['access_token' => 'company-secret-token'])->assertJsonPath('scope', 'company');
        $accountId = $response->json('id');
        $this->assertNotSame('company-secret-token', DB::table('whatsapp_business_accounts')->where('id', $accountId)->value('access_token'));
        $this->assertSame('company-secret-token', WhatsAppBusinessAccount::findOrFail($accountId)->access_token);
    }

    public function test_personal_account_is_preferred_and_company_account_is_the_fallback(): void
    {
        config(['services.whatsapp.driver' => 'log']);
        [$company, $owner, $stage] = $this->workspace('alpha');
        $agent = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $agent->id, 'role' => 'agent', 'status' => 'active']);
        $lead = $this->lead($company, $agent, $stage);
        $companyAccount = WhatsAppBusinessAccount::create($this->accountAttributes($company->id, null, 'Company'));
        $personalAccount = WhatsAppBusinessAccount::create($this->accountAttributes($company->id, $agent->id, 'Personal'));
        $templateId = $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->getJson("/api/v1/leads/{$lead->id}/whatsapp/options")->assertOk()
            ->assertJsonPath('account.id', $personalAccount->id)->assertJsonPath('using_company_fallback', false)->json('templates.0.id');
        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->postJson("/api/v1/leads/{$lead->id}/whatsapp/messages", ['template_id' => $templateId])
            ->assertCreated()->assertJsonPath('status', 'logged');
        $this->assertDatabaseHas('whatsapp_messages', ['company_id' => $company->id, 'lead_id' => $lead->id, 'whatsapp_business_account_id' => $personalAccount->id]);
        $personalAccount->update(['is_active' => false]);
        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->getJson("/api/v1/leads/{$lead->id}/whatsapp/options")->assertOk()
            ->assertJsonPath('account.id', $companyAccount->id)->assertJsonPath('using_company_fallback', true);
    }

    public function test_agent_cannot_manage_company_account_but_can_link_their_own(): void
    {
        [$company, $owner] = $this->workspace('alpha');
        $agent = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $agent->id, 'role' => 'agent', 'status' => 'active']);
        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)->putJson('/api/v1/whatsapp/accounts/company', $this->accountPayload('secret'))->assertForbidden();
        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)->putJson('/api/v1/whatsapp/accounts/personal', $this->accountPayload('personal-secret'))->assertOk()->assertJsonPath('scope', 'personal');
    }

    public function test_messages_and_templates_cannot_cross_tenants(): void
    {
        [$companyA, $ownerA, $stageA] = $this->workspace('alpha');
        [$companyB, $ownerB, $stageB] = $this->workspace('beta');
        $leadA = $this->lead($companyA, $ownerA, $stageA); $leadB = $this->lead($companyB, $ownerB, $stageB);
        WhatsAppBusinessAccount::create($this->accountAttributes($companyA->id, null, 'Alpha'));
        $foreignTemplate = $this->actingAs($ownerB)->withHeader('X-Company-ID', $companyB->id)->getJson('/api/v1/whatsapp/settings')->assertOk()->json('templates.0.id');
        $this->actingAs($ownerA)->withHeader('X-Company-ID', $companyA->id)->getJson("/api/v1/leads/{$leadB->id}/whatsapp/options")->assertNotFound();
        $this->actingAs($ownerA)->withHeader('X-Company-ID', $companyA->id)->postJson("/api/v1/leads/{$leadA->id}/whatsapp/messages", ['template_id' => $foreignTemplate])->assertUnprocessable();
        $this->assertSame(0, WhatsAppMessage::count());
    }

    private function workspace(string $slug): array
    {
        $company = Company::create(['name' => ucfirst($slug), 'slug' => $slug]); $this->enableCompanyTrial($company);
        $owner = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        $stage = PipelineStage::create(['company_id' => $company->id, 'name' => 'New', 'position' => 0]);
        return [$company, $owner, $stage];
    }

    private function lead(Company $company, User $user, PipelineStage $stage): Lead
    {
        return Lead::create(['company_id' => $company->id, 'pipeline_stage_id' => $stage->id, 'created_by' => $user->id, 'assigned_to' => $user->id, 'name' => 'Fictional Buyer', 'phone_original' => '01012345678', 'phone_normalized' => '+201012345678']);
    }

    private function accountPayload(string $token): array
    {
        return ['display_name' => 'Business WhatsApp', 'phone_number_id' => '1234567890', 'business_account_id' => '987654321', 'access_token' => $token, 'graph_version' => 'v25.0'];
    }

    private function accountAttributes(int $companyId, ?int $userId, string $name): array
    {
        return ['company_id' => $companyId, 'user_id' => $userId, 'display_name' => $name, 'phone_number_id' => '1234567890', 'business_account_id' => '987654321', 'access_token' => 'encrypted-by-cast', 'graph_version' => 'v25.0', 'is_active' => true];
    }
}
