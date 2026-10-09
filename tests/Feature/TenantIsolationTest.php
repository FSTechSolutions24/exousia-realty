<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_cannot_read_a_lead_from_another_company(): void
    {
        [$companyA, $userA, $stageA] = $this->workspace('alpha');
        [$companyB, $userB, $stageB] = $this->workspace('beta');
        $foreignLead = $this->lead($companyB, $userB, $stageB, 'Foreign customer');

        $this->actingAs($userA)->withHeader('X-Company-ID', $companyA->id)
            ->getJson("/api/v1/leads/{$foreignLead->id}")
            ->assertNotFound();
    }

    public function test_an_invalid_company_header_does_not_switch_tenant_context(): void
    {
        [$companyA, $userA, $stageA] = $this->workspace('alpha');
        [$companyB] = $this->workspace('beta');
        $lead = $this->lead($companyA, $userA, $stageA, 'Visible customer');

        $this->actingAs($userA)->withHeader('X-Company-ID', $companyB->id)
            ->getJson('/api/v1/leads')
            ->assertOk()->assertJsonFragment(['id' => $lead->id]);
        $this->assertSame($companyA->id, $userA->fresh()->current_company_id);
    }

    public function test_an_agent_cannot_read_another_agents_lead(): void
    {
        [$company, $owner, $stage] = $this->workspace('alpha');
        $agent = User::factory()->create(['current_company_id' => $company->id]);
        $otherAgent = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->createMany([
            ['user_id' => $agent->id, 'role' => 'agent', 'status' => 'active'],
            ['user_id' => $otherAgent->id, 'role' => 'agent', 'status' => 'active'],
        ]);
        $lead = $this->lead($company, $owner, $stage, 'Private assignment');
        $lead->update(['assigned_to' => $otherAgent->id]);

        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->getJson("/api/v1/leads/{$lead->id}")->assertNotFound();
    }

    public function test_a_task_cannot_be_completed_across_companies(): void
    {
        [$companyA, $userA] = $this->workspace('alpha');
        [$companyB, $userB, $stageB] = $this->workspace('beta');
        $leadB = $this->lead($companyB, $userB, $stageB, 'Foreign task lead');
        $task = FollowUpTask::create(['company_id' => $companyB->id, 'lead_id' => $leadB->id, 'assigned_to' => $userB->id, 'created_by' => $userB->id, 'title' => 'Private task', 'due_at' => now()]);

        $this->actingAs($userA)->withHeader('X-Company-ID', $companyA->id)
            ->patchJson("/api/v1/tasks/{$task->id}/complete")->assertNotFound();
    }

    private function workspace(string $slug): array
    {
        $company = Company::create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $stage = PipelineStage::create(['company_id' => $company->id, 'name' => 'New lead', 'position' => 0]);
        return [$company, $user, $stage];
    }

    private function lead(Company $company, User $user, PipelineStage $stage, string $name): Lead
    {
        return Lead::create(['company_id' => $company->id, 'pipeline_stage_id' => $stage->id, 'created_by' => $user->id, 'assigned_to' => $user->id, 'name' => $name, 'phone_original' => '01012345678', 'phone_normalized' => '+201012345678']);
    }
}
