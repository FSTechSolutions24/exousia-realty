<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PreferredLocation;
use App\Models\PropertyListing;
use App\Models\RealEstateDeveloper;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeveloperApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_search_and_link_developer_partners_to_inventory(): void
    {
        [$company, $owner] = $this->workspace('alpha');
        [$otherCompany] = $this->workspace('beta');
        $developer = $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/developers', [
                'name' => 'Nile Valley Developments', 'contact_name' => 'Mona Hassan',
                'email' => 'mona@nile.test', 'phone' => '01000000000', 'website' => 'https://nile.test',
            ])->assertCreated()->assertJsonPath('name', 'Nile Valley Developments')->json();
        $foreignDeveloper = RealEstateDeveloper::create(['company_id' => $otherCompany->id, 'name' => 'Foreign Developer']);

        $this->getJson('/api/v1/developers?search=Mona')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $developer['id']);
        $this->getJson('/api/v1/developers?active=1')->assertOk()->assertJsonPath('data.0.id', $developer['id']);
        $location = PreferredLocation::create(['company_id' => $company->id, 'name' => 'New Cairo', 'position' => 0]);
        $payload = [
            'title' => 'Partner apartment', 'listing_type' => 'sale', 'property_type' => 'apartment',
            'preferred_location_id' => $location->id, 'price_egp' => '7500000', 'developer_id' => $developer['id'],
        ];
        $listing = $this->postJson('/api/v1/inventory', $payload)->assertCreated()
            ->assertJsonPath('developer.id', $developer['id'])->assertJsonPath('developer.name', 'Nile Valley Developments');
        $this->assertDatabaseHas('property_listings', ['id' => $listing->json('id'), 'company_id' => $company->id, 'developer_id' => $developer['id']]);
        $this->getJson('/api/v1/inventory?search=Nile')->assertOk()->assertJsonPath('data.0.id', $listing->json('id'));
        $this->postJson('/api/v1/inventory', array_merge($payload, ['title' => 'Foreign link', 'developer_id' => $foreignDeveloper->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('developer_id');
        $this->putJson('/api/v1/developers/'.$foreignDeveloper->id, ['name' => 'Attempted foreign update'])->assertNotFound();

        $this->putJson('/api/v1/developers/'.$developer['id'], ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->assertSame($developer['id'], $listing->json('developer.id'));
        $this->assertDatabaseHas('property_listings', ['id' => $listing->json('id'), 'developer_id' => $developer['id']]);
    }

    public function test_agents_can_search_developers_but_cannot_change_the_partner_directory(): void
    {
        [$company, $owner] = $this->workspace('alpha');
        $agent = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $agent->id, 'role' => 'agent', 'status' => 'active']);
        $developer = RealEstateDeveloper::create(['company_id' => $company->id, 'name' => 'Cairo Homes']);

        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/developers?search=Cairo')->assertOk()->assertJsonPath('data.0.id', $developer->id);
        $this->postJson('/api/v1/developers', ['name' => 'Unauthorized Partner'])->assertForbidden();
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)->getJson('/api/v1/developers?active=not-a-boolean')->assertUnprocessable();
    }

    private function workspace(string $slug): array
    {
        $company = Company::create(['name' => ucfirst($slug).' Realty', 'slug' => $slug.'-dev']);
        $this->enableCompanyTrial($company);
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        return [$company, $user];
    }
}
