<?php

namespace Tests\Feature;

use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_sends_verification_and_does_not_open_an_unverified_workspace(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Owner', 'email' => 'new.owner@example.test', 'password' => 'secure-pass123',
            'password_confirmation' => 'secure-pass123', 'company_name' => 'New Realty',
        ])->assertCreated()->assertJsonPath('email', 'new.owner@example.test');

        $user = User::where('email', 'new.owner@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('company_memberships', ['user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertGuest('web');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secure-pass123'])
            ->assertForbidden()->assertJsonPath('verification_required', true);
        $this->getJson('/api/v1/bootstrap')->assertForbidden();
    }

    public function test_signed_verification_link_verifies_the_user_and_redirects_to_sign_in(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        $verificationUrl = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$verificationUrl) {
            $verificationUrl = $notification->toMail($user)->actionUrl;
            return true;
        });

        $this->get($verificationUrl)->assertRedirect('/login?verified=1');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verification_resend_uses_a_generic_response(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'verify@example.test']);

        $message = $this->postJson('/api/v1/auth/email/verification-notification', ['email' => $user->email])
            ->assertOk()->json('message');
        $this->postJson('/api/v1/auth/email/verification-notification', ['email' => 'missing@example.test'])
            ->assertOk()->assertJsonPath('message', $message);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_password_reset_link_changes_the_password_and_invalid_tokens_fail(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.test']);

        $response = $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])
            ->assertOk()->json('message');
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'missing@example.test'])
            ->assertOk()->assertJsonPath('message', $response);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;
            return true;
        });
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email, 'token' => $token, 'password' => 'new-secure123',
            'password_confirmation' => 'new-secure123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secure123', $user->fresh()->password));
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email, 'token' => $token, 'password' => 'another-secure123',
            'password_confirmation' => 'another-secure123',
        ])->assertUnprocessable();
    }
}
