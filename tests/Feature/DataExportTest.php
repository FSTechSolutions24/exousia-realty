<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealCommission;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\PreferredLocation;
use App\Models\PropertyListing;
use App\Models\RealEstateDeveloper;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class DataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_export_filtered_company_data_as_real_excel_workbooks(): void
    {
        [$company, $owner, $stage] = $this->workspace('alpha');
        [$foreignCompany, $foreignOwner, $foreignStage] = $this->workspace('beta');

        $lead = $this->lead($company, $owner, $stage, 'Filtered lead', 'facebook');
        $this->lead($company, $owner, $stage, 'Other source', 'referral');
        $this->lead($foreignCompany, $foreignOwner, $foreignStage, 'Foreign lead', 'facebook');
        $developer = RealEstateDeveloper::create(['company_id' => $company->id, 'name' => 'Local Developer', 'contact_name' => '=2+2']);
        RealEstateDeveloper::create(['company_id' => $foreignCompany->id, 'name' => 'Foreign Developer']);
        $location = PreferredLocation::create(['company_id' => $company->id, 'name' => 'New Cairo', 'position' => 0]);
        $listing = PropertyListing::create([
            'company_id' => $company->id, 'reference_code' => 'LOCAL-1', 'title' => 'Local apartment',
            'listing_type' => 'sale', 'property_type' => 'apartment', 'status' => 'available',
            'location' => 'New Cairo', 'preferred_location_id' => $location->id, 'developer_id' => $developer->id,
            'price_minor_units' => 725000025, 'currency' => 'EGP', 'listed_by' => $owner->id,
        ]);
        PropertyListing::create([
            'company_id' => $foreignCompany->id, 'reference_code' => 'FOREIGN-1', 'title' => 'Foreign property',
            'listing_type' => 'sale', 'property_type' => 'villa', 'status' => 'available',
            'location' => 'Alexandria', 'price_minor_units' => 500000000, 'currency' => 'EGP', 'listed_by' => $foreignOwner->id,
        ]);
        $deal = Deal::create([
            'company_id' => $company->id, 'lead_id' => $lead->id, 'property_listing_id' => $listing->id,
            'created_by' => $owner->id, 'status' => 'negotiation', 'agreed_price_minor_units' => 700000000,
        ]);
        DealCommission::create([
            'company_id' => $company->id, 'deal_id' => $deal->id,
            'company_membership_id' => $company->memberships()->where('user_id', $owner->id)->value('id'),
            'payee_name' => $owner->name, 'amount_minor_units' => 1000000, 'status' => 'pending',
            'created_by' => $owner->id,
        ]);
        FollowUpTask::create([
            'company_id' => $company->id, 'lead_id' => $lead->id, 'assigned_to' => $owner->id,
            'created_by' => $owner->id, 'title' => 'Call filtered lead', 'priority' => 'high', 'due_at' => now()->addHour(),
        ]);

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id);
        $leadXml = $this->sheetXml($this->get('/api/v1/exports/leads?source=facebook'));
        $this->assertStringContainsString('Filtered lead', $leadXml);
        $this->assertStringNotContainsString('Other source', $leadXml);
        $this->assertStringNotContainsString('Foreign lead', $leadXml);

        $inventoryXml = $this->sheetXml($this->get('/api/v1/exports/inventory?status=available'));
        $this->assertStringContainsString('Local apartment', $inventoryXml);
        $this->assertStringContainsString('7250000.25', $inventoryXml);
        $this->assertStringNotContainsString('Foreign property', $inventoryXml);

        $developerXml = $this->sheetXml($this->get('/api/v1/exports/developers?active=1'));
        $this->assertStringContainsString('Local Developer', $developerXml);
        $this->assertStringContainsString('<t>=2+2</t>', $developerXml);
        $this->assertStringNotContainsString('<f>', $developerXml);

        foreach (['deals', 'commissions', 'tasks', 'team'] as $resource) {
            $this->sheetXml($this->get('/api/v1/exports/'.$resource));
        }
        $reportXml = $this->sheetXml($this->get('/api/v1/reports?period=month&format=xlsx'));
        $this->assertStringContainsString('Deal status', $reportXml);
        $this->assertStringContainsString('7000000.00', $reportXml);

        foreach (['leads', 'inventory', 'developers', 'deals', 'commissions', 'tasks', 'team', 'reports'] as $resource) {
            $this->assertDatabaseHas('audit_logs', [
                'company_id' => $company->id, 'user_id' => $owner->id, 'event' => 'export.'.$resource,
            ]);
        }
    }

    public function test_exports_preserve_visibility_permissions_and_tenant_isolation(): void
    {
        [$company, $owner, $stage] = $this->workspace('alpha');
        $agent = User::factory()->create(['current_company_id' => $company->id]);
        $otherAgent = User::factory()->create(['current_company_id' => $company->id]);
        $finance = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->createMany([
            ['user_id' => $agent->id, 'role' => 'agent', 'status' => 'active'],
            ['user_id' => $otherAgent->id, 'role' => 'agent', 'status' => 'active'],
            ['user_id' => $finance->id, 'role' => 'finance', 'status' => 'active'],
        ]);
        $this->lead($company, $agent, $stage, 'Agent visible lead', 'website');
        $this->lead($company, $otherAgent, $stage, 'Other agent private lead', 'website');

        $agentXml = $this->sheetXml($this->actingAs($agent)->withHeader('X-Company-ID', $company->id)->get('/api/v1/exports/leads'));
        $this->assertStringContainsString('Agent visible lead', $agentXml);
        $this->assertStringNotContainsString('Other agent private lead', $agentXml);

        $this->actingAs($finance)->withHeader('X-Company-ID', $company->id)
            ->get('/api/v1/exports/inventory')->assertForbidden();
        $this->get('/api/v1/exports/developers')->assertForbidden();
    }

    private function sheetXml($response): string
    {
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertStringContainsString('/companies/', str_replace('\\', '/', $path));
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertNotFalse($xml);
        $this->assertNotFalse($zip->getFromName('xl/styles.xml'));
        $zip->close();
        return (string) $xml;
    }

    private function workspace(string $slug): array
    {
        $company = Company::create(['name' => ucfirst($slug).' Realty', 'slug' => $slug.'-exports']);
        $this->enableCompanyTrial($company);
        $owner = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        $stage = PipelineStage::create(['company_id' => $company->id, 'name' => 'New lead', 'position' => 0]);
        return [$company, $owner, $stage];
    }

    private function lead(Company $company, User $user, PipelineStage $stage, string $name, string $source): Lead
    {
        return Lead::create([
            'company_id' => $company->id, 'pipeline_stage_id' => $stage->id,
            'created_by' => $user->id, 'assigned_to' => $user->id,
            'name' => $name, 'phone_original' => '01012345678',
            'phone_normalized' => '+201012345678', 'source' => $source,
        ]);
    }
}
