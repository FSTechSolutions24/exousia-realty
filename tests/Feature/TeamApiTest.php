<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Mail\TeamInvitationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_add_and_update_a_workspace_member(): void
    {
        [$company, $owner] = $this->workspace();
        $person = User::factory()->create(['name' => 'New Agent', 'email' => 'agent@example.test']);
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id);

        $this->postJson('/api/v1/team/members', ['name' => $person->name, 'email' => $person->email, 'role' => 'agent'])
            ->assertCreated()->assertJsonPath('member.user.id', $person->id)->assertJsonPath('member.status', 'active');
        $membership = CompanyMembership::where('company_id', $company->id)->where('user_id', $person->id)->firstOrFail();

        $this->patchJson('/api/v1/team/members/'.$membership->id, ['role' => 'manager'])
            ->assertOk()->assertJsonPath('role', 'manager');
        $this->patchJson('/api/v1/team/members/'.$membership->id, ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('status', 'inactive');
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'event' => 'team.member_added']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'event' => 'team.member_updated']);
    }

    public function test_an_owner_can_invite_a_new_person_and_they_can_accept(): void
    {
        Mail::fake();
        [$company, $owner] = $this->workspace();
        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->postJson('/api/v1/team/members', ['name' => 'Beshoy Acladus', 'email' => 'beshoy.acladus@demo.exousia.test', 'role' => 'agent'])
            ->assertCreated()->assertJsonPath('invitation.name', 'Beshoy Acladus')->assertJsonPath('invitation.status', 'pending');

        $invitation = \App\Models\CompanyInvitation::where('company_id', $company->id)->firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'event' => 'team.invitation_created']);
        $plainToken = null;
        Mail::assertSent(TeamInvitationMail::class, function (TeamInvitationMail $mail) use (&$plainToken) {
            parse_str((string) parse_url($mail->acceptUrl, PHP_URL_QUERY), $query);
            $plainToken = $query['token'] ?? null;
            return $mail->hasTo('beshoy.acladus@demo.exousia.test');
        });
        $this->assertNotNull($plainToken);
        $this->assertNotSame($plainToken, $invitation->token_hash);

        $this->postJson('/api/v1/auth/invitations/accept', [
            'token' => $plainToken, 'password' => 'secure-pass123', 'password_confirmation' => 'secure-pass123',
        ])->assertCreated();

        $newUser = User::where('email', 'beshoy.acladus@demo.exousia.test')->firstOrFail();
        $this->assertAuthenticatedAs($newUser, 'web');
        $this->assertNotNull($newUser->email_verified_at);
        $this->assertDatabaseHas('company_memberships', ['company_id' => $company->id, 'user_id' => $newUser->id, 'role' => 'agent', 'status' => 'active']);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'event' => 'team.invitation_accepted']);
        $this->postJson('/api/v1/auth/invitations/accept', ['token' => $plainToken])
            ->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_a_manager_can_view_but_cannot_administer_team_memberships(): void
    {
        [$company, $owner] = $this->workspace();
        $manager = User::factory()->create();
        $company->memberships()->create(['user_id' => $manager->id, 'role' => 'manager', 'status' => 'active']);

        $this->actingAs($manager)->withHeader('X-Company-ID', $company->id)
            ->getJson('/api/v1/team/members')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/team/members', ['email' => $owner->email, 'role' => 'agent'])->assertForbidden();
    }

    public function test_member_ids_are_scoped_to_the_active_company(): void
    {
        [$company, $owner] = $this->workspace();
        $other = Company::create(['name' => 'Other Realty', 'slug' => 'other-realty']);
        $otherMember = User::factory()->create();
        $membership = $other->memberships()->create(['user_id' => $otherMember->id, 'role' => 'agent', 'status' => 'active']);

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->patchJson('/api/v1/team/members/'.$membership->id, ['status' => 'inactive'])->assertNotFound();
        $this->assertDatabaseHas('company_memberships', ['id' => $membership->id, 'status' => 'active']);
    }

    public function test_company_cannot_remove_its_last_active_owner(): void
    {
        [$company, $owner] = $this->workspace();
        $membership = CompanyMembership::where('company_id', $company->id)->where('user_id', $owner->id)->firstOrFail();

        $this->actingAs($owner)->withHeader('X-Company-ID', $company->id)
            ->patchJson('/api/v1/team/members/'.$membership->id, ['status' => 'inactive'])
            ->assertUnprocessable();
    }

    private function workspace(): array
    {
        $company = Company::create(['name' => 'Test Realty', 'slug' => 'test-realty']);
        $owner = User::factory()->create(['current_company_id' => $company->id]);
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        return [$company, $owner];
    }
}
