<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_upload_private_logo_for_the_workspace(): void
    {
        Storage::fake('private');
        [$company, $owner] = $this->workspace('alpha');
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id);

        $upload = $this->postJson('/api/v1/company/logo', ['logo' => UploadedFile::fake()->image('brand.png', 150, 90)])
            ->assertOk()->assertJsonPath('logo_mime_type', 'image/png');
        $company->refresh();
        $this->assertStringStartsWith('companies/'.$company->id.'/branding/', $company->logo_path);
        Storage::disk('private')->assertExists($company->logo_path);
        $this->getJson('/api/v1/bootstrap')->assertOk()->assertJsonPath('company.logo_url', $upload->json('logo_url'))
            ->assertJsonMissingPath('company.logo_path');
        $this->get('/api/v1/company/logo')->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        [$otherCompany, $otherOwner] = $this->workspace('beta');
        $this->actingAs($otherOwner)->withHeader('X-Company-ID', $otherCompany->id)
            ->getJson('/api/v1/bootstrap')->assertOk()->assertJsonPath('company.logo_url', null);
        $this->get('/api/v1/company/logo')->assertNotFound();

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id);
        $this->deleteJson('/api/v1/company/logo')->assertNoContent();
        Storage::disk('private')->assertMissing($company->logo_path);
        $this->assertNull($company->fresh()->logo_path);
    }

    public function test_regular_members_cannot_change_company_branding(): void
    {
        Storage::fake('private');
        [$company, $owner] = $this->workspace('alpha');
        $agent = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $agent->id, 'role' => 'agent', 'status' => 'active']);
        $this->actingAs($agent)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/company/logo', ['logo' => UploadedFile::fake()->image('brand.png')])->assertForbidden();
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/company/logo', ['logo' => UploadedFile::fake()->create('logo.svg', 100, 'image/svg+xml')])
            ->assertUnprocessable()->assertJsonValidationErrors('logo');
        $this->assertNull($company->fresh()->logo_path);
    }

    private function workspace(string $slug): array
    {
        $company = Company::create(['name' => ucfirst($slug).' Realty', 'slug' => $slug.'-brand']);
        $this->enableCompanyTrial($company);
        $owner = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        return [$company, $owner];
    }
}
