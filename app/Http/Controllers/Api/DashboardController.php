<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant)
    {
        $membership = $request->attributes->get('membership');
        $leadQuery = Lead::where('company_id', $tenant->id());
        $taskQuery = FollowUpTask::where('company_id', $tenant->id());
        if (! $membership->can('view_all_leads')) {
            $leadQuery->where('assigned_to', $request->user()->id);
            $taskQuery->where('assigned_to', $request->user()->id);
        }

        $wonStageIds = PipelineStage::where('company_id', $tenant->id())->where('is_won', true)->pluck('id');
        $total = (clone $leadQuery)->count();
        $won = (clone $leadQuery)->whereIn('pipeline_stage_id', $wonStageIds)->count();

        return response()->json([
            'metrics' => [
                'new_leads' => (clone $leadQuery)->where('created_at', '>=', now()->startOfMonth())->count(),
                'active_leads' => (clone $leadQuery)->whereNotIn('pipeline_stage_id', PipelineStage::where('company_id', $tenant->id())->where(fn ($q) => $q->where('is_won', true)->orWhere('is_lost', true))->pluck('id'))->count(),
                'overdue_tasks' => (clone $taskQuery)->whereNull('completed_at')->where('due_at', '<', now())->count(),
                'conversion_rate' => $total ? round(($won / $total) * 100, 1) : 0,
            ],
            'pipeline' => PipelineStage::where('company_id', $tenant->id())->orderBy('position')->get()->map(function ($stage) use ($leadQuery) {
                return ['id' => $stage->id, 'name' => $stage->name, 'name_ar' => $stage->name_ar, 'color' => $stage->color, 'count' => (clone $leadQuery)->where('pipeline_stage_id', $stage->id)->count()];
            }),
            'tasks' => $taskQuery->with(['lead:id,name', 'assignee:id,name'])->whereNull('completed_at')->orderBy('due_at')->limit(6)->get(),
            'recent_leads' => $leadQuery->with(['stage:id,name,name_ar,color', 'assignee:id,name'])->latest()->limit(5)->get(),
        ]);
    }
}
