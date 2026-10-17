<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyBrandingController extends Controller
{
    public function show(Request $request, TenantContext $tenant)
    {
        $company = $tenant->company();
        abort_unless($company->logo_path && Storage::disk('private')->exists($company->logo_path), 404);
        $extension = pathinfo($company->logo_path, PATHINFO_EXTENSION);
        return Storage::disk('private')->response($company->logo_path, 'company-logo.'.$extension, [
            'Content-Type' => $company->logo_mime_type ?: 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, TenantContext $tenant, AuditLogger $audit)
    {
        $this->authorizeManage($request);
        $data = $request->validate(['logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $company = $tenant->company();
        $file = $data['logo'];
        $extension = strtolower($file->extension());
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw ValidationException::withMessages(['logo' => 'Choose a JPEG, PNG, or WebP logo image.']);
        }
        $path = $file->storeAs("companies/{$tenant->id()}/branding", Str::uuid().'.'.$extension, 'private');
        $oldPath = $company->logo_path;
        $old = ['has_logo' => (bool) $oldPath, 'mime_type' => $company->logo_mime_type];
        $company->update(['logo_path' => $path, 'logo_mime_type' => $file->getMimeType()]);
        if ($oldPath) Storage::disk('private')->delete($oldPath);
        $audit->log($request, 'company.logo_updated', $company, $old, [
            'has_logo' => true, 'mime_type' => $company->logo_mime_type, 'size_bytes' => $file->getSize(),
        ]);
        return response()->json(['logo_url' => route('company.logo'), 'logo_mime_type' => $company->logo_mime_type]);
    }

    public function destroy(Request $request, TenantContext $tenant, AuditLogger $audit)
    {
        $this->authorizeManage($request);
        $company = $tenant->company();
        $oldPath = $company->logo_path;
        abort_unless($oldPath, 404);
        $company->update(['logo_path' => null, 'logo_mime_type' => null]);
        Storage::disk('private')->delete($oldPath);
        $audit->log($request, 'company.logo_removed', $company, ['has_logo' => true], ['has_logo' => false]);
        return response()->noContent();
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->can('manage_settings'), 403);
    }
}
