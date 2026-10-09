<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PreferredLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferredLocationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_preferred_locations(): void
    {
        [$company, $owner] = $this->workspace('owner');
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id);

        $created = $this->postJson('/api/v1/preferred-locations', [
            'name' => 'New Cairo',
            'name_ar' => 'القاهرة الجديدة',
        ])->assertCreated()->assertJsonPath('name', 'New Cairo');

        $id = $created->json('id');
        $this->putJson("/api/v1/preferred-locations/{$id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);

        $this->getJson('/api/v1/preferred-locations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/preferred-locations?active=1')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/preferred-locations/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('preferred_locations', ['id' => $id]);
    }

    public function test_agent_can_read_locations_but_cannot_manage_them(): void
    {
        [$company, $agent] = $this->workspace('agent');
        PreferredLocation::create(['company_id' => $company->id, 'name' => 'Maadi']);

        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/preferred-locations?active=1')
            ->assertOk()->assertJsonPath('data.0.name', 'Maadi');

        $this->postJson('/api/v1/preferred-locations', ['name' => 'Zamalek'])->assertForbidden();
    }

    public function test_locations_are_isolated_by_company(): void
    {
        [$company, $owner] = $this->workspace('owner');
        $other = Company::create(['name' => 'Other Realty', 'slug' => 'other-realty']);
        PreferredLocation::create(['company_id' => $company->id, 'name' => 'New Cairo']);
        PreferredLocation::create(['company_id' => $other->id, 'name' => 'Alexandria']);

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/preferred-locations')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'New Cairo');
    }

    private function workspace(string $role): array
    {
        $company = Company::create(['name' => ucfirst($role).' Realty', 'slug' => $role.'-realty']);
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $user->id, 'role' => $role, 'status' => 'active']);

        return [$company, $user];
    }
}
