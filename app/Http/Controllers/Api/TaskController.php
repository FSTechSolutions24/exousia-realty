<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        $filters = $request->validate([
            'bucket' => ['nullable', Rule::in(['all', 'overdue', 'today', 'upcoming'])],
            'open' => ['sometimes', 'boolean'],
        ]);
        $query = FollowUpTask::where('company_id', $tenant->id())->with(['lead:id,name', 'assignee:id,name']);
        if ($request->attributes->get('membership')->role === 'agent') $query->where('assigned_to', $request->user()->id);
        $isOpen = $request->boolean('open', true);
        if ($isOpen) $query->whereNull('completed_at');

        $counts = ['all' => (clone $query)->count()];
        if ($isOpen) {
            $counts['overdue'] = (clone $query)->where('due_at', '<', now()->startOfDay())->count();
            $counts['today'] = (clone $query)->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])->count();
            $counts['upcoming'] = (clone $query)->where('due_at', '>', now()->endOfDay())->count();
        } else {
            $counts += ['overdue' => 0, 'today' => 0, 'upcoming' => 0];
        }

        $bucket = $filters['bucket'] ?? 'all';
        if ($isOpen && $bucket === 'overdue') $query->where('due_at', '<', now()->startOfDay());
        if ($isOpen && $bucket === 'today') $query->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]);
        if ($isOpen && $bucket === 'upcoming') $query->where('due_at', '>', now()->endOfDay());

        $tasks = $query->orderByRaw('completed_at is null desc')->orderBy('due_at')->paginate(20)->toArray();
        return response()->json($tasks + ['task_counts' => $counts]);
    }

    public function store(Request $request, TenantContext $tenant, AuditLogger $audit)
    {
        $data = $request->validate([
            'lead_id' => ['nullable', Rule::exists('leads', 'id')->where('company_id', $tenant->id())],
            'assigned_to' => ['required', Rule::exists('company_memberships', 'user_id')->where('company_id', $tenant->id())->where('status', 'active')],
            'title' => ['required', 'string', 'max:190'], 'description' => ['nullable', 'string', 'max:3000'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])], 'due_at' => ['required', 'date'],
        ]);
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('manage_tasks') || ($membership->can('manage_own_tasks') && (int) $data['assigned_to'] === $request->user()->id), 403);
        $task = FollowUpTask::create($data + ['company_id' => $tenant->id(), 'created_by' => $request->user()->id]);
        $audit->log($request, 'task.created', $task, [], $task->toArray());
        return response()->json($task->load(['lead:id,name', 'assignee:id,name']), 201);
    }

    public function complete(Request $request, TenantContext $tenant, int $task, AuditLogger $audit)
    {
        $task = FollowUpTask::where('company_id', $tenant->id())->whereKey($task)->firstOrFail();
        $membership = $request->attributes->get('membership');
        abort_unless($membership->can('manage_tasks') || $task->assigned_to === $request->user()->id, 403);
        $task->update(['completed_at' => $task->completed_at ? null : now()]);
        $audit->log($request, 'task.completion_toggled', $task, [], ['completed_at' => $task->completed_at]);
        return response()->json($task);
    }
}
