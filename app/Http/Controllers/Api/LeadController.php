<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\PipelineStage;
use App\Services\AuditLogger;
use App\Services\EgyptianPhoneNormalizer;
use App\Services\PropertyMatchingService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        $query = $this->visibleQuery($request, $tenant)
            ->with(['stage:id,name,name_ar,color', 'assignee:id,name']);

        if ($request->boolean('archived')) $query->onlyTrashed();

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone_original', 'like', "%{$search}%")
                    ->orWhere('phone_normalized', 'like', "%{$search}%");
            });
        }
        if ($stage = $request->query('stage')) $query->where('pipeline_stage_id', $stage);
        if ($source = $request->query('source')) $query->where('source', $source);
        if ($intent = $request->query('intent')) $query->where('intent', $intent);
        if ($assignee = $request->query('assigned_to')) {
            $assignee === 'unassigned' ? $query->whereNull('assigned_to') : $query->where('assigned_to', $assignee);
        }

        $sort = in_array($request->query('sort'), ['name', 'created_at', 'next_follow_up_at', 'budget_max'], true) ? $request->query('sort') : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        return response()->json($query->orderBy($sort, $direction)->paginate(min((int) $request->query('per_page', 15), 50)));
    }

    public function store(Request $request, TenantContext $tenant, EgyptianPhoneNormalizer $phones, AuditLogger $audit)
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('create_leads'), 403);
        $data = $this->validateLead($request, $tenant);

        if ($membership->role === 'agent') {
            $data['assigned_to'] = $request->user()->id;
        } elseif (! empty($data['assigned_to'])) {
            abort_unless($membership->can('reassign_leads'), 403);
        }

        try { $data['phone_normalized'] = $phones->normalize($data['phone']); }
        catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['phone' => $e->getMessage()]); }

        $duplicate = Lead::where('company_id', $tenant->id())->where('phone_normalized', $data['phone_normalized'])->first();
        $stage = PipelineStage::where('company_id', $tenant->id())->orderBy('position')->firstOrFail();
        $attributes = $this->payload($data);
        $attributes['company_id'] = $tenant->id();
        $attributes['pipeline_stage_id'] = $data['pipeline_stage_id'] ?? $stage->id;
        $attributes['created_by'] = $request->user()->id;
        $attributes['phone_original'] = $data['phone'];
        $lead = Lead::create($attributes);

        LeadActivity::create([
            'company_id' => $tenant->id(), 'lead_id' => $lead->id, 'user_id' => $request->user()->id,
            'type' => 'created', 'title' => 'Lead created', 'occurred_at' => now(),
        ]);
        $audit->log($request, 'lead.created', $lead, [], Arr::except($lead->toArray(), ['notes']));

        return response()->json(['lead' => $lead->load(['stage', 'assignee']), 'duplicate_warning' => $duplicate ? ['id' => $duplicate->id, 'name' => $duplicate->name] : null], 201);
    }

    public function show(Request $request, TenantContext $tenant, int $lead)
    {
        $lead = $this->visibleQuery($request, $tenant)->whereKey($lead)->firstOrFail();
        return response()->json($lead->load(['stage', 'assignee:id,name,email', 'activities.user:id,name', 'tasks.assignee:id,name']));
    }

    public function matches(Request $request, TenantContext $tenant, int $lead, PropertyMatchingService $matching)
    {
        $lead = $this->visibleQuery($request, $tenant)->whereKey($lead)->firstOrFail();
        abort_unless($request->attributes->get('membership')->can('view_inventory'), 403);

        return response()->json([
            'lead_id' => $lead->id,
            'matches' => $matching->match($lead),
        ]);
    }

    public function update(Request $request, TenantContext $tenant, int $lead, EgyptianPhoneNormalizer $phones, AuditLogger $audit)
    {
        $lead = $this->visibleQuery($request, $tenant)->whereKey($lead)->firstOrFail();
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('update_leads') || ($membership->can('update_own_leads') && $lead->assigned_to === $request->user()->id), 403);
        $data = $this->validateLead($request, $tenant, true);
        if (array_key_exists('assigned_to', $data)) {
            abort_unless($membership->can('reassign_leads'), 403);
        }
        if (isset($data['phone'])) {
            try { $data['phone_normalized'] = $phones->normalize($data['phone']); }
            catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['phone' => $e->getMessage()]); }
            $data['phone_original'] = $data['phone'];
        }

        $old = $lead->getAttributes();
        $lead->fill($this->payload($data));
        if (isset($data['phone_original'])) $lead->phone_original = $data['phone_original'];
        if (isset($data['phone_normalized'])) $lead->phone_normalized = $data['phone_normalized'];
        $lead->save();

        $changes = $lead->getChanges();
        if ($changes) {
            $assignmentChanged = array_key_exists('assigned_to', $changes);
            LeadActivity::create([
                'company_id' => $tenant->id(), 'lead_id' => $lead->id, 'user_id' => $request->user()->id,
                'type' => $assignmentChanged ? 'assignment_changed' : (isset($changes['pipeline_stage_id']) ? 'stage_changed' : 'updated'),
                'title' => $assignmentChanged ? 'Lead assignment changed' : (isset($changes['pipeline_stage_id']) ? 'Pipeline stage changed' : 'Lead details updated'),
                'metadata' => Arr::except($changes, ['updated_at']), 'occurred_at' => now(),
            ]);
            $audit->log($request, 'lead.updated', $lead, Arr::only($old, array_keys($changes)), $changes);
        }
        return response()->json($lead->fresh()->load(['stage', 'assignee']));
    }

    public function addActivity(Request $request, TenantContext $tenant, int $lead, AuditLogger $audit)
    {
        $lead = $this->visibleQuery($request, $tenant)->whereKey($lead)->firstOrFail();
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('update_leads') || ($membership->can('update_own_leads') && $lead->assigned_to === $request->user()->id), 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(['note', 'call', 'whatsapp', 'email', 'meeting'])],
            'title' => ['required', 'string', 'max:190'], 'description' => ['nullable', 'string', 'max:5000'],
        ]);
        $activity = $lead->activities()->create($data + ['company_id' => $tenant->id(), 'user_id' => $request->user()->id, 'occurred_at' => now()]);
        if (in_array($data['type'], ['call', 'whatsapp', 'email', 'meeting'], true)) $lead->update(['last_contacted_at' => now()]);
        $audit->log($request, 'lead.activity_created', $lead, [], ['type' => $data['type']]);
        return response()->json($activity->load('user:id,name'), 201);
    }

    public function archive(Request $request, TenantContext $tenant, int $lead, AuditLogger $audit)
    {
        $lead = $this->visibleQuery($request, $tenant)->whereKey($lead)->firstOrFail();
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('archive_leads'), 403);

        $lead->delete();
        LeadActivity::create([
            'company_id' => $tenant->id(), 'lead_id' => $lead->id, 'user_id' => $request->user()->id,
            'type' => 'archived', 'title' => 'Lead archived', 'occurred_at' => now(),
        ]);
        $audit->log($request, 'lead.archived', $lead, ['deleted_at' => null], ['deleted_at' => $lead->deleted_at]);

        return response()->json(['message' => 'Lead archived.']);
    }

    public function bulk(Request $request, TenantContext $tenant, AuditLogger $audit)
    {
        $membership = $request->attributes->get('membership');
        $data = $request->validate([
            'action' => ['required', Rule::in(['archive', 'assign'])],
            'lead_ids' => ['required', 'array', 'min:1', 'max:100'],
            'lead_ids.*' => ['required', 'integer', 'distinct', Rule::exists('leads', 'id')->where('company_id', $tenant->id())->whereNull('deleted_at')],
            'assigned_to' => ['required_if:action,assign', 'nullable', 'integer', Rule::exists('company_memberships', 'user_id')->where('company_id', $tenant->id())->where('status', 'active')],
        ]);

        if ($data['action'] === 'archive') abort_unless($membership->can('archive_leads'), 403);
        if ($data['action'] === 'assign') abort_unless($membership->can('reassign_leads'), 403);

        $leads = $this->visibleQuery($request, $tenant)->whereIn('id', $data['lead_ids'])->get();
        abort_unless($leads->count() === count($data['lead_ids']), 404);

        DB::transaction(function () use ($data, $leads, $request, $tenant, $audit) {
            foreach ($leads as $lead) {
                if ($data['action'] === 'archive') {
                    $lead->delete();
                    LeadActivity::create([
                        'company_id' => $tenant->id(), 'lead_id' => $lead->id, 'user_id' => $request->user()->id,
                        'type' => 'archived', 'title' => 'Lead archived', 'occurred_at' => now(),
                    ]);
                    $audit->log($request, 'lead.archived', $lead, ['deleted_at' => null], ['deleted_at' => $lead->deleted_at, 'bulk' => true]);
                    continue;
                }

                $previousAssignee = $lead->assigned_to;
                $lead->update(['assigned_to' => $data['assigned_to']]);
                LeadActivity::create([
                    'company_id' => $tenant->id(), 'lead_id' => $lead->id, 'user_id' => $request->user()->id,
                    'type' => 'assignment_changed', 'title' => 'Lead assignment changed',
                    'metadata' => ['from' => $previousAssignee, 'to' => $lead->assigned_to, 'bulk' => true], 'occurred_at' => now(),
                ]);
                $audit->log($request, 'lead.updated', $lead, ['assigned_to' => $previousAssignee], ['assigned_to' => $lead->assigned_to, 'bulk' => true]);
            }
        });

        return response()->json(['message' => 'Bulk lead action completed.', 'updated_count' => $leads->count()]);
    }

    public function restore(Request $request, TenantContext $tenant, int $lead, AuditLogger $audit)
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('archive_leads'), 403);
        $lead = $this->visibleQuery($request, $tenant)->onlyTrashed()->whereKey($lead)->firstOrFail();
        $deletedAt = $lead->deleted_at;
        $lead->restore();
        LeadActivity::create([
            'company_id' => $tenant->id(), 'lead_id' => $lead->id, 'user_id' => $request->user()->id,
            'type' => 'restored', 'title' => 'Lead restored', 'occurred_at' => now(),
        ]);
        $audit->log($request, 'lead.restored', $lead, ['deleted_at' => $deletedAt], ['deleted_at' => null]);

        return response()->json($lead->fresh()->load(['stage', 'assignee']));
    }

    private function visibleQuery(Request $request, TenantContext $tenant)
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('view_all_leads') || $membership->can('view_own_leads'), 403);
        $query = Lead::where('company_id', $tenant->id());
        if (! $membership->can('view_all_leads')) {
            $query->where(function ($q) use ($request) {
                $q->where('assigned_to', $request->user()->id)->orWhere('created_by', $request->user()->id);
            });
        }
        return $query;
    }

    private function validateLead(Request $request, TenantContext $tenant, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:160'], 'phone' => [$required, 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'], 'source' => ['nullable', 'string', 'max:50'],
            'intent' => ['nullable', Rule::in(['buy', 'rent', 'sell'])],
            'pipeline_stage_id' => ['nullable', Rule::exists('pipeline_stages', 'id')->where('company_id', $tenant->id())],
            'assigned_to' => ['nullable', Rule::exists('company_memberships', 'user_id')->where('company_id', $tenant->id())->where('status', 'active')],
            'budget_min' => ['nullable', 'integer', 'min:0'], 'budget_max' => ['nullable', 'integer', 'min:0'],
            'preferred_locations' => ['nullable', 'array', 'max:10'], 'preferred_locations.*' => ['string', 'max:100'],
            'property_type' => ['nullable', 'string', 'max:40'], 'bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'notes' => ['nullable', 'string', 'max:10000'], 'next_follow_up_at' => ['nullable', 'date'],
        ]);

        if (isset($data['budget_min'], $data['budget_max']) && $data['budget_max'] < $data['budget_min']) {
            throw ValidationException::withMessages(['budget_max' => 'The maximum budget must be greater than or equal to the minimum budget.']);
        }

        return $data;
    }

    private function payload(array $data): array
    {
        if (array_key_exists('phone', $data)) unset($data['phone']);
        return Arr::only($data, ['name', 'email', 'source', 'intent', 'pipeline_stage_id', 'assigned_to', 'budget_min', 'budget_max', 'preferred_locations', 'property_type', 'bedrooms', 'notes', 'next_follow_up_at', 'phone_original', 'phone_normalized']);
    }
}
