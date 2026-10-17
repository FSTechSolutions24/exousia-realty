<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\PropertyListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyViewingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_schedule_a_viewing_and_record_buyer_feedback(): void
    {
        [$company, $owner, $lead, $listing] = $this->workspace('alpha');
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id);

        $scheduledAt = now()->addDays(2)->startOfHour();
        $created = $this->postJson("/api/v1/leads/{$lead->id}/viewings", [
            'property_listing_id' => $listing->id,
            'scheduled_at' => $scheduledAt->toDateTimeString(),
        ])->assertCreated()
            ->assertJsonPath('status', 'scheduled')
            ->assertJsonPath('listing_title', $listing->title)
            ->assertJsonPath('listing_price_minor_units', $listing->price_minor_units);

        $viewingId = $created->json('id');
        $this->patchJson("/api/v1/leads/{$lead->id}/viewings/{$viewingId}", [
            'status' => 'completed', 'buyer_rating' => 4, 'buyer_feedback' => 'Loved the light, wants a larger balcony.',
        ])->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('buyer_rating', 4);

        $this->assertDatabaseHas('lead_activities', [
            'company_id' => $company->id, 'lead_id' => $lead->id, 'title' => 'Property viewing scheduled',
        ]);
        $this->assertDatabaseHas('lead_activities', [
            'company_id' => $company->id, 'lead_id' => $lead->id, 'title' => 'Viewing completed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id, 'event' => 'property_viewing.outcome_recorded', 'auditable_id' => $viewingId,
        ]);
        $listing->update(['title' => 'Renamed property', 'price_minor_units' => 51000000]);
        $this->getJson("/api/v1/leads/{$lead->id}")->assertOk()
            ->assertJsonPath('viewings.0.listing_title', 'Cairo Garden Apartment')
            ->assertJsonPath('viewings.0.listing_price_minor_units', 50000000);
    }

    public function test_viewing_rejects_foreign_listings_and_foreign_leads(): void
    {
        [$company, $owner, $lead] = $this->workspace('alpha');
        [$foreignCompany, , $foreignLead, $foreignListing] = $this->workspace('beta');
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id);

        $this->postJson("/api/v1/leads/{$lead->id}/viewings", [
            'property_listing_id' => $foreignListing->id, 'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('property_listing_id');
        $this->postJson("/api/v1/leads/{$foreignLead->id}/viewings", [
            'property_listing_id' => $foreignListing->id, 'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertNotFound();
        $this->assertDatabaseMissing('property_viewings', ['company_id' => $foreignCompany->id]);
    }

    public function test_members_without_lead_update_permission_cannot_schedule_a_viewing(): void
    {
        [$company, , $lead, $listing] = $this->workspace('alpha');
        $finance = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $finance->id, 'role' => 'finance', 'status' => 'active']);

        $this->actingAs($finance)->withHeader('X-Company-ID', $company->id)
            ->postJson("/api/v1/leads/{$lead->id}/viewings", [
                'property_listing_id' => $listing->id, 'scheduled_at' => now()->addDay()->toDateTimeString(),
            ])->assertForbidden();
        $this->assertDatabaseMissing('property_viewings', ['lead_id' => $lead->id]);
    }

    private function workspace(string $suffix): array
    {
        $company = Company::create(['name' => ucfirst($suffix).' Realty', 'slug' => $suffix.'-realty']);
        $this->enableCompanyTrial($company);
        $owner = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        $stage = PipelineStage::create(['company_id' => $company->id, 'name' => 'New enquiry', 'position' => 0]);
        $lead = Lead::create([
            'company_id' => $company->id, 'pipeline_stage_id' => $stage->id, 'created_by' => $owner->id,
            'assigned_to' => $owner->id, 'name' => 'Mariam Buyer', 'phone_original' => '01012345678',
            'phone_normalized' => '+201012345678', 'intent' => 'buy',
        ]);
        $listing = PropertyListing::create([
            'company_id' => $company->id, 'reference_code' => strtoupper($suffix).'-001',
            'title' => 'Cairo Garden Apartment', 'listing_type' => 'sale', 'property_type' => 'apartment',
            'status' => 'available', 'location' => 'New Cairo', 'price_minor_units' => 50000000,
            'currency' => 'EGP', 'listed_by' => $owner->id,
        ]);

        return [$company, $owner, $lead, $listing];
    }
}
