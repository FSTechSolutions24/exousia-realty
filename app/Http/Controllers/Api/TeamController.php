<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TeamInvitationMail;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        abort_unless($request->attributes->get('membership')->can('view_team'), 403);

        $members = CompanyMembership::where('company_id', $tenant->id())
            ->with('user:id,name,email')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->get()
            ->map(fn (CompanyMembership $membership) => [
                'id' => $membership->id,
                'user' => $membership->user,
                'role' => $membership->role,
                'status' => $membership->status,
                'joined_at' => $membership->joined_at,
                'created_at' => $membership->created_at,
            ]);

        $invitations = CompanyInvitation::where('company_id', $tenant->id())
            ->whereNull('accepted_at')->whereNull('revoked_at')->latest()
            ->get()->map(fn (CompanyInvitation $invitation) => [
                'id' => $invitation->id, 'name' => $invitation->name, 'email' => $invitation->email,
                'role' => $invitation->role, 'status' => $invitation->expires_at->isPast() ? 'expired' : 'pending',
                'expires_at' => $invitation->expires_at,
            ]);

        return response()->json(['data' => $members, 'invitations' => $invitations]);
    }

    public function store(Request $request, TenantContext $tenant, AuditLogger $audit)
    {
        $this->authorizeManagement($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', Rule::in(['admin', 'manager', 'agent', 'operations', 'finance'])],
        ]);

        $data['email'] = Str::lower($data['email']);
        $user = User::whereRaw('LOWER(email) = ?', [$data['email']])->first();
        if ($user) {
            $membership = CompanyMembership::where('company_id', $tenant->id())->where('user_id', $user->id)->first();
            if ($membership && $membership->status === 'active') {
                throw ValidationException::withMessages(['email' => 'This person is already an active member.']);
            }

            if ($membership) {
                $old = $membership->only(['role', 'status']);
                $membership->update(['role' => $data['role'], 'status' => 'active', 'joined_at' => now()]);
                $event = 'team.member_reactivated';
            } else {
                $membership = CompanyMembership::create([
                    'company_id' => $tenant->id(), 'user_id' => $user->id, 'role' => $data['role'],
                    'status' => 'active', 'joined_at' => now(),
                ]);
                $old = [];
                $event = 'team.member_added';
            }

            $audit->log($request, $event, $membership, $old, $membership->only(['role', 'status']));
            return response()->json(['member' => $this->serialize($membership->load('user:id,name,email'))], 201);
        }

        $plainToken = Str::random(64);
        $existingInvitation = CompanyInvitation::where('company_id', $tenant->id())
            ->where('email', $data['email'])->whereNull('accepted_at')->whereNull('revoked_at')->latest()->first();
        if ($existingInvitation) {
            $existingInvitation->update([
                'invited_by' => $request->user()->id, 'name' => $data['name'], 'role' => $data['role'],
                'token_hash' => hash('sha256', $plainToken), 'expires_at' => now()->addDays(7),
            ]);
            $invitation = $existingInvitation->fresh();
            $event = 'team.invitation_resent';
        } else {
            $invitation = CompanyInvitation::create([
                'company_id' => $tenant->id(), 'invited_by' => $request->user()->id,
                'name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'],
                'token_hash' => hash('sha256', $plainToken), 'expires_at' => now()->addDays(7),
            ]);
            $event = 'team.invitation_created';
        }
        $audit->log($request, $event, $invitation, [], ['email' => $invitation->email, 'role' => $invitation->role]);
        $url = url('/accept-invite?token='.urlencode($plainToken));
        Mail::to($invitation->email)->send(new TeamInvitationMail($invitation->load(['company', 'inviter']), $url));

        return response()->json(['invitation' => [
            'id' => $invitation->id, 'name' => $invitation->name, 'email' => $invitation->email,
            'role' => $invitation->role, 'status' => 'pending', 'expires_at' => $invitation->expires_at,
        ]], 201);
    }

    public function revoke(Request $request, TenantContext $tenant, int $invitation, AuditLogger $audit)
    {
        $this->authorizeManagement($request);
        $invitation = CompanyInvitation::where('company_id', $tenant->id())->whereNull('accepted_at')->whereNull('revoked_at')->findOrFail($invitation);
        $invitation->update(['revoked_at' => now()]);
        $audit->log($request, 'team.invitation_revoked', $invitation, ['status' => 'pending'], ['status' => 'revoked']);
        return response()->noContent();
    }

    public function update(Request $request, TenantContext $tenant, int $membership, AuditLogger $audit)
    {
        $this->authorizeManagement($request);
        $target = CompanyMembership::where('company_id', $tenant->id())->with('user:id,name,email')->findOrFail($membership);
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(['admin', 'manager', 'agent', 'operations', 'finance'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        if (! $data) abort(422);

        if ($target->user_id === $request->user()->id && (($data['status'] ?? $target->status) !== 'active' || ($data['role'] ?? $target->role) !== $target->role)) {
            throw ValidationException::withMessages(['membership' => 'You cannot deactivate yourself or change your own role.']);
        }

        if ($target->role === 'owner' && (($data['role'] ?? 'owner') !== 'owner' || ($data['status'] ?? 'active') !== 'active')) {
            $otherOwners = CompanyMembership::where('company_id', $tenant->id())->where('role', 'owner')->where('status', 'active')->whereKeyNot($target->id)->count();
            if ($otherOwners === 0) {
                throw ValidationException::withMessages(['membership' => 'The company must keep at least one active owner.']);
            }
        }

        $old = $target->only(['role', 'status']);
        $target->update($data);
        $audit->log($request, 'team.member_updated', $target, $old, $target->fresh()->only(['role', 'status']));

        return response()->json($this->serialize($target->fresh()->load('user:id,name,email')));
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->can('manage_members'), 403);
    }

    private function serialize(CompanyMembership $membership): array
    {
        return [
            'id' => $membership->id, 'user' => $membership->user, 'role' => $membership->role,
            'status' => $membership->status, 'joined_at' => $membership->joined_at, 'created_at' => $membership->created_at,
        ];
    }
}
