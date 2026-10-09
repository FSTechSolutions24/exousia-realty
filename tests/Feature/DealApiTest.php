<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealDocument;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\PropertyListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DealApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_update_and_review_a_deal_history(): void
    {
        [$company, $owner, $lead] = $this->workspace('owner');
        $listing = $this->listing($company, $owner);

        $created = $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/deals', [
                'lead_id' => $lead->id, 'property_listing_id' => $listing->id, 'status' => 'negotiation',
                'expected_close_date' => '2026-12-15', 'agreed_price_egp' => '7250000.25', 'notes' => 'Offer submitted.',
            ])->assertCreated()->assertJsonPath('agreed_price_minor_units', 725000025)
            ->assertJsonPath('agreed_price_egp', '7250000.25')->assertJsonPath('lead.id', $lead->id)
            ->assertJsonPath('property.id', $listing->id);

        $dealId = $created->json('id');
        $this->putJson('/api/v1/deals/'.$dealId, ['status' => 'reserved', 'agreed_price_egp' => '7100000'])
            ->assertOk()->assertJsonPath('status', 'reserved')->assertJsonPath('agreed_price_minor_units', 710000000);
        $closed = $this->putJson('/api/v1/deals/'.$dealId, ['status' => 'closed_won'])->assertOk()
            ->assertJsonPath('salesperson_name', $owner->name);
        $this->assertNotNull($closed->json('closed_at'));
        $this->assertDatabaseHas('deals', [
            'id' => $dealId,
            'salesperson_membership_id' => $company->memberships()->where('user_id', $owner->id)->value('id'),
            'salesperson_name' => $owner->name,
        ]);
        $this->getJson('/api/v1/deals/'.$dealId)->assertOk()->assertJsonCount(3, 'activities');
        $this->assertDatabaseHas('deal_activities', ['company_id' => $company->id, 'deal_id' => $dealId, 'event' => 'created']);
        $this->assertDatabaseHas('deal_activities', ['company_id' => $company->id, 'deal_id' => $dealId, 'event' => 'status_changed']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'event' => 'deal.created', 'auditable_id' => $dealId]);
    }

    public function test_agent_sees_and_updates_deals_only_for_their_own_leads(): void
    {
        [$company, $agent, $lead] = $this->workspace('agent');
        $otherAgent = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $otherAgent->id, 'role' => 'agent', 'status' => 'active']);
        $otherLead = $this->lead($company, $otherAgent);
        $ownDeal = Deal::create(['company_id' => $company->id, 'lead_id' => $lead->id, 'created_by' => $agent->id, 'status' => 'negotiation']);
        $otherDeal = Deal::create(['company_id' => $company->id, 'lead_id' => $otherLead->id, 'created_by' => $otherAgent->id, 'status' => 'negotiation']);

        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/deals')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownDeal->id);
        $this->getJson('/api/v1/deals/'.$otherDeal->id)->assertNotFound();
        $this->putJson('/api/v1/deals/'.$otherDeal->id, ['status' => 'closed_won'])->assertNotFound();
        $this->postJson('/api/v1/deals', ['lead_id' => $otherLead->id, 'status' => 'negotiation'])->assertNotFound();
    }

    public function test_deal_cannot_reference_a_lead_or_listing_from_another_company(): void
    {
        [$company, $owner] = $this->workspace('owner');
        [$otherCompany, $otherOwner, $foreignLead] = $this->workspace('owner', 'Elsewhere Realty');
        $foreignListing = $this->listing($otherCompany, $otherOwner);

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/deals', ['lead_id' => $foreignLead->id, 'status' => 'negotiation'])
            ->assertUnprocessable()->assertJsonValidationErrors('lead_id');
        $localLead = $this->lead($company, $owner);
        $this->postJson('/api/v1/deals', ['lead_id' => $localLead->id, 'property_listing_id' => $foreignListing->id, 'status' => 'negotiation'])
            ->assertUnprocessable()->assertJsonValidationErrors('property_listing_id');
    }

    public function test_finance_can_read_deals_but_cannot_change_them(): void
    {
        [$company, $owner] = $this->workspace('owner');
        [$finance] = $this->workspaceMember($company, 'finance');
        $lead = $this->lead($company, $owner);
        $deal = Deal::create(['company_id' => $company->id, 'lead_id' => $lead->id, 'created_by' => $owner->id, 'status' => 'negotiation']);

        $this->actingAs($finance)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/deals')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson('/api/v1/deals/'.$deal->id, ['status' => 'closed_won'])->assertForbidden();
    }

    public function test_commissions_and_deal_documents_are_tenant_scoped_and_permission_controlled(): void
    {
        Storage::fake('private');
        [$company, $owner, $lead] = $this->workspace('owner');
        [$finance] = $this->workspaceMember($company, 'finance');
        [$agent] = $this->workspaceMember($company, 'agent');
        $listing = $this->listing($company, $owner);
        $deal = Deal::create(['company_id' => $company->id, 'lead_id' => $lead->id, 'property_listing_id' => $listing->id, 'created_by' => $owner->id, 'status' => 'contracted', 'agreed_price_minor_units' => 100000000]);
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id);

        $commission = $this->postJson('/api/v1/deals/'.$deal->id.'/commissions', [
            'payee_user_id' => $finance->id, 'amount_egp' => '12500.50', 'due_on' => '2026-12-01', 'reference' => 'COMM-1',
        ])->assertCreated()->assertJsonPath('amount_minor_units', 1250050)->assertJsonPath('status', 'pending');
        $commissionId = $commission->json('id');
        $this->patchJson('/api/v1/deals/'.$deal->id.'/commissions/'.$commissionId, ['status' => 'paid'])
            ->assertOk()->assertJsonPath('status', 'paid');
        $this->patchJson('/api/v1/deals/'.$deal->id.'/commissions/'.$commissionId, ['status' => 'void'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('deal_commissions', ['id' => $commissionId, 'company_id' => $company->id, 'status' => 'paid']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'event' => 'deal.commission_status_changed', 'auditable_id' => $commissionId]);

        $percentage = $this->postJson('/api/v1/deals/'.$deal->id.'/commissions', [
            'payee_user_id' => $agent->id, 'calculation_type' => 'percentage', 'rate_percent' => '2.50',
        ])->assertCreated()->assertJsonPath('amount_minor_units', 2500000)
            ->assertJsonPath('calculation_type', 'percentage')->assertJsonPath('rate_percent', '2.50')
            ->assertJsonPath('base_amount_minor_units', 100000000)
            ->assertJsonPath('property_listing_id', $listing->id);
        $this->assertStringContainsString($listing->reference_code, $percentage->json('unit_reference'));

        $document = $this->postJson('/api/v1/deals/'.$deal->id.'/documents', [
            'document' => UploadedFile::fake()->create('sale-contract.pdf', 45, 'application/pdf'), 'category' => 'contract',
        ])->assertCreated()->assertJsonPath('category', 'contract');
        $documentId = $document->json('id');
        $record = DealDocument::findOrFail($documentId);
        $this->assertStringStartsWith('companies/'.$company->id.'/deals/'.$deal->id.'/documents/', $record->storage_path);
        Storage::disk('private')->assertExists($record->storage_path);
        $this->get($document->json('url'))->assertOk()->assertHeader('content-disposition');

        $this->actingAs($finance)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/deals/'.$deal->id.'/commissions')->assertOk()->assertJsonCount(2);
        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/deals/'.$deal->id.'/commissions')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.payee_name', $agent->name);
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/reports?period=year')->assertOk()
            ->assertJsonPath('commission_totals.entry_count', 2)
            ->assertJsonPath('commission_totals.pending_minor_units', 2500000)
            ->assertJsonPath('commission_entries.0.property_listing_id', $listing->id);
        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/reports?period=year')->assertOk()
            ->assertJsonPath('commission_totals.entry_count', 1)
            ->assertJsonPath('commission_totals.pending_minor_units', 2500000)
            ->assertJsonPath('commissions_by_employee.0.payee_name', $agent->name)
            ->assertJsonCount(1, 'commission_entries')
            ->assertJsonPath('commission_entries.0.unit_reference', $percentage->json('unit_reference'));
        $this->postJson('/api/v1/deals/'.$deal->id.'/commissions', [
            'payee_user_id' => $agent->id, 'amount_egp' => '100',
        ])->assertForbidden();

        [$otherCompany, $otherOwner, $otherLead] = $this->workspace('owner', 'Foreign Deal Realty');
        $otherDeal = Deal::create(['company_id' => $otherCompany->id, 'lead_id' => $otherLead->id, 'created_by' => $otherOwner->id, 'status' => 'contracted']);
        $foreignDocument = DealDocument::create([
            'company_id' => $otherCompany->id, 'deal_id' => $otherDeal->id, 'uploaded_by' => $otherOwner->id,
            'category' => 'contract', 'original_name' => 'private.pdf', 'storage_path' => 'companies/'.$otherCompany->id.'/deals/'.$otherDeal->id.'/documents/private.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 10,
        ]);
        Storage::disk('private')->put($foreignDocument->storage_path, 'private document');
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->get('/api/v1/deals/'.$otherDeal->id.'/documents/'.$foreignDocument->id)->assertNotFound();
    }

    private function workspace(string $role, string $name = 'Deal Test Realty'): array
    {
        $company = Company::create(['name' => $name, 'slug' => 'deal-'.uniqid()]);
        [$user] = $this->workspaceMember($company, $role);
        return [$company, $user, $this->lead($company, $user)];
    }

    private function workspaceMember(Company $company, string $role): array
    {
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $user->id, 'role' => $role, 'status' => 'active']);
        return [$user];
    }

    private function lead(Company $company, User $user): Lead
    {
        $stage = PipelineStage::firstOrCreate(['company_id' => $company->id, 'name' => 'New lead'], ['position' => 0]);
        return Lead::create([
            'company_id' => $company->id, 'pipeline_stage_id' => $stage->id,
            'assigned_to' => $user->id, 'created_by' => $user->id, 'name' => 'Beshoy Client',
            'phone_original' => '01000000000', 'phone_normalized' => '+201000000000', 'intent' => 'buy',
        ]);
    }

    private function listing(Company $company, User $user): PropertyListing
    {
        return PropertyListing::create([
            'company_id' => $company->id, 'reference_code' => 'DEAL-'.uniqid(), 'title' => 'Demo property',
            'listing_type' => 'sale', 'property_type' => 'apartment', 'status' => 'available', 'location' => 'Cairo',
            'price_minor_units' => 700000000, 'currency' => 'EGP', 'listed_by' => $user->id,
        ]);
    }
}
