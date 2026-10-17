<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform-admin:create {name : Platform administrator name} {email : Unique administrator email address}';
    protected $description = 'Create a dedicated platform owner account with no company membership.';

    public function handle(): int
    {
        $email = strtolower($this->argument('email'));
        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->error('That email already belongs to a user. Use platform-admin:set to grant access to an existing verified user.');
            return self::FAILURE;
        }

        $password = $this->secret('Password (at least 12 characters)');
        if (! is_string($password) || strlen($password) < 12) {
            $this->error('Choose a password with at least 12 characters.');
            return self::FAILURE;
        }
        if ($password !== $this->secret('Confirm password')) {
            $this->error('The passwords do not match.');
            return self::FAILURE;
        }

        $user = User::create(['name' => $this->argument('name'), 'email' => $email, 'password' => Hash::make($password), 'locale' => 'en']);
        $user->forceFill(['email_verified_at' => now(), 'is_platform_admin' => true])->save();
        $this->info('Created dedicated platform administrator '.$user->email.'. It has no company workspace membership.');
        return self::SUCCESS;
    }
}
