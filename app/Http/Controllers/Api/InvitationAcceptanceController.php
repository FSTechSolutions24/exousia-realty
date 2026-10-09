<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class InvitationAcceptanceController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $tokenHash = hash('sha256', $data['token']);
        $invitation = CompanyInvitation::where('token_hash', $tokenHash)->first();
        $this->ensureUsable($invitation);
        $existingUser = User::whereRaw('LOWER(email) = ?', [Str::lower($invitation->email)])->exists();

        if (! $existingUser) {
            $request->validate(['password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()]]);
        }

        [$user, $membership, $invitation] = DB::transaction(function () use ($tokenHash, $request) {
            $invitation = CompanyInvitation::where('token_hash', $tokenHash)->lockForUpdate()->first();
            $this->ensureUsable($invitation);

            $email = Str::lower($invitation->email);
            $user = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            if (! $user) {
                if (! $request->filled('password')) {
                    throw ValidationException::withMessages(['password' => 'Create a password to finish setting up your account.']);
                }
                $user = User::create([
                    'name' => $invitation->name, 'email' => $email,
                    'password' => Hash::make($request->input('password')),
                    'current_company_id' => $invitation->company_id,
                ]);
                $user->email_verified_at = now();
                $user->save();
            }

            $membership = CompanyMembership::where('company_id', $invitation->company_id)
                ->where('user_id', $user->id)->first();
            if ($membership) {
                $membership->update(['role' => $invitation->role, 'status' => 'active', 'joined_at' => now()]);
            } else {
                $membership = CompanyMembership::create([
                    'company_id' => $invitation->company_id, 'user_id' => $user->id,
                    'role' => $invitation->role, 'status' => 'active', 'joined_at' => now(),
                ]);
            }

            $user->update(['current_company_id' => $invitation->company_id]);
            $invitation->update(['accepted_at' => now()]);
            return [$user, $membership, $invitation];
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $audit->log($request, 'team.invitation_accepted', $membership, [], ['role' => $membership->role, 'status' => $membership->status]);

        return response()->json(['message' => 'Invitation accepted. Your workspace is ready.'], 201);
    }

    private function ensureUsable(?CompanyInvitation $invitation): void
    {
        if (! $invitation || $invitation->accepted_at || $invitation->revoked_at || $invitation->expires_at->isPast()) {
            throw ValidationException::withMessages(['token' => 'This invitation is invalid, expired, or has already been used.']);
        }
    }
}
