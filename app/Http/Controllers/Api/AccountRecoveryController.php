<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AccountRecoveryController extends Controller
{
    public function sendResetLink(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        Password::sendResetLink(['email' => Str::lower($data['email'])]);

        return response()->json(['message' => 'If an account exists for that email, a password reset link has been sent.']);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);
        $data['email'] = Str::lower($data['email']);

        $status = Password::reset(Arr::except($data, ['password_confirmation']), function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'This password reset link is invalid or expired. Request a new one and try again.'], 422);
        }

        return response()->json(['message' => 'Your password has been reset. You can sign in now.']);
    }

    public function sendVerificationLink(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $user = User::whereRaw('LOWER(email) = ?', [Str::lower($data['email'])])->first();
        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'If the account needs verification, a verification link has been sent.']);
    }
}
