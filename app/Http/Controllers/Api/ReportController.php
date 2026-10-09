<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealCommission;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Support\TenantContext;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    private const DEAL_STATUSES = ['negotiation', 'reserved', 'contracted', 'closed_won', 'closed_lost'];

    public function __invoke(Request $request, TenantContext $tenant)
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('view_reports') || $membership->can('view_own_reports'), 403);
        $validated = $request->validate(['period' => ['sometimes', Rule::in(['month', 'quarter', 'year'])]]);
        $period = $validated['period'] ?? 'month';
        $start = match ($period) {
            'quarter' => now()->startOfQuarter(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };
        $end = now();

        $leads = Lead::query()->where('leads.company_id', $tenant->id())
            ->whereBetween('leads.created_at', [$start, $end]);
        if (! $membership->can('view_reports')) {
            $leads->where(function ($query) use ($request) {
                $query->where('leads.assigned_to', $request->user()->id)
                    ->orWhere('leads.created_by', $request->user()->id);
            });
        }

        $stages = PipelineStage::where('company_id', $tenant->id())->orderBy('position')->get(['id', 'name', 'name_ar', 'color', 'is_won', 'is_lost']);
        $wonStageIds = $stages->where('is_won', true)->pluck('id')->all();
        $lostStageIds = $stages->where('is_lost', true)->pluck('id')->all();
        $totalLeads = (clone $leads)->count();
        $wonLeads = $wonStageIds ? (clone $leads)->whereIn('leads.pipeline_stage_id', $wonStageIds)->count() : 0;
        $lostLeads = $lostStageIds ? (clone $leads)->whereIn('leads.pipeline_stage_id', $lostStageIds)->count() : 0;
        $funnelCounts = (clone $leads)->selectRaw('leads.pipeline_stage_id, COUNT(*) as lead_count')
            ->groupBy('leads.pipeline_stage_id')->pluck('lead_count', 'pipeline_stage_id');
        $funnel = $stages->map(fn (PipelineStage $stage) => [
            'id' => $stage->id, 'name' => $stage->name, 'name_ar' => $stage->name_ar, 'color' => $stage->color,
            'count' => (int) ($funnelCounts[$stage->id] ?? 0),
        ])->values();

        $sources = (clone $leads)
            ->leftJoin('pipeline_stages', function (JoinClause $join) use ($tenant) {
                $join->on('pipeline_stages.id', '=', 'leads.pipeline_stage_id')
                    ->where('pipeline_stages.company_id', '=', $tenant->id());
            })
            ->select('leads.source')
            ->selectRaw('COUNT(*) as lead_count')
            ->selectRaw('SUM(CASE WHEN pipeline_stages.is_won = 1 THEN 1 ELSE 0 END) as won_count')
            ->groupBy('leads.source')->orderByDesc('lead_count')->get()
            ->map(function ($source) {
                $count = (int) $source->lead_count;
                $won = (int) $source->won_count;
                return [
                    'source' => $source->source ?: 'unknown', 'lead_count' => $count, 'won_count' => $won,
                    'conversion_rate' => $count ? round($won / $count * 100, 1) : 0,
                ];
            })->values();

        $deals = Deal::where('company_id', $tenant->id())->whereBetween('created_at', [$start, $end]);
        if (! $membership->can('view_reports')) {
            $deals->whereHas('lead', function ($query) use ($request) {
                $query->where(fn ($leadQuery) => $leadQuery->where('assigned_to', $request->user()->id)->orWhere('created_by', $request->user()->id));
            });
        }
        $dealRows = (clone $deals)->selectRaw('status, COUNT(*) as deal_count, COALESCE(SUM(agreed_price_minor_units), 0) as value_minor_units')
            ->groupBy('status')->get()->keyBy('status');
        $dealBreakdown = collect(self::DEAL_STATUSES)->map(function (string $status) use ($dealRows) {
            $row = $dealRows->get($status);
            return ['status' => $status, 'count' => (int) ($row->deal_count ?? 0), 'value_minor_units' => (int) ($row->value_minor_units ?? 0)];
        });

        $sales = Deal::query()->where('company_id', $tenant->id())->where('status', 'closed_won')
            ->whereBetween('closed_at', [$start, $end]);
        if (! $membership->can('view_reports')) {
            $sales->where('salesperson_membership_id', $membership->id);
        }
        $salesPerformance = (clone $sales)->selectRaw("salesperson_membership_id, COALESCE(salesperson_name, 'Unattributed') as salesperson_name, COUNT(*) as units_sold, COUNT(agreed_price_minor_units) as priced_deals, COALESCE(SUM(agreed_price_minor_units), 0) as agreed_value_minor_units")
            ->groupBy('salesperson_membership_id', 'salesperson_name')
            ->orderByDesc('units_sold')->orderByDesc('agreed_value_minor_units')->get()
            ->map(fn ($row) => [
                'salesperson_membership_id' => $row->salesperson_membership_id ? (int) $row->salesperson_membership_id : null,
                'salesperson_name' => $row->salesperson_name,
                'units_sold' => (int) $row->units_sold,
                'priced_deals' => (int) $row->priced_deals,
                'agreed_value_minor_units' => (int) $row->agreed_value_minor_units,
            ])->values();

        $commissionQuery = DealCommission::query()->where('company_id', $tenant->id())->whereBetween('created_at', [$start, $end]);
        if (! $membership->can('view_reports')) $commissionQuery->where('company_membership_id', $membership->id);
        $commissionSummary = (clone $commissionQuery)->selectRaw("COUNT(*) as entry_count, COALESCE(SUM(CASE WHEN status = 'pending' THEN amount_minor_units ELSE 0 END), 0) as pending_minor_units, COALESCE(SUM(CASE WHEN status = 'approved' THEN amount_minor_units ELSE 0 END), 0) as approved_minor_units, COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_minor_units ELSE 0 END), 0) as paid_minor_units, COALESCE(SUM(CASE WHEN status = 'void' THEN amount_minor_units ELSE 0 END), 0) as void_minor_units")
            ->first();
        $commissionByEmployee = (clone $commissionQuery)->selectRaw("company_membership_id, payee_name, COUNT(*) as entry_count, COALESCE(SUM(CASE WHEN status = 'pending' THEN amount_minor_units ELSE 0 END), 0) as pending_minor_units, COALESCE(SUM(CASE WHEN status = 'approved' THEN amount_minor_units ELSE 0 END), 0) as approved_minor_units, COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_minor_units ELSE 0 END), 0) as paid_minor_units")
            ->groupBy('company_membership_id', 'payee_name')->orderByRaw('(SUM(CASE WHEN status IN (\'pending\', \'approved\', \'paid\') THEN amount_minor_units ELSE 0 END)) DESC')->get()
            ->map(fn ($row) => [
                'company_membership_id' => $row->company_membership_id ? (int) $row->company_membership_id : null,
                'payee_name' => $row->payee_name,
                'entry_count' => (int) $row->entry_count,
                'pending_minor_units' => (int) $row->pending_minor_units,
                'approved_minor_units' => (int) $row->approved_minor_units,
                'paid_minor_units' => (int) $row->paid_minor_units,
            ])->values();
        $commissionEntries = (clone $commissionQuery)->latest('created_at')->get([
            'id', 'deal_id', 'property_listing_id', 'company_membership_id', 'payee_name', 'unit_reference',
            'amount_minor_units', 'calculation_type', 'rate_basis_points', 'base_amount_minor_units', 'status', 'created_at',
        ])->map(fn (DealCommission $entry) => [
            'id' => $entry->id, 'deal_id' => $entry->deal_id, 'property_listing_id' => $entry->property_listing_id,
            'company_membership_id' => $entry->company_membership_id, 'payee_name' => $entry->payee_name,
            'unit_reference' => $entry->unit_reference ?: 'Deal #'.$entry->deal_id,
            'amount_minor_units' => (int) $entry->amount_minor_units,
            'calculation_type' => $entry->calculation_type,
            'rate_basis_points' => $entry->rate_basis_points,
            'base_amount_minor_units' => $entry->base_amount_minor_units,
            'status' => $entry->status, 'created_at' => $entry->created_at,
        ])->values();
        $commissionTotals = [
            'entry_count' => (int) ($commissionSummary->entry_count ?? 0),
            'pending_minor_units' => (int) ($commissionSummary->pending_minor_units ?? 0),
            'approved_minor_units' => (int) ($commissionSummary->approved_minor_units ?? 0),
            'paid_minor_units' => (int) ($commissionSummary->paid_minor_units ?? 0),
            'void_minor_units' => (int) ($commissionSummary->void_minor_units ?? 0),
        ];
        $commissionTotals['eligible_minor_units'] = $commissionTotals['pending_minor_units'] + $commissionTotals['approved_minor_units'] + $commissionTotals['paid_minor_units'];

        $activeStatuses = ['negotiation', 'reserved', 'contracted'];
        $activeDealCount = $dealBreakdown->whereIn('status', $activeStatuses)->sum('count');
        $activeDealValue = $dealBreakdown->whereIn('status', $activeStatuses)->sum('value_minor_units');
        $wonDealCount = (int) $dealBreakdown->firstWhere('status', 'closed_won')['count'];
        $closedDealCount = $wonDealCount + (int) $dealBreakdown->firstWhere('status', 'closed_lost')['count'];

        $previousStart = match ($period) {
            'quarter' => $start->copy()->subQuarter(),
            'year' => $start->copy()->subYear(),
            default => $start->copy()->subMonth(),
        };
        $previousPeriodEnd = match ($period) {
            'quarter' => $previousStart->copy()->endOfQuarter(),
            'year' => $previousStart->copy()->endOfYear(),
            default => $previousStart->copy()->endOfMonth(),
        };
        $previousEnd = $previousStart->copy()->addSeconds($start->diffInSeconds($end));
        if ($previousEnd->greaterThan($previousPeriodEnd)) $previousEnd = $previousPeriodEnd;
        $previousLeads = Lead::query()->where('company_id', $tenant->id())->whereBetween('created_at', [$previousStart, $previousEnd]);
        $previousDeals = Deal::query()->where('company_id', $tenant->id())->whereBetween('created_at', [$previousStart, $previousEnd]);
        if (! $membership->can('view_reports')) {
            $previousLeads->where(fn ($query) => $query->where('assigned_to', $request->user()->id)->orWhere('created_by', $request->user()->id));
            $previousDeals->whereHas('lead', fn ($query) => $query->where(fn ($leadQuery) => $leadQuery->where('assigned_to', $request->user()->id)->orWhere('created_by', $request->user()->id)));
        }
        $previousLeadCount = (clone $previousLeads)->count();
        $previousWonLeads = $wonStageIds ? (clone $previousLeads)->whereIn('pipeline_stage_id', $wonStageIds)->count() : 0;
        $previousDealRows = (clone $previousDeals)->selectRaw('status, COUNT(*) as deal_count, COALESCE(SUM(agreed_price_minor_units), 0) as value_minor_units')->groupBy('status')->get()->keyBy('status');
        $previousActive = $previousDealRows->only($activeStatuses);
        $previousWon = $previousDealRows->get('closed_won');
        $previousLost = $previousDealRows->get('closed_lost');
        $previousClosedCount = (int) ($previousWon->deal_count ?? 0) + (int) ($previousLost->deal_count ?? 0);
        $previousDealCount = (int) $previousDealRows->sum('deal_count');
        $previousMetrics = [
            'leads' => $previousLeadCount,
            'won_leads' => $previousWonLeads,
            'lead_win_rate' => $previousLeadCount ? round($previousWonLeads / $previousLeadCount * 100, 1) : 0,
            'deals' => $previousDealCount,
            'active_deals' => (int) $previousActive->sum('deal_count'),
            'closed_deals' => $previousClosedCount,
            'deal_win_rate' => $previousClosedCount ? round((int) ($previousWon->deal_count ?? 0) / $previousClosedCount * 100, 1) : 0,
            'active_deal_value_minor_units' => (int) $previousActive->sum('value_minor_units'),
            'won_deal_value_minor_units' => (int) ($previousWon->value_minor_units ?? 0),
        ];

        $payload = [
            'period' => ['key' => $period, 'from' => $start->toDateString(), 'to' => $end->toDateString()],
            'metrics' => [
                'leads' => $totalLeads, 'won_leads' => $wonLeads, 'lost_leads' => $lostLeads,
                'lead_win_rate' => $totalLeads ? round($wonLeads / $totalLeads * 100, 1) : 0,
                'deals' => (int) $dealBreakdown->sum('count'), 'active_deals' => (int) $activeDealCount,
                'closed_deals' => (int) $closedDealCount,
                'deal_win_rate' => $closedDealCount ? round($wonDealCount / $closedDealCount * 100, 1) : 0,
                'active_deal_value_minor_units' => (int) $activeDealValue,
                'won_deal_value_minor_units' => (int) $dealBreakdown->firstWhere('status', 'closed_won')['value_minor_units'],
            ],
            'funnel' => $funnel,
            'sources' => $sources,
            'deals_by_status' => $dealBreakdown->values(),
            'sales_performance' => $salesPerformance,
            'commission_totals' => $commissionTotals,
            'commissions_by_employee' => $commissionByEmployee,
            'commission_entries' => $commissionEntries,
            'comparison' => [
                'from' => $previousStart->toDateString(), 'to' => $previousEnd->toDateString(),
                'metrics' => $previousMetrics,
            ],
        ];

        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () use ($payload) {
                $output = fopen('php://output', 'w');
                $safe = fn ($value) => is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, ['Section', 'Name', 'Value', 'Details']);
                fputcsv($output, ['Period', 'Current', $payload['period']['from'].' to '.$payload['period']['to'], '']);
                fputcsv($output, ['Period', 'Previous', $payload['comparison']['from'].' to '.$payload['comparison']['to'], '']);
                foreach ($payload['metrics'] as $name => $value) fputcsv($output, ['Metric', $safe($name), $value, '']);
                foreach ($payload['funnel'] as $row) fputcsv($output, ['Lead stage', $safe($row['name']), $row['count'], $safe($row['name_ar'] ?? '')]);
                foreach ($payload['sources'] as $row) fputcsv($output, ['Lead source', $safe($row['source']), $row['lead_count'], $safe('Won: '.$row['won_count'].'; conversion: '.$row['conversion_rate'].'%')]);
                foreach ($payload['deals_by_status'] as $row) fputcsv($output, ['Deal status', $safe($row['status']), $row['count'], 'Agreed value minor units: '.$row['value_minor_units']]);
                foreach ($payload['sales_performance'] as $row) fputcsv($output, ['Sales performance', $safe($row['salesperson_name']), $row['units_sold'], 'Agreed value minor units: '.$row['agreed_value_minor_units'].'; priced deals: '.$row['priced_deals']]);
                foreach ($payload['commissions_by_employee'] as $row) fputcsv($output, ['Employee commissions', $safe($row['payee_name']), $row['entry_count'], 'Pending: '.$row['pending_minor_units'].'; approved: '.$row['approved_minor_units'].'; paid: '.$row['paid_minor_units'].' (EGP minor units)']);
                foreach ($payload['commission_entries'] as $row) fputcsv($output, ['Commission by unit', $safe($row['unit_reference']), $row['amount_minor_units'], $safe($row['payee_name'].'; '.$row['status'].'; '.$row['calculation_type'].'; deal #'.$row['deal_id'])]);
                foreach ($payload['comparison']['metrics'] as $name => $value) fputcsv($output, ['Previous period metric', $safe($name), $value, '']);
                fclose($output);
            }, 'exousia-report-'.$period.'-'.$start->format('Y-m-d').'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return response()->json($payload);
    }
}
