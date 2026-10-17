<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\PropertyListing;
use App\Models\PropertyViewing;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PropertyViewingController extends Controller
{
    public function store(Request $request, TenantContext $tenant, int $lead, AuditLogger $audit)
    {
        $lead = $this->visibleLead($request, $tenant, $lead);
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('update_leads') || ($membership->can('update_own_leads') && (int) $lead->assigned_to === (int) $request->user()->id), 403);
        abort_unless($membership->can('view_inventory'), 403);

        $data = $request->validate([
            'property_listing_id' => ['required', Rule::exists('property_listings', 'id')->where('company_id', $tenant->id())->where('status', 'available')->whereNull('deleted_at')],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'buyer_feedback' => ['nullable', 'string', 'max:3000'],
        ]);
        $listing = PropertyListing::where('company_id', $tenant->id())->whereKey($data['property_listing_id'])->firstOrFail();
        $viewing = PropertyViewing::create($data + [
            'company_id' => $tenant->id(), 'lead_id' => $lead->id,
            'scheduled_by' => $request->user()->id, 'status' => 'scheduled',
            'listing_title' => $listing->title, 'listing_reference_code' => $listing->reference_code,
            'listing_location' => $listing->location, 'listing_price_minor_units' => $listing->price_minor_units,
        ]);
        LeadActivity::create([
            'company_id' => $tenant->id(), 'lead_id' => $lead->id, 'user_id' => $request->user()->id,
            'type' => 'meeting', 'title' => 'Property viewing scheduled',
            'description' => $listing->title.' · '. $viewing->scheduled_at->format('j M Y, g:i A'),
            'metadata' => ['viewing_id' => $viewing->id, 'property_listing_id' => $listing->id], 'occurred_at' => now(),
        ]);
        $audit->log($request, 'property_viewing.scheduled', $viewing, [], $viewing->toArray());

        return response()->json($viewing->load('scheduler:id,name'), 201);
    }

    public function update(Request $request, TenantContext $tenant, int $lead, int $viewing, AuditLogger $audit)
    {
        $lead = $this->visibleLead($request, $tenant, $lead);
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('update_leads') || ($membership->can('update_own_leads') && (int) $lead->assigned_to === (int) $request->user()->id), 403);
        $viewing = PropertyViewing::where('company_id', $tenant->id())->where('lead_id', $lead->id)->whereKey($viewing)->firstOrFail();
        abort_unless($viewing->status === 'scheduled', 409);

        $data = $request->validate([
            'status' => ['required', Rule::in(['completed', 'cancelled', 'no_show'])],
            'buyer_rating' => ['required_if:status,completed', 'nullable', 'integer', 'min:1', 'max:5'],
            'buyer_feedback' => ['nullable', 'string', 'max:3000'],
        ]);
        $previous = $viewing->only(['status', 'buyer_rating', 'buyer_feedback']);
        $viewing->update($data);
        $event = $data['status'] === 'completed' ? 'Viewing completed' : ($data['status'] === 'no_show' ? 'Buyer missed property viewing' : 'Property viewing cancelled');
        LeadActivity::create([
            'company_id' => $tenant->id(), 'lead_id' => $lead->id, 'user_id' => $request->user()->id,
            'type' => 'meeting', 'title' => $event,
            'description' => $viewing->listing_title.($viewing->buyer_feedback ? ' · '.$viewing->buyer_feedback : ''),
            'metadata' => ['viewing_id' => $viewing->id, 'status' => $viewing->status, 'buyer_rating' => $viewing->buyer_rating], 'occurred_at' => now(),
        ]);
        $audit->log($request, 'property_viewing.outcome_recorded', $viewing, $previous, $viewing->only(['status', 'buyer_rating', 'buyer_feedback']));

        return response()->json($viewing->load('scheduler:id,name'));
    }

    private function visibleLead(Request $request, TenantContext $tenant, int $id): Lead
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('view_all_leads') || $membership->can('view_own_leads'), 403);
        $query = Lead::where('company_id', $tenant->id())->whereKey($id);
        if (! $membership->can('view_all_leads')) {
            $query->where(fn ($builder) => $builder->where('assigned_to', $request->user()->id)->orWhere('created_by', $request->user()->id));
        }
        return $query->firstOrFail();
    }
}
