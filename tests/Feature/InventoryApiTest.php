<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PropertyListing;
use App\Models\PropertyPhoto;
use App\Models\PreferredLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_update_and_audit_a_property_listing(): void
    {
        [$company, $owner] = $this->workspace('owner');
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id);
        $location = PreferredLocation::where('company_id', $company->id)->where('name', 'New Cairo')->firstOrFail();

        $created = $this->postJson('/api/v1/inventory', [
            'title' => 'Apartment in New Cairo', 'listing_type' => 'sale', 'property_type' => 'apartment',
            'preferred_location_id' => $location->id, 'price_egp' => '7250000.25', 'bedrooms' => 3, 'bathrooms' => 2, 'area_sqm' => '168.50',
        ])->assertCreated()->assertJsonPath('price_minor_units', 725000025)->assertJsonPath('price_egp', '7250000.25')
            ->assertJsonPath('preferred_location_id', $location->id)->assertJsonPath('location', 'New Cairo');

        $id = $created->json('id');
        $this->assertStringStartsWith('INV-'.$company->id.'-', $created->json('reference_code'));
        $this->putJson('/api/v1/inventory/'.$id, ['status' => 'reserved'])
            ->assertOk()->assertJsonPath('status', 'reserved');
        $this->putJson('/api/v1/inventory/'.$id, ['price_egp' => '7000000.10'])
            ->assertOk()->assertJsonPath('price_minor_units', 700000010);
        $this->assertDatabaseHas('property_listing_activities', ['company_id' => $company->id, 'property_listing_id' => $id, 'event' => 'created']);
        $this->assertDatabaseHas('property_listing_activities', ['company_id' => $company->id, 'property_listing_id' => $id, 'event' => 'status_changed']);
        $this->assertDatabaseHas('property_listing_activities', ['company_id' => $company->id, 'property_listing_id' => $id, 'event' => 'price_changed']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'event' => 'inventory.listing_created', 'auditable_id' => $id]);
    }

    public function test_inventory_can_be_filtered_and_isolated_to_the_active_company(): void
    {
        [$company, $owner] = $this->workspace('owner');
        [$otherCompany] = $this->workspace('owner', 'Other Realty', 'other-realty');
        $mine = $this->listing($company, $owner, ['reference_code' => 'MINE-1', 'title' => 'New Cairo apartment']);
        $other = $this->listing($otherCompany, $owner, ['reference_code' => 'OTHER-1', 'title' => 'Alexandria villa']);

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/inventory?location=Cairo&status=available')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson('/api/v1/inventory/'.$other->id)->assertNotFound();
        $this->putJson('/api/v1/inventory/'.$other->id, ['status' => 'reserved'])->assertNotFound();
    }

    public function test_agents_can_view_inventory_but_cannot_change_it(): void
    {
        [$company, $agent] = $this->workspace('agent');
        $listing = $this->listing($company, $agent);
        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/inventory')->assertOk()->assertJsonPath('data.0.id', $listing->id);
        $this->postJson('/api/v1/inventory', [
            'title' => 'Unauthorized listing', 'listing_type' => 'sale', 'property_type' => 'villa',
            'location' => 'Giza', 'price_egp' => '5000000',
        ])->assertForbidden();
        $this->putJson('/api/v1/inventory/'.$listing->id, ['status' => 'sold'])->assertForbidden();
    }

    public function test_property_photos_are_private_and_company_scoped(): void
    {
        Storage::fake('private');
        [$company, $owner] = $this->workspace('owner');
        [$otherCompany] = $this->workspace('owner', 'Other Realty', 'other-realty');
        $listing = $this->listing($company, $owner);
        $otherListing = $this->listing($otherCompany, $owner);

        $upload = $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/inventory/'.$listing->id.'/photos', ['photo' => UploadedFile::fake()->image('front.webp')])
            ->assertCreated()->assertJsonPath('mime_type', 'image/webp');
        $photoId = $upload->json('id');
        $photo = PropertyPhoto::findOrFail($photoId);
        $this->assertStringStartsWith('companies/'.$company->id.'/inventory/'.$listing->id.'/', $photo->storage_path);
        Storage::disk('private')->assertExists($photo->storage_path);

        $this->getJson('/api/v1/inventory/'.$listing->id.'/photos/'.$photoId)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $foreignPhoto = PropertyPhoto::create([
            'company_id' => $otherCompany->id, 'property_listing_id' => $otherListing->id,
            'storage_path' => 'companies/'.$otherCompany->id.'/inventory/'.$otherListing->id.'/foreign.jpg',
            'original_name' => 'foreign.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);
        Storage::disk('private')->put($foreignPhoto->storage_path, 'private');
        $this->getJson('/api/v1/inventory/'.$otherListing->id.'/photos/'.$foreignPhoto->id)->assertNotFound();
        $this->deleteJson('/api/v1/inventory/'.$otherListing->id.'/photos/'.$foreignPhoto->id)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'event' => 'inventory.photo_uploaded', 'auditable_id' => $photoId]);
        $this->assertDatabaseHas('property_listing_activities', ['company_id' => $company->id, 'property_listing_id' => $listing->id, 'event' => 'photo_uploaded']);
    }

    public function test_prices_reject_values_that_cannot_be_stored_as_egp_minor_units(): void
    {
        [$company, $owner] = $this->workspace('owner');
        $location = PreferredLocation::where('company_id', $company->id)->firstOrFail();
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/inventory', [
                'title' => 'Invalid price', 'listing_type' => 'sale', 'property_type' => 'apartment',
                'preferred_location_id' => $location->id, 'price_egp' => '12.345',
            ])->assertUnprocessable()->assertJsonValidationErrors('price_egp');
    }

    public function test_listing_location_must_be_active_and_belong_to_the_current_company(): void
    {
        [$company, $owner] = $this->workspace('owner');
        [$otherCompany] = $this->workspace('owner', 'Other Realty', 'other-realty');
        $foreignLocation = PreferredLocation::where('company_id', $otherCompany->id)->firstOrFail();
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/inventory', [
                'title' => 'Foreign location listing', 'listing_type' => 'sale', 'property_type' => 'apartment',
                'preferred_location_id' => $foreignLocation->id, 'price_egp' => '1200000',
            ])->assertUnprocessable()->assertJsonValidationErrors('preferred_location_id');

        $inactiveLocation = PreferredLocation::where('company_id', $company->id)->firstOrFail();
        $inactiveLocation->update(['is_active' => false]);
        $this->postJson('/api/v1/inventory', [
            'title' => 'Inactive location listing', 'listing_type' => 'sale', 'property_type' => 'apartment',
            'preferred_location_id' => $inactiveLocation->id, 'price_egp' => '1200000',
        ])->assertUnprocessable()->assertJsonValidationErrors('preferred_location_id');
    }

    private function workspace(string $role, string $name = 'Test Realty', string $slug = 'test-realty'): array
    {
        $company = Company::create(['name' => $name, 'slug' => $slug]);
        foreach (['Cairo', 'New Cairo'] as $position => $locationName) {
            PreferredLocation::create(['company_id' => $company->id, 'name' => $locationName, 'position' => $position]);
        }
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $user->id, 'role' => $role, 'status' => 'active']);
        return [$company, $user];
    }

    private function listing(Company $company, User $user, array $overrides = []): PropertyListing
    {
        return PropertyListing::create(array_merge([
            'company_id' => $company->id, 'reference_code' => 'TEST-'.uniqid(), 'title' => 'Test property',
            'listing_type' => 'sale', 'property_type' => 'apartment', 'status' => 'available', 'location' => 'Cairo',
            'price_minor_units' => 50000000, 'currency' => 'EGP', 'listed_by' => $user->id,
        ], $overrides));
    }
}
