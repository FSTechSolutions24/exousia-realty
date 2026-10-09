<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyMembership;
use App\Models\Deal;
use App\Models\DealCommission;
use App\Models\DealDocument;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DealFinanceController extends Controller
{
    public function commissions(Request $request, TenantContext $tenant, int $deal)
    {
        $membership = $request->attributes->get('membership');
        $deal = Deal::where('company_id', $tenant->id())->findOrFail($deal);
        $query = DealCommission::where('company_id', $tenant->id())->where('deal_id', $deal->id);
        if (! $membership->can('view_commissions')) $query->where('company_membership_id', $membership->id);

        return response()->json($query->with('membership.user:id,name')->latest()->get()->map(fn (DealCommission $entry) => $this->serializeCommission($entry)));
    }

    public function createCommission(Request $request, TenantContext $tenant, int $deal, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('manage_commissions'), 403);
        $deal = Deal::where('company_id', $tenant->id())->findOrFail($deal);
        $data = $request->validate([
            'payee_user_id' => ['required', 'integer', Rule::exists('company_memberships', 'user_id')->where('company_id', $tenant->id())->where('status', 'active')],
            'calculation_type' => ['sometimes', Rule::in(['fixed', 'percentage'])],
            'amount_egp' => ['required_if:calculation_type,fixed', 'nullable', 'string', 'regex:/^\\d{1,12}(?:\\.\\d{1,2})?$/'],
            'rate_percent' => ['required_if:calculation_type,percentage', 'nullable', 'string', 'regex:/^\\d{1,3}(?:\\.\\d{1,2})?$/'],
            'due_on' => ['nullable', 'date_format:Y-m-d'], 'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $calculationType = $data['calculation_type'] ?? 'fixed';
        $baseMinor = $deal->agreed_price_minor_units;
        $rateBasisPoints = null;
        if ($calculationType === 'percentage') {
            abort_unless(in_array($deal->status, ['contracted', 'closed_won'], true), 422, 'A percentage commission can only be calculated for a contracted or won deal.');
            if (! $deal->property_listing_id) throw ValidationException::withMessages(['deal' => 'Link this deal to a property unit before calculating a percentage commission.']);
            if (! $baseMinor || $baseMinor < 1) throw ValidationException::withMessages(['deal' => 'Enter the agreed sale value before calculating a percentage commission.']);
            $rateBasisPoints = $this->toBasisPoints($data['rate_percent']);
            if ($rateBasisPoints < 1 || $rateBasisPoints > 10000) throw ValidationException::withMessages(['rate_percent' => 'The commission rate must be greater than 0% and no more than 100%.']);
            $amountMinor = intdiv(($baseMinor * $rateBasisPoints) + 5000, 10000);
            if ($amountMinor < 1) throw ValidationException::withMessages(['rate_percent' => 'This rate is too small to produce a commission of at least EGP 0.01.']);
        } else {
            $amountMinor = $this->toMinorUnits($data['amount_egp']);
        }
        $membership = CompanyMembership::where('company_id', $tenant->id())->where('user_id', $data['payee_user_id'])->where('status', 'active')->with('user:id,name')->firstOrFail();
        $listing = $deal->property_listing_id
            ? \App\Models\PropertyListing::where('company_id', $tenant->id())->whereKey($deal->property_listing_id)->first()
            : null;
        $unitReference = $listing ? trim($listing->reference_code.' · '.$listing->title, ' ·') : 'Deal #'.$deal->id.' (no unit linked)';
        $entry = DealCommission::create([
            'company_id' => $tenant->id(), 'deal_id' => $deal->id, 'property_listing_id' => $listing?->id,
            'unit_reference' => $unitReference, 'company_membership_id' => $membership->id,
            'payee_name' => $membership->user->name, 'amount_minor_units' => $amountMinor,
            'calculation_type' => $calculationType, 'rate_basis_points' => $rateBasisPoints,
            'base_amount_minor_units' => $calculationType === 'percentage' ? $baseMinor : null,
            'currency' => 'EGP', 'status' => 'pending', 'due_on' => $data['due_on'] ?? null,
            'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $audit->log($request, 'deal.commission_created', $entry, [], $entry->only(['deal_id', 'property_listing_id', 'unit_reference', 'payee_name', 'amount_minor_units', 'calculation_type', 'rate_basis_points', 'base_amount_minor_units', 'currency', 'status', 'due_on', 'reference']));

        return response()->json($this->serializeCommission($entry->load('membership.user:id,name')), 201);
    }

    public function updateCommission(Request $request, TenantContext $tenant, int $deal, int $commission, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('manage_commissions'), 403);
        $deal = Deal::where('company_id', $tenant->id())->findOrFail($deal);
        $entry = DealCommission::where('company_id', $tenant->id())->where('deal_id', $deal->id)->findOrFail($commission);
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'paid', 'void'])]]);
        $transitions = ['pending' => ['approved', 'void'], 'approved' => ['paid', 'void'], 'paid' => [], 'void' => []];
        if (! in_array($data['status'], $transitions[$entry->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'This commission status cannot be changed from its current state.']);
        }
        if ($data['status'] === 'paid' && ! $entry->currentSignedDocument()->exists()) {
            throw ValidationException::withMessages(['signed_document' => 'Upload the employee-signed payment order before marking this commission as paid.']);
        }
        $old = $entry->status;
        $entry->status = $data['status'];
        if ($entry->status === 'paid') $entry->paid_at = now();
        $entry->save();
        $audit->log($request, 'deal.commission_status_changed', $entry, ['status' => $old], ['status' => $entry->status, 'paid_at' => $entry->paid_at]);

        return response()->json($this->serializeCommission($entry->fresh('membership.user:id,name')));
    }

    public function documents(Request $request, TenantContext $tenant, int $deal)
    {
        $deal = $this->findVisibleDeal($request, $tenant, $deal);
        return response()->json(DealDocument::where('company_id', $tenant->id())->where('deal_id', $deal->id)->latest()->get()->map(fn (DealDocument $document) => $this->serializeDocument($document)));
    }

    public function uploadDocument(Request $request, TenantContext $tenant, int $deal, AuditLogger $audit)
    {
        $deal = $this->findVisibleDeal($request, $tenant, $deal);
        $this->authorizeManageDeal($request, $deal);
        $data = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
            'category' => ['sometimes', Rule::in(['signed_contract', 'contract', 'reservation', 'identity', 'other'])],
        ]);
        $file = $data['document'];
        $category = $data['category'] ?? 'contract';
        if ($category === 'signed_contract') {
            abort_unless($request->attributes->get('membership')->can('manage_deals'), 403, 'Only workspace management can attach an executed sale contract.');
        }
        if ($category === 'signed_contract' && $file->getMimeType() !== 'application/pdf') {
            throw \Illuminate\Validation\ValidationException::withMessages(['document' => 'A signed deal contract must be uploaded as a PDF.']);
        }
        $filename = Str::uuid().'.'.$file->extension();
        $path = $file->storeAs("companies/{$tenant->id()}/deals/{$deal->id}/documents", $filename, 'private');
        $document = DealDocument::create([
            'company_id' => $tenant->id(), 'deal_id' => $deal->id, 'uploaded_by' => $request->user()->id,
            'category' => $category, 'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'storage_path' => $path, 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
        ]);
        $audit->log($request, 'deal.document_uploaded', $document, [], ['deal_id' => $deal->id, 'category' => $document->category, 'mime_type' => $document->mime_type, 'size_bytes' => $document->size_bytes]);

        return response()->json($this->serializeDocument($document), 201);
    }

    public function downloadDocument(Request $request, TenantContext $tenant, int $deal, int $document)
    {
        $deal = $this->findVisibleDeal($request, $tenant, $deal);
        $document = DealDocument::where('company_id', $tenant->id())->where('deal_id', $deal->id)->findOrFail($document);

        return Storage::disk('private')->download($document->storage_path, $document->original_name, [
            'Content-Type' => $document->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function findVisibleDeal(Request $request, TenantContext $tenant, int $id): Deal
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('view_deals') || $membership->can('view_own_deals'), 403);
        $query = Deal::where('company_id', $tenant->id())->with('lead');
        if (! $membership->can('view_deals')) {
            $query->whereHas('lead', fn ($lead) => $lead->where(fn ($visible) => $visible->where('assigned_to', $request->user()->id)->orWhere('created_by', $request->user()->id)));
        }
        return $query->findOrFail($id);
    }

    private function authorizeManageDeal(Request $request, Deal $deal): void
    {
        $membership = $request->attributes->get('membership');
        $own = $deal->lead && ($deal->lead->assigned_to === $request->user()->id || $deal->lead->created_by === $request->user()->id);
        abort_unless($membership->can('manage_deals') || ($membership->can('update_own_deals') && $own), 403);
    }

    private function serializeCommission(DealCommission $entry): array
    {
        $entry->loadMissing(['currentSignedDocument', 'deal']);
        $signedDocument = $entry->currentSignedDocument;
        return [
            'id' => $entry->id, 'deal_id' => $entry->deal_id, 'payee_name' => $entry->payee_name,
            'payee_user_id' => $entry->membership?->user_id,
            'property_listing_id' => $entry->property_listing_id, 'unit_reference' => $entry->unit_reference,
            'calculation_type' => $entry->calculation_type,
            'rate_percent' => $entry->rate_basis_points === null ? null : intdiv($entry->rate_basis_points, 100).'.'.str_pad((string) ($entry->rate_basis_points % 100), 2, '0', STR_PAD_LEFT),
            'base_amount_minor_units' => $entry->base_amount_minor_units,
            'amount_egp' => intdiv($entry->amount_minor_units, 100).'.'.str_pad((string) ($entry->amount_minor_units % 100), 2, '0', STR_PAD_LEFT),
            'amount_minor_units' => $entry->amount_minor_units, 'currency' => $entry->currency,
            'status' => $entry->status, 'due_on' => $entry->due_on?->format('Y-m-d'),
            'paid_at' => $entry->paid_at, 'reference' => $entry->reference, 'notes' => $entry->notes,
            'signed_document' => $signedDocument ? [
                'id' => $signedDocument->id, 'original_name' => $signedDocument->original_name,
                'mime_type' => $signedDocument->mime_type, 'size_bytes' => $signedDocument->size_bytes,
                'uploaded_at' => $signedDocument->created_at,
                'url' => route('commissions.signed-document', ['commission' => $entry->id]),
            ] : null,
        ];
    }

    private function serializeDocument(DealDocument $document): array
    {
        return [
            'id' => $document->id, 'category' => $document->category, 'original_name' => $document->original_name,
            'mime_type' => $document->mime_type, 'size_bytes' => $document->size_bytes,
            'uploaded_at' => $document->created_at, 'url' => route('deals.documents.download', ['deal' => $document->deal_id, 'document' => $document->id]),
        ];
    }

    private function toMinorUnits(string $amount): int
    {
        [$pounds, $piastres] = array_pad(explode('.', $amount, 2), 2, '');
        $minor = ((int) $pounds * 100) + (int) str_pad($piastres, 2, '0');
        if ($minor < 1) throw ValidationException::withMessages(['amount_egp' => 'The commission must be greater than zero.']);
        return $minor;
    }

    private function toBasisPoints(string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', $percent, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
