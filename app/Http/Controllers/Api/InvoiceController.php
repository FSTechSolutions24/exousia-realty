<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Lead;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        abort_unless($request->attributes->get('membership')->can('view_invoices'), 403);
        $this->markOverdue($tenant->id());
        $query = Invoice::where('company_id', $tenant->id())->with(['lead:id,name', 'deal.propertyListing:id,title,reference_code']);
        if ($status = $request->query('status')) $query->where('status', $status);
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($invoices) => $invoices->where('invoice_number', 'like', "%{$search}%")->orWhere('billing_name', 'like', "%{$search}%"));
        }
        return response()->json($query->latest('issue_date')->paginate(min(max((int) $request->query('per_page', 15), 1), 50)));
    }

    public function store(Request $request, TenantContext $tenant, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('manage_invoices'), 403);
        $data = $request->validate([
            'deal_id' => ['nullable', 'integer', Rule::exists('deals', 'id')->where('company_id', $tenant->id())],
            'lead_id' => ['nullable', 'integer', Rule::exists('leads', 'id')->where('company_id', $tenant->id())],
            'issue_date' => ['required', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'billing_name' => ['required', 'string', 'max:180'], 'billing_phone' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:5000'],
            'tax_egp' => ['nullable', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'items' => ['required', 'array', 'min:1', 'max:50'], 'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'], 'items.*.unit_price_egp' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
        ]);
        if (! empty($data['deal_id'])) {
            $deal = Deal::where('company_id', $tenant->id())->findOrFail($data['deal_id']);
            if (! empty($data['lead_id']) && $deal->lead_id !== (int) $data['lead_id']) throw ValidationException::withMessages(['lead_id' => 'The lead must belong to the selected deal.']);
            $data['lead_id'] = $deal->lead_id;
        } elseif (! empty($data['lead_id'])) {
            Lead::where('company_id', $tenant->id())->findOrFail($data['lead_id']);
        }

        $invoice = DB::transaction(function () use ($data, $request, $tenant) {
            Company::whereKey($tenant->id())->lockForUpdate()->firstOrFail();
            $next = ((int) Invoice::withoutGlobalScope('branch')->where('company_id', $tenant->id())->max('id')) + 1;
            $subtotal = 0;
            $items = [];
            foreach ($data['items'] as $position => $item) {
                $unit = $this->minor($item['unit_price_egp']);
                $quantity = (int) $item['quantity'];
                if ($unit > intdiv(PHP_INT_MAX, $quantity)) throw ValidationException::withMessages(['items' => 'An invoice line amount is too large.']);
                $line = $unit * $quantity;
                if ($subtotal > PHP_INT_MAX - $line) throw ValidationException::withMessages(['items' => 'The invoice total is too large.']);
                $subtotal += $line;
                $items[] = ['company_id' => $tenant->id(), 'description' => $item['description'], 'quantity' => $quantity, 'unit_price_minor_units' => $unit, 'line_total_minor_units' => $line, 'position' => $position];
            }
            $tax = $this->minor($data['tax_egp'] ?? '0');
            if ($subtotal > PHP_INT_MAX - $tax) throw ValidationException::withMessages(['tax_egp' => 'The invoice total is too large.']);
            $total = $subtotal + $tax;
            if ($total < 1) throw ValidationException::withMessages(['items' => 'The invoice total must be greater than zero.']);
            $invoice = Invoice::create([
                'company_id' => $tenant->id(), 'deal_id' => $data['deal_id'] ?? null, 'lead_id' => $data['lead_id'] ?? null,
                'created_by' => $request->user()->id, 'invoice_number' => 'INV-'.Carbon::parse($data['issue_date'])->format('Y').'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT),
                'status' => 'draft', 'issue_date' => $data['issue_date'], 'due_date' => $data['due_date'] ?? null,
                'billing_name' => $data['billing_name'], 'billing_phone' => $data['billing_phone'] ?? null,
                'subtotal_minor_units' => $subtotal, 'tax_minor_units' => $tax, 'total_minor_units' => $total,
                'currency' => 'EGP', 'notes' => $data['notes'] ?? null,
            ]);
            $invoice->items()->createMany($items);
            return $invoice;
        });
        $audit->log($request, 'invoice.created', $invoice, [], ['invoice_number' => $invoice->invoice_number, 'total_minor_units' => $invoice->total_minor_units]);
        return response()->json($invoice->load('items'), 201);
    }

    public function show(Request $request, TenantContext $tenant, int $invoice)
    {
        abort_unless($request->attributes->get('membership')->can('view_invoices'), 403);
        $this->markOverdue($tenant->id());
        return response()->json(Invoice::where('company_id', $tenant->id())->with(['items', 'payments.recorder:id,name', 'lead:id,name,phone_original', 'deal.propertyListing:id,title,reference_code'])->findOrFail($invoice));
    }

    public function issue(Request $request, TenantContext $tenant, int $invoice, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('manage_invoices'), 403);
        $invoice = Invoice::where('company_id', $tenant->id())->findOrFail($invoice);
        abort_unless($invoice->status === 'draft', 422, 'Only a draft invoice can be issued.');
        $status = $invoice->due_date && $invoice->due_date->lt(today()) ? 'overdue' : 'issued';
        $invoice->update(['status' => $status, 'issued_at' => now()]);
        $audit->log($request, 'invoice.issued', $invoice, ['status' => 'draft'], ['status' => $status]);
        return response()->json($invoice);
    }

    public function payment(Request $request, TenantContext $tenant, int $invoice, AuditLogger $audit)
    {
        abort_unless($request->attributes->get('membership')->can('manage_invoices'), 403);
        $data = $request->validate([
            'amount_egp' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'], 'paid_on' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'cheque', 'card', 'other'])],
            'reference' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $amount = $this->minor($data['amount_egp']);
        [$invoice, $payment] = DB::transaction(function () use ($data, $amount, $request, $tenant, $invoice) {
            $locked = Invoice::where('company_id', $tenant->id())->lockForUpdate()->findOrFail($invoice);
            abort_if(in_array($locked->status, ['draft', 'void', 'paid'], true), 422, 'This invoice cannot receive a payment.');
            $balance = $locked->total_minor_units - $locked->amount_paid_minor_units;
            if ($amount < 1 || $amount > $balance) throw ValidationException::withMessages(['amount_egp' => 'The payment must be greater than zero and cannot exceed the invoice balance.']);
            $payment = InvoicePayment::create([
                'company_id' => $tenant->id(), 'invoice_id' => $locked->id, 'recorded_by' => $request->user()->id,
                'amount_minor_units' => $amount, 'paid_on' => $data['paid_on'], 'method' => $data['method'],
                'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
            ]);
            $paid = $locked->amount_paid_minor_units + $amount;
            $locked->update(['amount_paid_minor_units' => $paid, 'status' => $paid === $locked->total_minor_units ? 'paid' : 'part_paid', 'paid_at' => $paid === $locked->total_minor_units ? now() : null]);
            return [$locked, $payment];
        });
        $audit->log($request, 'invoice.payment_recorded', $invoice, [], ['payment_id' => $payment->id, 'amount_minor_units' => $amount, 'status' => $invoice->status]);
        return response()->json($invoice->fresh()->load('payments'), 201);
    }

    private function markOverdue(int $companyId): void
    {
        Invoice::where('company_id', $companyId)->whereIn('status', ['issued', 'part_paid'])->whereNotNull('due_date')->whereDate('due_date', '<', today())->update(['status' => 'overdue']);
    }

    private function minor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
