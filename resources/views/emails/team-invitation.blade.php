<h1>You are invited to join {{ $invitation->company->name }}</h1>
<p>Hello {{ $invitation->name }},</p>
<p>{{ $invitation->inviter?->name ?? 'A workspace administrator' }} invited you to join {{ $invitation->company->name }} as a {{ $invitation->role }}.</p>
<p><a href="{{ $acceptUrl }}">Accept invitation</a></p>
<p>This invitation expires {{ $invitation->expires_at->toDayDateTimeString() }}. If you were not expecting it, you can ignore this message.</p>
