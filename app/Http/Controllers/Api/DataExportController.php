<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\Deal;
use App\Models\DealCommission;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\PropertyListing;
use App\Models\RealEstateDeveloper;
use App\Services\AuditLogger;
use App\Services\ExcelExportService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DataExportController extends Controller
{
    public function leads(Request $request, TenantContext $tenant, ExcelExportService $excel, AuditLogger $audit)
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('view_all_leads') || $membership->can('view_own_leads'), 403);

        $query = Lead::where('company_id', $tenant->id())->with(['stage:id,name', 'assignee:id,name']);
        if (! $membership->can('view_all_leads')) {
            $query->where(fn ($leads) => $leads->where('assigned_to', $request->user()->id)->orWhere('created_by', $request->user()->id));
        }
        if ($request->boolean('archived')) $query->onlyTrashed();
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($leads) => $leads->where('name', 'like', "%{$search}%")
                ->orWhere('phone_original', 'like', "%{$search}%")->orWhere('phone_normalized', 'like', "%{$search}%"));
        }
        if ($stage = $request->query('stage')) $query->where('pipeline_stage_id', $stage);
        if ($source = $request->query('source')) $query->where('source', $source);
        if ($intent = $request->query('intent')) $query->where('intent', $intent);
        if ($assignee = $request->query('assigned_to')) {
            $assignee === 'unassigned' ? $query->whereNull('assigned_to') : $query->where('assigned_to', $assignee);
        }
        $sort = in_array($request->query('sort'), ['name', 'created_at', 'next_follow_up_at', 'budget_max'], true) ? $request->query('sort') : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $rows = $query->orderBy($sort, $direction)->get()->map(fn (Lead $lead) => [
            'id' => $lead->id, 'name' => $lead->name, 'phone' => $lead->phone_original,
            'normalized_phone' => $lead->phone_normalized, 'email' => $lead->email,
            'stage' => $lead->stage?->name, 'source' => $lead->source, 'intent' => $lead->intent,
            'property_type' => $lead->property_type, 'bedrooms' => $lead->bedrooms,
            'locations' => implode(', ', $lead->preferred_locations ?? []),
            'budget_min' => $lead->budget_min, 'budget_max' => $lead->budget_max,
            'owner' => $lead->assignee?->name, 'next_follow_up' => $lead->next_follow_up_at,
            'last_contacted' => $lead->last_contacted_at, 'created_at' => $lead->created_at,
            'archived_at' => $lead->deleted_at,
        ]);

        $this->audit($request, $tenant, $audit, 'leads', $rows->count());
        return $excel->download($tenant->id(), 'leads', 'Leads', [
            $this->column('id', 'ID', 'integer', 10), $this->column('name', 'Lead name', 'string', 24),
            $this->column('phone', 'Original phone', 'string', 18), $this->column('normalized_phone', 'Normalized phone', 'string', 18),
            $this->column('email', 'Email', 'string', 26), $this->column('stage', 'Stage', 'string', 18),
            $this->column('source', 'Source', 'string', 16), $this->column('intent', 'Intent', 'string', 12),
            $this->column('property_type', 'Property type', 'string', 18), $this->column('bedrooms', 'Bedrooms', 'integer', 11),
            $this->column('locations', 'Preferred locations', 'string', 30), $this->column('budget_min', 'Minimum budget (EGP)', 'integer', 20),
            $this->column('budget_max', 'Maximum budget (EGP)', 'integer', 20), $this->column('owner', 'Owner', 'string', 20),
            $this->column('next_follow_up', 'Next follow-up', 'datetime', 20), $this->column('last_contacted', 'Last contacted', 'datetime', 20),
            $this->column('created_at', 'Created', 'datetime', 20), $this->column('archived_at', 'Archived', 'datetime', 20),
        ], $rows);
    }

    public function inventory(Request $request, TenantContext $tenant, ExcelExportService $excel, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('view_inventory'), 403);
        $query = PropertyListing::where('company_id', $tenant->id())->with(['preferredLocation', 'developer:id,name', 'lister:id,name']);
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($listings) use ($search, $tenant) {
                $listings->where('title', 'like', "%{$search}%")->orWhere('reference_code', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhereHas('preferredLocation', fn ($locations) => $locations->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('developer', fn ($developers) => $developers->where('company_id', $tenant->id())->where('name', 'like', "%{$search}%"));
            });
        }
        foreach (['status', 'listing_type', 'property_type'] as $filter) {
            if ($value = $request->query($filter)) $query->where($filter, $value);
        }
        if ($location = $request->query('location')) {
            $query->where(fn ($listings) => $listings->where('location', 'like', "%{$location}%")
                ->orWhereHas('preferredLocation', fn ($locations) => $locations->where('name', 'like', "%{$location}%")));
        }
        $sort = in_array($request->query('sort'), ['created_at', 'price_minor_units', 'title'], true) ? $request->query('sort') : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $rows = $query->orderBy($sort, $direction)->get()->map(fn (PropertyListing $listing) => [
            'reference' => $listing->reference_code, 'title' => $listing->title,
            'developer' => $listing->developer?->name ?: 'Independent', 'listing_type' => $listing->listing_type,
            'property_type' => $listing->property_type, 'status' => $listing->status,
            'location' => $listing->preferredLocation?->name ?? $listing->location, 'address' => $listing->address,
            'price' => $this->minorUnits($listing->price_minor_units), 'bedrooms' => $listing->bedrooms,
            'bathrooms' => $listing->bathrooms, 'area' => $listing->area_sqm,
            'listed_by' => $listing->lister?->name, 'created_at' => $listing->created_at,
        ]);
        $this->audit($request, $tenant, $audit, 'inventory', $rows->count());
        return $excel->download($tenant->id(), 'inventory', 'Inventory', [
            $this->column('reference', 'Reference', 'string', 16), $this->column('title', 'Property', 'string', 30),
            $this->column('developer', 'Developer', 'string', 24), $this->column('listing_type', 'Listing type', 'string', 14),
            $this->column('property_type', 'Property type', 'string', 18), $this->column('status', 'Status', 'string', 14),
            $this->column('location', 'Location', 'string', 24), $this->column('address', 'Address', 'string', 32),
            $this->column('price', 'Price', 'currency', 18), $this->column('bedrooms', 'Bedrooms', 'integer', 11),
            $this->column('bathrooms', 'Bathrooms', 'integer', 11), $this->column('area', 'Area (sqm)', 'decimal', 13),
            $this->column('listed_by', 'Listed by', 'string', 20), $this->column('created_at', 'Created', 'datetime', 20),
        ], $rows);
    }

    public function developers(Request $request, TenantContext $tenant, ExcelExportService $excel, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('view_inventory'), 403);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'active' => ['sometimes', 'boolean']]);
        $query = RealEstateDeveloper::where('company_id', $tenant->id())->withCount('listings')->orderBy('name');
        if (! empty($data['search'])) {
            $term = trim($data['search']);
            $query->where(fn ($developers) => $developers->where('name', 'like', "%{$term}%")
                ->orWhere('contact_name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
        }
        if (array_key_exists('active', $data)) $query->where('is_active', $request->boolean('active'));
        $rows = $query->get()->map(fn (RealEstateDeveloper $developer) => [
            'name' => $developer->name, 'status' => $developer->is_active ? 'Active' : 'Inactive',
            'listings' => $developer->listings_count, 'contact' => $developer->contact_name,
            'phone' => $developer->phone, 'email' => $developer->email, 'website' => $developer->website,
            'notes' => $developer->notes, 'created_at' => $developer->created_at,
        ]);
        $this->audit($request, $tenant, $audit, 'developers', $rows->count());
        return $excel->download($tenant->id(), 'developers', 'Developers', [
            $this->column('name', 'Developer', 'string', 28), $this->column('status', 'Status', 'string', 12),
            $this->column('listings', 'Linked properties', 'integer', 17), $this->column('contact', 'Contact person', 'string', 24),
            $this->column('phone', 'Phone', 'string', 18), $this->column('email', 'Email', 'string', 28),
            $this->column('website', 'Website', 'string', 30), $this->column('notes', 'Notes', 'string', 36),
            $this->column('created_at', 'Added', 'datetime', 20),
        ], $rows);
    }

    public function deals(Request $request, TenantContext $tenant, ExcelExportService $excel, AuditLogger $audit)
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('view_deals') || $membership->can('view_own_deals'), 403);
        $query = Deal::where('company_id', $tenant->id())->with(['lead:id,name,phone_original,assigned_to,created_by', 'propertyListing:id,reference_code,title', 'creator:id,name']);
        if (! $membership->can('view_deals')) {
            $query->whereHas('lead', fn ($leads) => $leads->where(fn ($visible) => $visible->where('assigned_to', $request->user()->id)->orWhere('created_by', $request->user()->id)));
        }
        if ($status = $request->query('status')) $query->where('status', $status);
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($deals) => $deals->whereHas('lead', fn ($leads) => $leads->where('name', 'like', "%{$search}%"))
                ->orWhereHas('propertyListing', fn ($listings) => $listings->where('title', 'like', "%{$search}%")->orWhere('reference_code', 'like', "%{$search}%")));
        }
        $rows = $query->latest()->get()->map(fn (Deal $deal) => [
            'id' => $deal->id, 'lead' => $deal->lead?->name, 'phone' => $deal->lead?->phone_original,
            'property_reference' => $deal->propertyListing?->reference_code, 'property' => $deal->propertyListing?->title,
            'status' => $deal->status, 'expected_close' => $deal->expected_close_date,
            'agreed_price' => $this->minorUnits($deal->agreed_price_minor_units),
            'amount_received' => $this->minorUnits($deal->amount_received_minor_units),
            'balance_due' => $deal->agreed_price_minor_units === null ? null : $this->minorUnits(max(0, $deal->agreed_price_minor_units - ($deal->amount_received_minor_units ?? 0))),
            'salesperson' => $deal->salesperson_name, 'payment_method' => $deal->payment_method,
            'payment_reference' => $deal->payment_reference, 'payment_received_on' => $deal->payment_received_on,
            'closed_at' => $deal->closed_at, 'created_by' => $deal->creator?->name, 'created_at' => $deal->created_at,
        ]);
        $this->audit($request, $tenant, $audit, 'deals', $rows->count());
        return $excel->download($tenant->id(), 'deals', 'Deals', [
            $this->column('id', 'Deal ID', 'integer', 10), $this->column('lead', 'Lead', 'string', 24),
            $this->column('phone', 'Phone', 'string', 18), $this->column('property_reference', 'Property reference', 'string', 18),
            $this->column('property', 'Property', 'string', 28), $this->column('status', 'Status', 'string', 16),
            $this->column('expected_close', 'Expected close', 'date', 16), $this->column('agreed_price', 'Agreed value', 'currency', 18),
            $this->column('amount_received', 'Amount received', 'currency', 19), $this->column('balance_due', 'Balance due', 'currency', 18),
            $this->column('salesperson', 'Salesperson', 'string', 20), $this->column('payment_method', 'Payment method', 'string', 18),
            $this->column('payment_reference', 'Payment reference', 'string', 22), $this->column('payment_received_on', 'Payment received', 'date', 18),
            $this->column('closed_at', 'Closed', 'datetime', 20), $this->column('created_by', 'Created by', 'string', 20),
            $this->column('created_at', 'Created', 'datetime', 20),
        ], $rows);
    }

    public function commissions(Request $request, TenantContext $tenant, ExcelExportService $excel, AuditLogger $audit)
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('view_commissions') || $membership->can('view_own_commissions'), 403);
        $query = DealCommission::where('company_id', $tenant->id())->with(['membership.user:id,name,email', 'currentSignedDocument']);
        if (! $membership->can('view_commissions')) $query->where('company_membership_id', $membership->id);
        if ($status = $request->query('status')) $query->where('status', $status);
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($commissions) => $commissions->where('payee_name', 'like', "%{$search}%")
                ->orWhere('unit_reference', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%"));
        }
        $rows = $query->latest()->get()->map(fn (DealCommission $commission) => [
            'deal_id' => $commission->deal_id, 'unit' => $commission->unit_reference,
            'payee' => $commission->payee_name, 'email' => $commission->membership?->user?->email,
            'calculation' => $commission->calculation_type,
            'rate' => $commission->rate_basis_points === null ? null : $commission->rate_basis_points / 10000,
            'base_amount' => $this->minorUnits($commission->base_amount_minor_units),
            'amount' => $this->minorUnits($commission->amount_minor_units), 'status' => $commission->status,
            'due_on' => $commission->due_on, 'paid_at' => $commission->paid_at,
            'reference' => $commission->reference, 'signed_order' => $commission->currentSignedDocument?->original_name,
            'created_at' => $commission->created_at,
        ]);
        $this->audit($request, $tenant, $audit, 'commissions', $rows->count());
        return $excel->download($tenant->id(), 'commissions', 'Commissions', [
            $this->column('deal_id', 'Deal ID', 'integer', 10), $this->column('unit', 'Unit reference', 'string', 20),
            $this->column('payee', 'Employee', 'string', 24), $this->column('email', 'Email', 'string', 28),
            $this->column('calculation', 'Calculation', 'string', 14), $this->column('rate', 'Rate', 'percent', 12),
            $this->column('base_amount', 'Base amount', 'currency', 18), $this->column('amount', 'Commission', 'currency', 18),
            $this->column('status', 'Status', 'string', 13), $this->column('due_on', 'Due date', 'date', 14),
            $this->column('paid_at', 'Paid', 'datetime', 20), $this->column('reference', 'Reference', 'string', 20),
            $this->column('signed_order', 'Signed order', 'string', 28), $this->column('created_at', 'Created', 'datetime', 20),
        ], $rows);
    }

    public function tasks(Request $request, TenantContext $tenant, ExcelExportService $excel, AuditLogger $audit)
    {
        $filters = $request->validate([
            'bucket' => ['nullable', Rule::in(['all', 'overdue', 'today', 'upcoming'])],
            'open' => ['sometimes', 'boolean'],
        ]);
        $query = FollowUpTask::where('company_id', $tenant->id())->with(['lead:id,name', 'assignee:id,name']);
        if ($request->attributes->get('membership')->role === 'agent') $query->where('assigned_to', $request->user()->id);
        $isOpen = $request->boolean('open', true);
        if ($isOpen) $query->whereNull('completed_at');
        $bucket = $filters['bucket'] ?? 'all';
        if ($isOpen && $bucket === 'overdue') $query->where('due_at', '<', now()->startOfDay());
        if ($isOpen && $bucket === 'today') $query->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]);
        if ($isOpen && $bucket === 'upcoming') $query->where('due_at', '>', now()->endOfDay());
        $rows = $query->orderByRaw('completed_at is null desc')->orderBy('due_at')->get()->map(fn (FollowUpTask $task) => [
            'title' => $task->title, 'lead' => $task->lead?->name, 'assignee' => $task->assignee?->name,
            'priority' => $task->priority, 'status' => $task->completed_at ? 'Completed' : 'Open',
            'due_at' => $task->due_at, 'completed_at' => $task->completed_at,
            'description' => $task->description, 'created_at' => $task->created_at,
        ]);
        $this->audit($request, $tenant, $audit, 'tasks', $rows->count());
        return $excel->download($tenant->id(), 'tasks', 'Tasks', [
            $this->column('title', 'Task', 'string', 30), $this->column('lead', 'Lead', 'string', 22),
            $this->column('assignee', 'Assignee', 'string', 22), $this->column('priority', 'Priority', 'string', 12),
            $this->column('status', 'Status', 'string', 12), $this->column('due_at', 'Due', 'datetime', 20),
            $this->column('completed_at', 'Completed', 'datetime', 20), $this->column('description', 'Description', 'string', 40),
            $this->column('created_at', 'Created', 'datetime', 20),
        ], $rows);
    }

    public function team(Request $request, TenantContext $tenant, ExcelExportService $excel, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('view_team'), 403);
        $members = CompanyMembership::where('company_id', $tenant->id())->with('user:id,name,email')->get()->map(fn (CompanyMembership $membership) => [
            'record_type' => 'Member', 'name' => $membership->user?->name, 'email' => $membership->user?->email,
            'role' => $membership->role, 'status' => $membership->status, 'joined_at' => $membership->joined_at,
            'expires_at' => null, 'created_at' => $membership->created_at,
        ]);
        $invitations = CompanyInvitation::where('company_id', $tenant->id())->whereNull('accepted_at')->whereNull('revoked_at')->get()->map(fn (CompanyInvitation $invitation) => [
            'record_type' => 'Invitation', 'name' => $invitation->name, 'email' => $invitation->email,
            'role' => $invitation->role, 'status' => $invitation->expires_at->isPast() ? 'expired' : 'pending',
            'joined_at' => null, 'expires_at' => $invitation->expires_at, 'created_at' => $invitation->created_at,
        ]);
        $rows = $members->concat($invitations);
        $this->audit($request, $tenant, $audit, 'team', $rows->count());
        return $excel->download($tenant->id(), 'team', 'Team', [
            $this->column('record_type', 'Record type', 'string', 15), $this->column('name', 'Name', 'string', 24),
            $this->column('email', 'Email', 'string', 28), $this->column('role', 'Role', 'string', 14),
            $this->column('status', 'Status', 'string', 14), $this->column('joined_at', 'Joined', 'datetime', 20),
            $this->column('expires_at', 'Invitation expires', 'datetime', 20), $this->column('created_at', 'Added / invited', 'datetime', 20),
        ], $rows);
    }

    private function audit(Request $request, TenantContext $tenant, AuditLogger $audit, string $resource, int $rowCount): void
    {
        $filters = $request->except(['page', 'per_page']);
        $audit->log($request, "export.{$resource}", $tenant->company(), [], [
            'company_id' => $tenant->id(), 'format' => 'xlsx', 'row_count' => $rowCount, 'filters' => $filters,
        ]);
    }

    private function minorUnits(?int $minor): ?string
    {
        if ($minor === null) return null;
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function column(string $key, string $label, string $type = 'string', int $width = 16): array
    {
        return compact('key', 'label', 'type', 'width');
    }
}
