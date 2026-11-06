<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit trail, for the people who run the workspace. Read-only by construction.
 */
class AuditLogController extends Controller
{
    public function __invoke(Request $request, Tenancy $tenancy): Response
    {
        $workspace = $tenancy->currentOrFail();
        $this->authorize('update', $workspace);

        $filters = $request->validate([
            'actor_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'max:60'],
            'event' => ['nullable', 'string', 'max:80'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $logs = AuditLog::query()
            ->when($filters['actor_id'] ?? null, fn ($q, $id) => $q->where('actor_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('subject_type', $type))
            ->when($filters['event'] ?? null, fn ($q, $event) => $q->where('event', $event))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59.999999'))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('subject_label', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('actor_name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('event', 'ilike', '%'.addcslashes($term, '%_\\').'%')))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->simplePaginate(50)
            ->withQueryString();

        return Inertia::render('settings/audit', [
            'logs' => $logs->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'at' => $log->created_at->toIso8601String(),
                'actor' => $log->actor_name,
                'event' => $log->event,
                'type' => $log->subject_type,
                'label' => $log->subject_label,
                'changes' => $log->changes,
                'ip' => $log->ip,
            ]),
            'filters' => $filters,
            'actors' => AuditLog::query()->whereNotNull('actor_id')
                ->select('actor_id', 'actor_name')->distinct()->orderBy('actor_name')->get()
                ->map(fn ($r) => ['id' => $r->actor_id, 'name' => $r->actor_name]),
            'types' => AuditLog::query()->whereNotNull('subject_type')
                ->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]);
    }
}
