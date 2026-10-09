<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealCommission;
use App\Models\DealCommissionDocument;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CommissionController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('view_commissions') || $membership->can('view_own_commissions'), 403);

        $query = DealCommission::query()->where('company_id', $tenant->id());
        if (! $membership->can('view_commissions')) $query->where('company_membership_id', $membership->id);
        if ($status = $request->query('status')) $query->where('status', $status);
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('payee_name', 'like', "%{$search}%")
                ->orWhere('unit_reference', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%"));
        }

        $page = $query->with(['deal.propertyListing', 'currentSignedDocument'])->latest()->paginate(min(max((int) $request->query('per_page', 25), 1), 100));
        $page->getCollection()->transform(fn (DealCommission $entry) => $this->serialize($entry));
        return response()->json($page);
    }

    public function show(Request $request, TenantContext $tenant, int $commission)
    {
        $entry = $this->visibleCommission($request, $tenant, $commission);
        return response()->json($this->serialize($entry->load(['deal.propertyListing', 'currentSignedDocument', 'membership.user:id,name,email'])));
    }

    public function uploadSignedDocument(Request $request, TenantContext $tenant, int $commission, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('manage_commissions'), 403);
        $entry = DealCommission::where('company_id', $tenant->id())->findOrFail($commission);
        abort_unless($entry->status === 'approved', 422, 'Approve this commission before attaching its signed payment order.');
        $data = $request->validate([
            'signed_document' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);
        $file = $data['signed_document'];
        $path = $file->storeAs(
            "companies/{$tenant->id()}/commissions/{$entry->id}/signed",
            Str::uuid().'.'.$file->extension(),
            'private'
        );

        try {
            $document = DB::transaction(function () use ($entry, $file, $path, $request, $tenant) {
                DealCommissionDocument::where('company_id', $tenant->id())
                    ->where('deal_commission_id', $entry->id)->where('is_current', true)
                    ->update(['is_current' => false]);
                return DealCommissionDocument::create([
                    'company_id' => $tenant->id(), 'deal_commission_id' => $entry->id,
                    'uploaded_by' => $request->user()->id,
                    'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'storage_path' => $path, 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
                    'is_current' => true,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        $audit->log($request, 'deal.commission_signed_document_uploaded', $document, [], [
            'commission_id' => $entry->id, 'company_id' => $tenant->id(), 'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes, 'original_name' => $document->original_name,
        ]);

        return response()->json($this->serialize($entry->fresh()->load(['deal.propertyListing', 'currentSignedDocument', 'membership.user:id,name,email'])), 201);
    }

    public function downloadSignedDocument(Request $request, TenantContext $tenant, int $commission)
    {
        $entry = $this->visibleCommission($request, $tenant, $commission);
        $document = DealCommissionDocument::where('company_id', $tenant->id())
            ->where('deal_commission_id', $entry->id)->where('is_current', true)->latest()->firstOrFail();

        return Storage::disk('private')->download($document->storage_path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function visibleCommission(Request $request, TenantContext $tenant, int $id): DealCommission
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('view_commissions') || $membership->can('view_own_commissions'), 403);
        $query = DealCommission::where('company_id', $tenant->id());
        if (! $membership->can('view_commissions')) $query->where('company_membership_id', $membership->id);
        return $query->findOrFail($id);
    }

    private function serialize(DealCommission $entry): array
    {
        $entry->loadMissing(['deal.propertyListing', 'currentSignedDocument', 'membership.user']);
        $document = $entry->currentSignedDocument;
        $rate = $entry->rate_basis_points;

        return [
            'id' => $entry->id, 'deal_id' => $entry->deal_id,
            'payee_name' => $entry->payee_name, 'payee_user_id' => $entry->membership?->user_id,
            'payee_email' => $entry->membership?->user?->email,
            'unit_reference' => $entry->unit_reference ?: 'Deal #'.$entry->deal_id,
            'property_listing_id' => $entry->property_listing_id,
            'amount_minor_units' => $entry->amount_minor_units,
            'calculation_type' => $entry->calculation_type,
            'rate_basis_points' => $rate,
            'rate_percent' => $rate === null ? null : intdiv($rate, 100).'.'.str_pad((string) ($rate % 100), 2, '0', STR_PAD_LEFT),
            'base_amount_minor_units' => $entry->base_amount_minor_units,
            'currency' => $entry->currency, 'status' => $entry->status,
            'due_on' => $entry->due_on?->format('Y-m-d'), 'paid_at' => $entry->paid_at,
            'reference' => $entry->reference, 'notes' => $entry->notes,
            'created_at' => $entry->created_at,
            'deal' => $entry->deal ? [
                'status' => $entry->deal->status,
                'agreed_price_minor_units' => $entry->deal->agreed_price_minor_units,
                'property' => $entry->deal->propertyListing ? [
                    'reference_code' => $entry->deal->propertyListing->reference_code,
                    'title' => $entry->deal->propertyListing->title,
                    'location' => $entry->deal->propertyListing->preferredLocation?->name ?? $entry->deal->propertyListing->location,
                ] : null,
            ] : null,
            'signed_document' => $document ? [
                'id' => $document->id, 'original_name' => $document->original_name,
                'mime_type' => $document->mime_type, 'size_bytes' => $document->size_bytes,
                'uploaded_at' => $document->created_at,
                'url' => route('commissions.signed-document', ['commission' => $entry->id]),
            ] : null,
        ];
    }
}
