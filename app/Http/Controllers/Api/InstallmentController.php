<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealInstallment;
use App\Models\DealInstallmentPayment;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InstallmentController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        abort_unless($request->attributes->get('membership')->can('view_deals') || $request->attributes->get('membership')->can('view_own_deals'), 403);
        DealInstallment::where('company_id', $tenant->id())->whereIn('status', ['scheduled', 'part_paid'])->whereDate('due_date', '<', today())->update(['status' => 'overdue']);
        $query = DealInstallment::where('company_id', $tenant->id())->with(['deal.lead:id,name,assigned_to,created_by', 'deal.propertyListing:id,title,reference_code']);
        $membership = $request->attributes->get('membership');
        if (! $membership->can('view_deals')) {
            $query->whereHas('deal.lead', fn ($leads) => $leads->where(fn ($visible) => $visible->where('assigned_to', $request->user()->id)->orWhere('created_by', $request->user()->id)));
        }
        if ($status = $request->query('status')) $query->where('status', $status);
        return response()->json($query->with('payments.recorder:id,name')->orderBy('due_date')->paginate(min(max((int) $request->query('per_page', 50), 1), 100)));
    }

    public function generate(Request $request, TenantContext $tenant, int $deal, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('manage_installments'), 403);
        $data = $request->validate(['count' => ['required', 'integer', 'min:1', 'max:120'], 'first_due_date' => ['required', 'date_format:Y-m-d'], 'frequency' => ['required', Rule::in(['monthly', 'quarterly'])]]);
        $start = Carbon::createFromFormat('Y-m-d', $data['first_due_date']);
        if ($start->format('Y-m-d') !== $data['first_due_date']) throw ValidationException::withMessages(['first_due_date' => 'Enter a valid due date.']);

        $installments = DB::transaction(function () use ($request, $tenant, $deal, $data, $start) {
            $deal = Deal::where('company_id', $tenant->id())->lockForUpdate()->findOrFail($deal);
            abort_unless(in_array($deal->status, ['reserved', 'contracted', 'closed_won'], true), 422, 'Installments can be scheduled after the deal is reserved or contracted.');
            abort_if(DealInstallment::where('company_id', $tenant->id())->where('deal_id', $deal->id)->exists(), 422, 'This deal already has an installment schedule.');
            $agreed = $deal->agreed_price_minor_units ?? 0;
            $received = $deal->amount_received_minor_units ?? 0;
            if ($received > $agreed) throw ValidationException::withMessages(['deal' => 'Recorded receipts exceed the deal value. Review the deal payment record first.']);
            $balance = $agreed - $received;
            if ($balance < 1) throw ValidationException::withMessages(['deal' => 'The deal has no outstanding balance to schedule.']);
            $base = intdiv($balance, (int) $data['count']);
            $remainder = $balance % (int) $data['count'];
            $items = [];
            for ($i = 1; $i <= (int) $data['count']; $i++) {
                $months = ($i - 1) * ($data['frequency'] === 'quarterly' ? 3 : 1);
                $due = $start->copy()->addMonthsNoOverflow($months);
                $items[] = DealInstallment::create([
                    'company_id' => $tenant->id(), 'branch_id' => $deal->branch_id, 'deal_id' => $deal->id,
                    'sequence' => $i, 'due_date' => $due->format('Y-m-d'), 'amount_minor_units' => $base + ($i === (int) $data['count'] ? $remainder : 0), 'status' => 'scheduled',
                ]);
            }
            return [$deal, $items, $balance];
        });
        [$dealModel, $items, $balance] = $installments;
        $audit->log($request, 'installments.generated', $dealModel, [], ['count' => count($items), 'balance_minor_units' => $balance, 'frequency' => $data['frequency']]);
        return response()->json(collect($items), 201);
    }

    public function pay(Request $request, TenantContext $tenant, int $installment, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('manage_installments'), 403);
        $data = $request->validate([
            'amount_egp' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'cheque', 'financing', 'other'])],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'payment_reference' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $amount = $this->minor($data['amount_egp']);
        [$item, $payment, $old, $new] = DB::transaction(function () use ($request, $tenant, $installment, $data, $amount) {
            $item = DealInstallment::where('company_id', $tenant->id())->lockForUpdate()->findOrFail($installment);
            $old = $item->only(['paid_minor_units', 'status', 'paid_at', 'payment_method', 'payment_reference', 'notes']);
            $remaining = $item->amount_minor_units - $item->paid_minor_units;
            if ($amount < 1 || $amount > $remaining) throw ValidationException::withMessages(['amount_egp' => 'The payment must be greater than zero and cannot exceed the installment balance.']);
            $paid = $item->paid_minor_units + $amount;
            $payment = DealInstallmentPayment::create([
                'company_id' => $tenant->id(), 'branch_id' => $item->branch_id, 'deal_id' => $item->deal_id,
                'deal_installment_id' => $item->id, 'recorded_by' => $request->user()->id,
                'amount_minor_units' => $amount, 'paid_on' => $data['paid_on'], 'method' => $data['payment_method'],
                'reference' => $data['payment_reference'] ?? null, 'notes' => $data['notes'] ?? null,
            ]);
            $item->update([
                'paid_minor_units' => $paid, 'status' => $paid === $item->amount_minor_units ? 'paid' : 'part_paid',
                'paid_at' => $paid === $item->amount_minor_units ? now() : null, 'payment_method' => $data['payment_method'],
                'payment_reference' => $data['payment_reference'] ?? null, 'notes' => $data['notes'] ?? $item->notes,
            ]);
            return [$item, $payment, $old, ['paid_minor_units' => $paid, 'status' => $item->status, 'payment_id' => $payment->id, 'amount_minor_units' => $amount]];
        });
        $audit->log($request, 'installment.payment_recorded', $item, $old, $new);
        return response()->json($item->fresh()->load('payments.recorder:id,name'));
    }

    private function minor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
