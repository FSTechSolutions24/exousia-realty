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
        $query = FollowUpTask::where('company_id', $tenant->id())->with(['lead:id,name', 'assignee:id,name']);
        if ($request->attributes->get('membership')->role === 'agent') $query->where('assigned_to', $request->user()->id);
        if ($request->boolean('open', true)) $query->whereNull('completed_at');
        return response()->json($query->orderByRaw('completed_at is null desc')->orderBy('due_at')->paginate(20));
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
