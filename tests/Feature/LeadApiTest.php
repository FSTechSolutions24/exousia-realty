<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\PropertyListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LeadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_create_a_lead_and_phone_is_normalized(): void
    {
        [$company, $user, $stage] = $this->workspace();
        $this->actingAs($user)->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/leads', [
            'name' => 'Demo Client', 'phone' => '010 1234 5678', 'pipeline_stage_id' => $stage->id,
            'intent' => 'buy', 'budget_max' => 5000000, 'preferred_locations' => ['New Cairo'], 'source' => 'facebook',
        ])->assertCreated()->assertJsonPath('lead.phone_normalized', '+201012345678')->assertJsonPath('lead.source', 'facebook');

        $this->assertDatabaseHas('leads', ['company_id' => $company->id, 'phone_normalized' => '+201012345678']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'event' => 'lead.created']);
    }

    public function test_it_returns_a_company_scoped_duplicate_warning(): void
    {
        [$company, $user, $stage] = $this->workspace();
        $payload = ['name' => 'First Client', 'phone' => '01112345678', 'pipeline_stage_id' => $stage->id];
        $this->actingAs($user)->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/leads', $payload)->assertCreated();
        $payload['name'] = 'Second Enquiry';
        $this->postJson('/api/v1/leads', $payload)->assertCreated()->assertJsonPath('duplicate_warning.name', 'First Client');
    }

    public function test_it_uses_the_first_pipeline_stage_when_the_request_sends_null(): void
    {
        [$company, $user, $stage] = $this->workspace();

        $this->actingAs($user)->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/leads', [
            'name' => 'Lead Without Stage',
            'phone' => '01212345678',
            'pipeline_stage_id' => null,
        ])->assertCreated()->assertJsonPath('lead.pipeline_stage_id', $stage->id);

        $this->assertDatabaseHas('leads', [
            'company_id' => $company->id,
            'name' => 'Lead Without Stage',
            'pipeline_stage_id' => $stage->id,
        ]);
    }

    public function test_an_owner_can_open_an_unassigned_lead_detail(): void
    {
        [$company, $user, $stage] = $this->workspace();
        $response = $this->actingAs($user)->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/leads', [
            'name' => 'Unassigned Lead',
            'phone' => '01512345678',
            'pipeline_stage_id' => $stage->id,
        ])->assertCreated();

        $this->getJson('/api/v1/leads/'.$response->json('lead.id'))
            ->assertOk()
            ->assertJsonPath('name', 'Unassigned Lead')
            ->assertJsonPath('stage.id', $stage->id);
    }

    public function test_lead_filters_are_applied_together(): void
    {
        [$company, $user, $stage] = $this->workspace();
        $this->actingAs($user)->withHeader('X-Company-ID', $company->id);
        $this->postJson('/api/v1/leads', ['name' => 'Matching Lead', 'phone' => '01012345678', 'pipeline_stage_id' => $stage->id, 'source' => 'referral', 'intent' => 'buy'])->assertCreated();
        $this->postJson('/api/v1/leads', ['name' => 'Other Lead', 'phone' => '01112345678', 'pipeline_stage_id' => $stage->id, 'source' => 'website', 'intent' => 'rent'])->assertCreated();

        $this->getJson('/api/v1/leads?source=referral&intent=buy&assigned_to=unassigned')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Matching Lead');
    }

    public function test_property_matches_apply_hard_filters_score_locations_and_stay_in_the_tenant(): void
    {
        [$company, $owner, $stage] = $this->workspace();
        $otherCompany = Company::create(['name' => 'Other Realty', 'slug' => 'other-realty']);
        $lead = Lead::create([
            'company_id' => $company->id, 'pipeline_stage_id' => $stage->id, 'created_by' => $owner->id,
            'name' => 'Apartment buyer', 'phone_original' => '01012345678', 'phone_normalized' => '+201012345678',
            'intent' => 'buy', 'property_type' => 'apartment', 'bedrooms' => 2,
            'budget_min' => 4000000, 'budget_max' => 5000000, 'preferred_locations' => ['New Cairo'],
        ]);
        $makeListing = fn (Company $tenant, array $values = []) => PropertyListing::create(array_merge([
            'company_id' => $tenant->id, 'reference_code' => 'MATCH-'.uniqid(), 'title' => 'Match property',
            'listing_type' => 'sale', 'property_type' => 'apartment', 'status' => 'available', 'location' => 'New Cairo',
            'price_minor_units' => 480000000, 'bedrooms' => 3, 'currency' => 'EGP', 'listed_by' => $owner->id,
        ], $values));
        $best = $makeListing($company);
        $lessRelevant = $makeListing($company, ['location' => 'Maadi']);
        $makeListing($company, ['price_minor_units' => 550000000]);
        $makeListing($company, ['property_type' => 'villa']);
        $makeListing($company, ['listing_type' => 'rent']);
        $makeListing($otherCompany);

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/leads/'.$lead->id.'/matches')
            ->assertOk()->assertJsonCount(2, 'matches')
            ->assertJsonPath('matches.0.listing.id', $best->id)
            ->assertJsonPath('matches.0.score', 100)
            ->assertJsonPath('matches.1.listing.id', $lessRelevant->id)
            ->assertJsonPath('matches.1.score', 80);
    }

    public function test_property_matches_require_inventory_permission(): void
    {
        [$company, $owner, $stage] = $this->workspace();
        $lead = Lead::create([
            'company_id' => $company->id, 'pipeline_stage_id' => $stage->id, 'created_by' => $owner->id,
            'name' => 'Visible lead', 'phone_original' => '01012345678', 'phone_normalized' => '+201012345678',
        ]);
        $viewer = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $viewer->id, 'role' => 'lead_viewer', 'status' => 'active', 'permissions' => ['view_all_leads']]);

        $this->actingAs($viewer)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/leads/'.$lead->id.'/matches')->assertForbidden();
    }

    public function test_csv_import_previews_validates_and_is_idempotent_without_dropping_duplicate_contacts(): void
    {
        [$company, $owner] = $this->workspace();
        $csv = UploadedFile::fake()->createWithContent('leads.csv', implode("\n", [
            'name,phone,email,source,intent,budget_min,budget_max,preferred_locations,property_type,bedrooms,notes',
            'Nour Client,01012345678,nour@example.test,facebook,buy,1000000,3000000,,apartment,2,First enquiry',
            'Nour Follow-up,01012345678,,referral,buy,,,,,,Second valid enquiry',
        ]));
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id);

        $this->postJson('/api/v1/leads/import/preview', ['file' => $csv])->assertOk()
            ->assertJsonPath('total_rows', 2)->assertJsonPath('valid_rows', 2)
            ->assertJsonPath('rows.1.duplicate_warning.existing', false);

        $this->postJson('/api/v1/leads/import', ['file' => $csv])->assertCreated()
            ->assertJsonPath('created_count', 2)->assertJsonPath('already_imported', false);
        $this->postJson('/api/v1/leads/import', ['file' => $csv])->assertOk()
            ->assertJsonPath('created_count', 2)->assertJsonPath('already_imported', true);
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseHas('leads', ['company_id' => $company->id, 'name' => 'Nour Client', 'source' => 'facebook', 'phone_normalized' => '+201012345678']);
        $this->assertDatabaseHas('leads', ['company_id' => $company->id, 'name' => 'Nour Follow-up', 'source' => 'referral']);
        $this->assertDatabaseHas('lead_imports', ['company_id' => $company->id, 'created_count' => 2]);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_csv_import_rejects_invalid_rows_as_a_batch_and_scopes_idempotency_to_tenant(): void
    {
        [$company, $owner] = $this->workspace();
        $invalid = UploadedFile::fake()->createWithContent('invalid.csv', "name,phone\nGood Row,01012345678\nBad Row,123\n");
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/leads/import', ['file' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('row_3');
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('lead_imports', 0);

        $csv = UploadedFile::fake()->createWithContent('same.csv', "name,phone\nTenant Lead,01055551234\n");
        $this->postJson('/api/v1/leads/import', ['file' => $csv])->assertCreated();
        $otherCompany = Company::create(['name' => 'Other Import Realty', 'slug' => 'other-import-'.uniqid()]);
        $otherOwner = User::factory()->create(['current_company_id' => $otherCompany->id]);
        $otherCompany->memberships()->create(['user_id' => $otherOwner->id, 'role' => 'owner', 'status' => 'active']);
        PipelineStage::create(['company_id' => $otherCompany->id, 'name' => 'New lead', 'position' => 0]);
        $this->actingAs($otherOwner)->withHeader('X-Company-ID', $otherCompany->id)
            ->postJson('/api/v1/leads/import', ['file' => $csv])->assertCreated()
            ->assertJsonPath('already_imported', false);
        $this->assertDatabaseHas('lead_imports', ['company_id' => $company->id]);
        $this->assertDatabaseHas('lead_imports', ['company_id' => $otherCompany->id]);
    }

    public function test_lead_import_template_and_import_require_create_permission(): void
    {
        [$company, $owner] = $this->workspace();
        $viewer = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $viewer->id, 'role' => 'lead_viewer', 'status' => 'active', 'permissions' => ['view_all_leads']]);
        $this->actingAs($viewer)->withHeader('X-Company-ID', $company->id)
            ->get('/api/v1/leads/import-template')->assertForbidden();
        $this->postJson('/api/v1/leads/import/preview')->assertForbidden();
        $this->postJson('/api/v1/leads/import')->assertForbidden();
        $template = $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->get('/api/v1/leads/import-template')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('name,phone,email,source,intent', $template->streamedContent());
    }

    public function test_an_owner_can_assign_a_lead_and_the_change_is_recorded(): void
    {
        [$company, $owner, $stage] = $this->workspace();
        $agent = User::factory()->create();
        $company->memberships()->create(['user_id' => $agent->id, 'role' => 'agent', 'status' => 'active']);
        $lead = Lead::create(['company_id' => $company->id, 'pipeline_stage_id' => $stage->id, 'created_by' => $owner->id, 'name' => 'Assign me', 'phone_original' => '01012345678', 'phone_normalized' => '+201012345678']);

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->putJson('/api/v1/leads/'.$lead->id, ['assigned_to' => $agent->id])
            ->assertOk()->assertJsonPath('assigned_to', $agent->id)->assertJsonPath('assignee.id', $agent->id);

        $this->assertDatabaseHas('lead_activities', ['company_id' => $company->id, 'lead_id' => $lead->id, 'type' => 'assignment_changed']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'event' => 'lead.updated', 'auditable_id' => $lead->id]);
    }

    public function test_an_agent_cannot_reassign_a_lead_they_can_update(): void
    {
        [$company, $owner, $stage] = $this->workspace();
        $agent = User::factory()->create(['current_company_id' => $company->id]);
        $otherAgent = User::factory()->create();
        $company->memberships()->create(['user_id' => $agent->id, 'role' => 'agent', 'status' => 'active']);
        $company->memberships()->create(['user_id' => $otherAgent->id, 'role' => 'agent', 'status' => 'active']);
        $lead = Lead::create(['company_id' => $company->id, 'pipeline_stage_id' => $stage->id, 'created_by' => $owner->id, 'assigned_to' => $agent->id, 'name' => 'My lead', 'phone_original' => '01012345678', 'phone_normalized' => '+201012345678']);

        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->putJson('/api/v1/leads/'.$lead->id, ['assigned_to' => $otherAgent->id])->assertForbidden();
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'assigned_to' => $agent->id]);
    }

    public function test_an_owner_can_archive_and_restore_a_lead_with_history(): void
    {
        [$company, $user, $stage] = $this->workspace();
        $lead = Lead::create(['company_id' => $company->id, 'pipeline_stage_id' => $stage->id, 'created_by' => $user->id, 'name' => 'Archive me', 'phone_original' => '01012345678', 'phone_normalized' => '+201012345678']);
        $this->actingAs($user)->withHeader('X-Company-ID', $company->id);

        $this->deleteJson('/api/v1/leads/'.$lead->id)->assertOk();
        $this->assertSoftDeleted('leads', ['id' => $lead->id, 'company_id' => $company->id]);
        $this->getJson('/api/v1/leads?archived=1')->assertOk()->assertJsonPath('data.0.id', $lead->id);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'archived', 'company_id' => $company->id]);

        $this->postJson('/api/v1/leads/'.$lead->id.'/restore')->assertOk()->assertJsonPath('id', $lead->id);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'restored', 'company_id' => $company->id]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'event' => 'lead.archived', 'auditable_id' => $lead->id]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'event' => 'lead.restored', 'auditable_id' => $lead->id]);
    }

    public function test_an_agent_cannot_archive_a_lead_even_when_it_is_visible_to_them(): void
    {
        [$company, $owner, $stage] = $this->workspace();
        $agent = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $agent->id, 'role' => 'agent', 'status' => 'active']);
        $lead = Lead::create(['company_id' => $company->id, 'pipeline_stage_id' => $stage->id, 'created_by' => $owner->id, 'assigned_to' => $agent->id, 'name' => 'Assigned lead', 'phone_original' => '01012345678', 'phone_normalized' => '+201012345678']);

        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)->deleteJson('/api/v1/leads/'.$lead->id)->assertForbidden();
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'deleted_at' => null]);
    }

    public function test_archived_lead_from_another_company_cannot_be_restored(): void
    {
        [$company, $user, $stage] = $this->workspace();
        $otherCompany = Company::create(['name' => 'Other Realty', 'slug' => 'other-realty']);
        $otherStage = PipelineStage::create(['company_id' => $otherCompany->id, 'name' => 'New lead', 'position' => 0]);
        $lead = Lead::create(['company_id' => $otherCompany->id, 'pipeline_stage_id' => $otherStage->id, 'created_by' => $user->id, 'name' => 'Other tenant lead', 'phone_original' => '01012345678', 'phone_normalized' => '+201012345678']);
        $lead->delete();

        $this->actingAs($user)->withHeader('X-Company-ID', $company->id)->postJson('/api/v1/leads/'.$lead->id.'/restore')->assertNotFound();
        $this->assertSoftDeleted('leads', ['id' => $lead->id, 'company_id' => $otherCompany->id]);
    }

    public function test_owner_can_bulk_assign_leads_and_records_each_change(): void
    {
        [$company, $owner, $stage] = $this->workspace();
        $agent = User::factory()->create();
        $company->memberships()->create(['user_id' => $agent->id, 'role' => 'agent', 'status' => 'active']);
        $leads = collect(['First batch lead', 'Second batch lead'])->map(fn ($name) => Lead::create([
            'company_id' => $company->id, 'pipeline_stage_id' => $stage->id, 'created_by' => $owner->id,
            'name' => $name, 'phone_original' => '01012345678', 'phone_normalized' => '+201012345678',
        ]));

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/leads/bulk', ['action' => 'assign', 'lead_ids' => $leads->pluck('id')->all(), 'assigned_to' => $agent->id])
            ->assertOk()->assertJsonPath('updated_count', 2);

        foreach ($leads as $lead) {
            $this->assertDatabaseHas('leads', ['id' => $lead->id, 'assigned_to' => $agent->id]);
            $this->assertDatabaseHas('lead_activities', ['company_id' => $company->id, 'lead_id' => $lead->id, 'type' => 'assignment_changed']);
        }
    }

    public function test_bulk_archive_is_reversible_and_rejects_foreign_or_unauthorized_leads(): void
    {
        [$company, $owner, $stage] = $this->workspace();
        $otherCompany = Company::create(['name' => 'Another Realty', 'slug' => 'another-realty']);
        $otherStage = PipelineStage::create(['company_id' => $otherCompany->id, 'name' => 'New lead', 'position' => 0]);
        $ownLead = Lead::create(['company_id' => $company->id, 'pipeline_stage_id' => $stage->id, 'created_by' => $owner->id, 'name' => 'Own batch lead', 'phone_original' => '01012345678', 'phone_normalized' => '+201012345678']);
        $foreignLead = Lead::create(['company_id' => $otherCompany->id, 'pipeline_stage_id' => $otherStage->id, 'created_by' => $owner->id, 'name' => 'Foreign batch lead', 'phone_original' => '01012345679', 'phone_normalized' => '+201012345679']);

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/leads/bulk', ['action' => 'archive', 'lead_ids' => [$ownLead->id, $foreignLead->id]])
            ->assertUnprocessable();
        $this->assertDatabaseHas('leads', ['id' => $ownLead->id, 'deleted_at' => null]);

        $agent = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $agent->id, 'role' => 'agent', 'status' => 'active']);
        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/leads/bulk', ['action' => 'archive', 'lead_ids' => [$ownLead->id]])->assertForbidden();

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/leads/bulk', ['action' => 'archive', 'lead_ids' => [$ownLead->id]])
            ->assertOk()->assertJsonPath('updated_count', 1);
        $this->assertSoftDeleted('leads', ['id' => $ownLead->id, 'company_id' => $company->id]);
        $this->assertDatabaseHas('lead_activities', ['company_id' => $company->id, 'lead_id' => $ownLead->id, 'type' => 'archived']);
        $this->postJson('/api/v1/leads/'.$ownLead->id.'/restore')->assertOk();
        $this->assertDatabaseHas('leads', ['id' => $ownLead->id, 'deleted_at' => null]);
    }

    private function workspace(): array
    {
        $company = Company::create(['name' => 'Test Realty', 'slug' => 'test-realty']);
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $stage = PipelineStage::create(['company_id' => $company->id, 'name' => 'New lead', 'position' => 0]);
        return [$company, $user, $stage];
    }
}
