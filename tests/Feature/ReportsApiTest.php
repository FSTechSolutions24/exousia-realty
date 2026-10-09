<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_report_summarizes_only_company_leads_and_deals_for_selected_period(): void
    {
        [$company, $owner] = $this->workspace('owner');
        [$otherCompany, $otherOwner] = $this->workspace('owner', 'Other Reports Workspace');
        $stages = $this->stages($company);
        $newLead = $this->lead($company, $owner, $stages['New'], 'website');
        $wonLead = $this->lead($company, $owner, $stages['Won'], 'website');
        $lostLead = $this->lead($company, $owner, $stages['Lost'], 'referral');
        $foreignLead = $this->lead($otherCompany, $otherOwner, $this->stages($otherCompany)['Won'], 'website');
        Deal::create(['company_id' => $company->id, 'lead_id' => $newLead->id, 'created_by' => $owner->id, 'status' => 'negotiation', 'agreed_price_minor_units' => 52000000]);
        $ownerMembership = $company->memberships()->where('user_id', $owner->id)->firstOrFail();
        Deal::create(['company_id' => $company->id, 'lead_id' => $wonLead->id, 'created_by' => $owner->id, 'status' => 'closed_won', 'closed_at' => now(), 'salesperson_membership_id' => $ownerMembership->id, 'salesperson_name' => $owner->name, 'agreed_price_minor_units' => 80000000]);
        Deal::create(['company_id' => $otherCompany->id, 'lead_id' => $foreignLead->id, 'created_by' => $otherOwner->id, 'status' => 'closed_won', 'closed_at' => now(), 'agreed_price_minor_units' => 99000000]);

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)->getJson('/api/v1/reports?period=year')
            ->assertOk()->assertJsonPath('period.key', 'year')->assertJsonPath('metrics.leads', 3)
            ->assertJsonPath('metrics.won_leads', 1)->assertJsonPath('metrics.lost_leads', 1)
            ->assertJsonPath('metrics.lead_win_rate', 33.3)->assertJsonPath('metrics.deals', 2)
            ->assertJsonPath('metrics.active_deal_value_minor_units', 52000000)
            ->assertJsonPath('metrics.won_deal_value_minor_units', 80000000)
            ->assertJsonCount(2, 'sources')->assertJsonCount(3, 'funnel')
            ->assertJsonPath('sales_performance.0.salesperson_name', $owner->name)
            ->assertJsonPath('sales_performance.0.units_sold', 1)
            ->assertJsonPath('sales_performance.0.agreed_value_minor_units', 80000000);
    }

    public function test_agent_reports_include_only_leads_and_deals_visible_to_that_agent(): void
    {
        [$company, $agent] = $this->workspace('agent');
        [$otherAgent] = $this->workspaceMember($company, 'agent');
        $stage = $this->stages($company)['New'];
        $ownLead = $this->lead($company, $agent, $stage, 'manual');
        $otherLead = $this->lead($company, $otherAgent, $stage, 'referral');
        Deal::create(['company_id' => $company->id, 'lead_id' => $ownLead->id, 'created_by' => $agent->id, 'status' => 'negotiation', 'agreed_price_minor_units' => 25000000]);
        $otherMembership = $company->memberships()->where('user_id', $otherAgent->id)->firstOrFail();
        Deal::create(['company_id' => $company->id, 'lead_id' => $otherLead->id, 'created_by' => $otherAgent->id, 'status' => 'closed_won', 'closed_at' => now(), 'salesperson_membership_id' => $otherMembership->id, 'salesperson_name' => $otherAgent->name, 'agreed_price_minor_units' => 45000000]);

        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)->getJson('/api/v1/reports')
            ->assertOk()->assertJsonPath('metrics.leads', 1)->assertJsonPath('metrics.deals', 1)
            ->assertJsonPath('sources.0.source', 'manual')->assertJsonPath('metrics.won_deal_value_minor_units', 0)
            ->assertJsonCount(0, 'sales_performance');
    }

    public function test_sales_leaderboard_counts_won_deals_by_close_date_and_employee(): void
    {
        [$company, $first] = $this->workspace('owner');
        [$second] = $this->workspaceMember($company, 'agent');
        $stage = $this->stages($company)['New'];
        $firstMembership = $company->memberships()->where('user_id', $first->id)->firstOrFail();
        $secondMembership = $company->memberships()->where('user_id', $second->id)->firstOrFail();
        foreach ([[$first, $firstMembership, 100000], [$first, $firstMembership, null], [$second, $secondMembership, 900000]] as [$user, $membership, $amount]) {
            $lead = $this->lead($company, $user, $stage, 'manual');
            Deal::create(['company_id' => $company->id, 'lead_id' => $lead->id, 'created_by' => $user->id, 'status' => 'closed_won', 'closed_at' => now(), 'salesperson_membership_id' => $membership->id, 'salesperson_name' => $user->name, 'agreed_price_minor_units' => $amount]);
        }
        $this->actingAs($first)->withHeader('X-Company-ID', $company->id)->getJson('/api/v1/reports?period=year')
            ->assertOk()->assertJsonPath('sales_performance.0.salesperson_name', $first->name)
            ->assertJsonPath('sales_performance.0.units_sold', 2)
            ->assertJsonPath('sales_performance.0.priced_deals', 1)
            ->assertJsonPath('sales_performance.0.agreed_value_minor_units', 100000)
            ->assertJsonPath('sales_performance.1.salesperson_name', $second->name)
            ->assertJsonPath('sales_performance.1.units_sold', 1);
    }

    public function test_report_permission_is_required_and_period_is_validated(): void
    {
        [$company, $operations] = $this->workspace('operations');
        $this->actingAs($operations)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/reports')->assertForbidden();

        [$ownerCompany, $owner] = $this->workspace('owner');
        $this->actingAs($owner)->withHeader('X-Company-ID', $ownerCompany->id)
            ->getJson('/api/v1/reports?period=forever')->assertUnprocessable()->assertJsonValidationErrors('period');
    }

    public function test_report_comparison_and_csv_export_are_scoped_to_the_active_company(): void
    {
        [$company, $owner] = $this->workspace('owner');
        [$otherCompany, $otherOwner] = $this->workspace('owner', 'Other Reports Workspace');
        $stage = $this->stages($company)['New'];
        $this->lead($company, $owner, $stage, 'website');
        $previous = $this->lead($company, $owner, $stage, 'referral');
        $previous->forceFill(['created_at' => now()->subMonth()->startOfMonth()->addDays(2)])->saveQuietly();
        $foreign = $this->lead($otherCompany, $otherOwner, $this->stages($otherCompany)['New'], 'foreign-source');

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/reports?period=month')->assertOk()
            ->assertJsonPath('metrics.leads', 1)
            ->assertJsonPath('comparison.metrics.leads', 1);

        $csv = $this->get('/api/v1/reports?period=month&format=csv')->assertOk();
        $csv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Previous period metric', $csv->streamedContent());
        $this->assertStringNotContainsString('foreign-source', $csv->streamedContent());
        $this->assertNotNull($foreign->id);
    }

    private function workspace(string $role, string $name = 'Reports Realty'): array
    {
        $company = Company::create(['name' => $name, 'slug' => 'reports-'.uniqid()]);
        [$user] = $this->workspaceMember($company, $role);
        return [$company, $user];
    }

    private function workspaceMember(Company $company, string $role): array
    {
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $user->id, 'role' => $role, 'status' => 'active']);
        return [$user];
    }

    private function stages(Company $company): array
    {
        return [
            'New' => PipelineStage::create(['company_id' => $company->id, 'name' => 'New', 'position' => 0]),
            'Won' => PipelineStage::create(['company_id' => $company->id, 'name' => 'Won', 'position' => 1, 'is_won' => true]),
            'Lost' => PipelineStage::create(['company_id' => $company->id, 'name' => 'Lost', 'position' => 2, 'is_lost' => true]),
        ];
    }

    private function lead(Company $company, User $user, PipelineStage $stage, string $source): Lead
    {
        return Lead::create([
            'company_id' => $company->id, 'pipeline_stage_id' => $stage->id,
            'assigned_to' => $user->id, 'created_by' => $user->id, 'name' => 'Report Client',
            'phone_original' => '01000000000', 'phone_normalized' => '+201000000000', 'source' => $source, 'intent' => 'buy',
        ]);
    }
}
