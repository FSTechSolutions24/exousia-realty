<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PreferredLocation;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreferredLocationController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        $query = PreferredLocation::where('company_id', $tenant->id())
            ->orderBy('position')
            ->orderBy('name');

        if ($request->boolean('active')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request, TenantContext $tenant, AuditLogger $audit)
    {
        $this->authorizeManagement($request);
        $data = $this->validateLocation($request, $tenant);
        $data['company_id'] = $tenant->id();
        $data['position'] = $data['position'] ?? ((int) PreferredLocation::where('company_id', $tenant->id())->max('position') + 1);
        $location = PreferredLocation::create($data);
        $audit->log($request, 'preferred_location.created', $location, [], $location->toArray());

        return response()->json($location, 201);
    }

    public function update(Request $request, TenantContext $tenant, int $preferredLocation, AuditLogger $audit)
    {
        $this->authorizeManagement($request);
        $location = $this->findLocation($tenant, $preferredLocation);
        $data = $this->validateLocation($request, $tenant, $location);
        $old = $location->toArray();
        $location->update($data);
        $audit->log($request, 'preferred_location.updated', $location, $old, $location->fresh()->toArray());

        return response()->json($location->fresh());
    }

    public function destroy(Request $request, TenantContext $tenant, int $preferredLocation, AuditLogger $audit)
    {
        $this->authorizeManagement($request);
        $location = $this->findLocation($tenant, $preferredLocation);
        $old = $location->toArray();
        $audit->log($request, 'preferred_location.deleted', $location, $old, []);
        $location->delete();

        return response()->noContent();
    }

    private function findLocation(TenantContext $tenant, int $id): PreferredLocation
    {
        return PreferredLocation::where('company_id', $tenant->id())->findOrFail($id);
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->can('manage_settings'), 403);
    }

    private function validateLocation(Request $request, TenantContext $tenant, ?PreferredLocation $location = null): array
    {
        $nameRule = Rule::unique('preferred_locations', 'name')->where('company_id', $tenant->id());
        if ($location) {
            $nameRule->ignore($location->id);
        }

        return $request->validate([
            'name' => [$location ? 'sometimes' : 'required', 'string', 'max:100', $nameRule],
            'name_ar' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);
    }
}
