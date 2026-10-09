<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\Lead;
use App\Models\PropertyListing;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DealController extends Controller
{
    private const STATUSES = ['negotiation', 'reserved', 'contracted', 'closed_won', 'closed_lost'];

    public function index(Request $request, TenantContext $tenant)
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('view_deals') || $membership->can('view_own_deals'), 403);

        $query = Deal::where('company_id', $tenant->id())->with([
            'lead:id,name,phone_original,assigned_to,created_by',
            'propertyListing:id,reference_code,title,location,status',
            'creator:id,name',
            'salespersonMembership:id,user_id',
        ]);
        if (! $membership->can('view_deals')) {
            $query->whereHas('lead', fn ($leads) => $this->whereLeadVisibleTo($leads, $request));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($deals) use ($search) {
                $deals->whereHas('lead', fn ($leads) => $leads->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('propertyListing', fn ($listings) => $listings->where('title', 'like', "%{$search}%")->orWhere('reference_code', 'like', "%{$search}%"));
            });
        }

        $page = $query->latest()->paginate(min(max((int) $request->query('per_page', 15), 1), 50));
        $page->getCollection()->transform(fn (Deal $deal) => $this->serialize($deal));
        return response()->json($page);
    }

    public function store(Request $request, TenantContext $tenant, AuditLogger $audit)
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('manage_deals') || $membership->can('create_deals'), 403);
        $data = $this->validateDeal($request, $tenant);
        $lead = Lead::where('company_id', $tenant->id())->findOrFail($data['lead_id']);
        $this->authorizeLead($request, $lead);
        $this->authorizeListing($tenant, $data['property_listing_id'] ?? null);

        if (isset($data['agreed_price_egp'])) {
            $data['agreed_price_minor_units'] = $this->toMinorUnits($data['agreed_price_egp']);
        } else {
            $data['agreed_price_minor_units'] = null;
        }
        unset($data['agreed_price_egp']);
        if (($data['status'] ?? null) === 'closed_won') {
            $data += $this->salesAttribution($lead, $tenant->id(), $request->user()->id);
            $data['closed_at'] = now();
        }
        $deal = Deal::create($data + ['company_id' => $tenant->id(), 'created_by' => $request->user()->id, 'currency' => 'EGP']);
        $values = $deal->only(['lead_id', 'property_listing_id', 'status', 'expected_close_date', 'agreed_price_minor_units']);
        $this->recordActivity($request, $deal, 'created', [], $values);
        $audit->log($request, 'deal.created', $deal, [], $values);

        return response()->json($this->serialize($deal->load(['lead', 'propertyListing', 'creator'])), 201);
    }

    public function show(Request $request, TenantContext $tenant, int $deal)
    {
        $deal = $this->findVisibleDeal($request, $tenant, $deal);
        return response()->json($this->serialize($deal->load(['lead', 'propertyListing', 'creator', 'salespersonMembership:id,user_id', 'activities.user:id,name']), true));
    }

    public function update(Request $request, TenantContext $tenant, int $deal, AuditLogger $audit)
    {
        $deal = $this->findVisibleDeal($request, $tenant, $deal);
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('manage_deals') || ($membership->can('update_own_deals') && $this->leadIsVisible($request, $deal->lead)), 403);

        $data = $this->validateDeal($request, $tenant, true);
        if (array_key_exists('property_listing_id', $data)) {
            $this->authorizeListing($tenant, $data['property_listing_id']);
        }
        if (array_key_exists('agreed_price_egp', $data)) {
            $data['agreed_price_minor_units'] = $data['agreed_price_egp'] === null ? null : $this->toMinorUnits($data['agreed_price_egp']);
            unset($data['agreed_price_egp']);
        }

        $old = $deal->getAttributes();
        if (($data['status'] ?? null) === 'closed_won' && $deal->status !== 'closed_won') {
            $data += $this->salesAttribution($deal->lead, $tenant->id(), $request->user()->id);
            $data['closed_at'] = now();
        } elseif (array_key_exists('status', $data) && $data['status'] !== 'closed_won' && $deal->status === 'closed_won') {
            $data['closed_at'] = null;
        }
        $deal->fill($data)->save();
        $changes = $deal->getChanges();
        if ($changes) {
            $loggedKeys = ['property_listing_id', 'status', 'expected_close_date', 'agreed_price_minor_units'];
            $oldValues = array_intersect_key($old, array_flip(array_intersect(array_keys($changes), $loggedKeys)));
            $newValues = array_intersect_key($changes, array_flip($loggedKeys));
            if (array_key_exists('notes', $changes)) $newValues['notes_changed'] = true;
            $event = array_key_exists('status', $changes) ? 'status_changed' : (array_key_exists('agreed_price_minor_units', $changes) ? 'amount_changed' : 'updated');
            $this->recordActivity($request, $deal, $event, $oldValues, $newValues);
            $audit->log($request, 'deal.updated', $deal, $oldValues, $newValues);
        }

        return response()->json($this->serialize($deal->fresh()->load(['lead', 'propertyListing', 'creator'])));
    }

    private function validateDeal(Request $request, TenantContext $tenant, bool $partial = false): array
    {
        return $request->validate([
            'lead_id' => [$partial ? 'prohibited' : 'required', 'integer', Rule::exists('leads', 'id')->where('company_id', $tenant->id())],
            'property_listing_id' => ['sometimes', 'nullable', 'integer', Rule::exists('property_listings', 'id')->where('company_id', $tenant->id())],
            'status' => [$partial ? 'sometimes' : 'required', Rule::in(self::STATUSES)],
            'expected_close_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'agreed_price_egp' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);
    }

    private function authorizeListing(TenantContext $tenant, mixed $listingId): void
    {
        if ($listingId !== null) PropertyListing::where('company_id', $tenant->id())->findOrFail($listingId);
    }

    private function salesAttribution(Lead $lead, int $companyId, int $fallbackUserId): array
    {
        $userId = $lead->assigned_to ?: $lead->created_by ?: $fallbackUserId;
        $membership = \App\Models\CompanyMembership::with('user:id,name')
            ->where('company_id', $companyId)->where('user_id', $userId)->where('status', 'active')->first();

        return [
            'salesperson_membership_id' => $membership?->id,
            'salesperson_name' => $membership?->user?->name,
        ];
    }

    private function findVisibleDeal(Request $request, TenantContext $tenant, int $id): Deal
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('view_deals') || $membership->can('view_own_deals'), 403);
        $query = Deal::where('company_id', $tenant->id())->with('lead');
        if (! $membership->can('view_deals')) {
            $query->whereHas('lead', fn ($leads) => $this->whereLeadVisibleTo($leads, $request));
        }
        return $query->findOrFail($id);
    }

    private function authorizeLead(Request $request, Lead $lead): void
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('view_all_leads') || $this->leadIsVisible($request, $lead), 404);
    }

    private function leadIsVisible(Request $request, Lead $lead): bool
    {
        return $lead->assigned_to === $request->user()->id || $lead->created_by === $request->user()->id;
    }

    private function whereLeadVisibleTo($query, Request $request): void
    {
        $query->where(function ($leads) use ($request) {
            $leads->where('assigned_to', $request->user()->id)->orWhere('created_by', $request->user()->id);
        });
    }

    private function toMinorUnits(string $amount): int
    {
        [$pounds, $piastres] = array_pad(explode('.', $amount, 2), 2, '');
        $minor = ((int) $pounds * 100) + (int) str_pad($piastres, 2, '0');
        if ($minor < 1) throw ValidationException::withMessages(['agreed_price_egp' => 'The agreed price must be greater than zero.']);
        return $minor;
    }

    private function recordActivity(Request $request, Deal $deal, string $event, array $old, array $new): void
    {
        DealActivity::create([
            'company_id' => $deal->company_id, 'deal_id' => $deal->id, 'user_id' => $request->user()->id,
            'event' => $event, 'old_values' => $old ?: null, 'new_values' => $new ?: null, 'occurred_at' => now(),
        ]);
    }

    private function serialize(Deal $deal, bool $includeHistory = false): array
    {
        $result = [
            'id' => $deal->id, 'status' => $deal->status,
            'expected_close_date' => $deal->expected_close_date?->format('Y-m-d'),
            'agreed_price_egp' => $deal->agreed_price_minor_units === null ? null : $this->fromMinorUnits($deal->agreed_price_minor_units),
            'agreed_price_minor_units' => $deal->agreed_price_minor_units, 'currency' => $deal->currency,
            'notes' => $deal->notes, 'created_at' => $deal->created_at,
            'closed_at' => $deal->closed_at, 'salesperson_name' => $deal->salesperson_name,
            'salesperson_membership_id' => $deal->salesperson_membership_id,
            'salesperson_user_id' => $deal->salespersonMembership?->user_id,
            'lead' => $deal->lead ? ['id' => $deal->lead->id, 'name' => $deal->lead->name, 'phone' => $deal->lead->phone_original, 'assigned_to' => $deal->lead->assigned_to, 'created_by' => $deal->lead->created_by] : null,
            'property' => $deal->propertyListing ? ['id' => $deal->propertyListing->id, 'reference_code' => $deal->propertyListing->reference_code, 'title' => $deal->propertyListing->title, 'location' => $deal->propertyListing->preferredLocation?->name ?? $deal->propertyListing->location, 'status' => $deal->propertyListing->status] : null,
            'creator' => $deal->creator,
        ];
        if ($includeHistory) {
            $result['activities'] = $deal->activities->map(fn (DealActivity $activity) => [
                'id' => $activity->id, 'event' => $activity->event, 'old_values' => $activity->old_values,
                'new_values' => $activity->new_values, 'occurred_at' => $activity->occurred_at, 'user' => $activity->user,
            ])->values();
        }
        return $result;
    }

    private function fromMinorUnits(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
