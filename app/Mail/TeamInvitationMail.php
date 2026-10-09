<?php

namespace App\Mail;

use App\Models\CompanyInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CompanyInvitation $invitation, public string $acceptUrl) {}

    public function build(): self
    {
        return $this->subject('You are invited to join '.$this->invitation->company->name)
            ->view('emails.team-invitation');
    }
}
