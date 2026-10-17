<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RealEstateDeveloper;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RealEstateDeveloperController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        abort_unless($request->attributes->get('membership')->can('view_inventory'), 403);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = RealEstateDeveloper::where('company_id', $tenant->id())
            ->withCount('listings')->orderBy('name');
        if (! empty($data['search'])) {
            $term = trim($data['search']);
            $query->where(fn ($builder) => $builder->where('name', 'like', "%{$term}%")
                ->orWhere('contact_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%"));
        }
        if (array_key_exists('active', $data)) $query->where('is_active', $request->boolean('active'));
        return response()->json($query->paginate((int) ($data['per_page'] ?? 20)));
    }

    public function store(Request $request, TenantContext $tenant, AuditLogger $audit)
    {
        $this->authorizeManage($request);
        $data = $this->validateDeveloper($request, $tenant);
        $developer = RealEstateDeveloper::create($data + ['company_id' => $tenant->id()]);
        $audit->log($request, 'developer.created', $developer, [], $developer->toArray());
        return response()->json($developer->loadCount('listings'), 201);
    }

    public function update(Request $request, TenantContext $tenant, int $developer, AuditLogger $audit)
    {
        $this->authorizeManage($request);
        $developer = $this->findDeveloper($tenant, $developer);
        $old = $developer->toArray();
        $developer->update($this->validateDeveloper($request, $tenant, $developer));
        $audit->log($request, 'developer.updated', $developer, $old, $developer->fresh()->toArray());
        return response()->json($developer->fresh()->loadCount('listings'));
    }

    public function destroy(Request $request, TenantContext $tenant, int $developer, AuditLogger $audit)
    {
        $this->authorizeManage($request);
        $developer = $this->findDeveloper($tenant, $developer);
        $wasActive = $developer->is_active;
        $developer->update(['is_active' => false]);
        $audit->log($request, 'developer.deactivated', $developer, ['is_active' => $wasActive], ['is_active' => false]);
        return response()->noContent();
    }

    private function validateDeveloper(Request $request, TenantContext $tenant, ?RealEstateDeveloper $developer = null): array
    {
        $nameRule = Rule::unique('real_estate_developers', 'name')->where('company_id', $tenant->id());
        if ($developer) $nameRule->ignore($developer->id);
        return $request->validate([
            'name' => [$developer ? 'sometimes' : 'required', 'string', 'max:160', $nameRule],
            'contact_name' => ['nullable', 'string', 'max:160'], 'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'], 'website' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'], 'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function findDeveloper(TenantContext $tenant, int $id): RealEstateDeveloper
    {
        return RealEstateDeveloper::where('company_id', $tenant->id())->findOrFail($id);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->can('manage_settings'), 403);
    }
}
