<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetPlatformAdmin extends Command
{
    protected $signature = 'platform-admin:set {email : Existing verified user email} {--revoke : Remove platform administrator access}';
    protected $description = 'Grant or revoke Exousia platform administrator access for an existing user.';

    public function handle(): int
    {
        $user = User::whereRaw('LOWER(email) = ?', [strtolower($this->argument('email'))])->first();
        if (! $user) {
            $this->error('No user with that email address exists.');
            return self::FAILURE;
        }
        if (! $user->hasVerifiedEmail() && ! $this->option('revoke')) {
            $this->error('Verify the account email before granting platform access.');
            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $user->forceFill(['is_platform_admin' => $grant])->save();
        $this->info(($grant ? 'Granted' : 'Revoked').' platform administrator access for '.$user->email.'.');
        return self::SUCCESS;
    }
}
