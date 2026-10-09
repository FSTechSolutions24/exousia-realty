<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 422);
        }

        $request->session()->regenerate();
        if (! $request->user()->hasVerifiedEmail()) {
            return response()->json([
                'verification_required' => true,
                'email' => $request->user()->email,
                'message' => 'Verify your email address before opening your workspace.',
            ], 403);
        }
        return response()->json(['message' => 'Welcome back.']);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'company_name' => ['required', 'string', 'max:160'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $company = Company::create([
                'name' => $data['company_name'],
                'slug' => Str::slug($data['company_name']).'-'.Str::lower(Str::random(5)),
            ]);
            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => Hash::make($data['password']),
                'current_company_id' => $company->id,
            ]);
            $company->memberships()->create([
                'user_id' => $user->id,
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);
            $this->createDefaultStages($company);
            return $user;
        });

        $user->sendEmailVerificationNotification();
        return response()->json([
            'message' => 'Check your email to verify your address and activate your workspace.',
            'email' => $user->email,
        ], 201);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'Signed out.']);
    }

    private function createDefaultStages(Company $company): void
    {
        $stages = [
            ['New lead', 'عميل جديد', '#64748b'],
            ['Contacted', 'تم التواصل', '#2563eb'],
            ['Qualified', 'مؤهل', '#7c3aed'],
            ['Viewing', 'معاينة', '#d97706'],
            ['Negotiation', 'تفاوض', '#db6b32'],
            ['Won', 'مكتمل', '#16836f', true, false],
            ['Lost', 'مفقود', '#dc3545', false, true],
        ];
        foreach ($stages as $position => $stage) {
            PipelineStage::create([
                'company_id' => $company->id, 'name' => $stage[0], 'name_ar' => $stage[1],
                'color' => $stage[2], 'position' => $position, 'is_won' => $stage[3] ?? false, 'is_lost' => $stage[4] ?? false,
            ]);
        }
    }
}
