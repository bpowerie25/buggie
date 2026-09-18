<?php

namespace App\Http\Controllers;

use App\Actions\TriageReport;
use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\ReportState;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Report;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Report::class);

        $reports = Report::awaitingTriage()
            ->with('project:id,key,name,slug')
            ->when(
                $request->filled('project'),
                fn ($q) => $q->whereHas('project', fn ($p) => $p->where('slug', $request->string('project'))),
            )
            ->oldest()
            ->limit(200)
            ->get();

        // Reports sharing a fingerprint are the same bug arriving repeatedly; show one
        // row with a count rather than making someone dismiss the same thing ten times.
        $grouped = $reports
            ->groupBy(fn (Report $report) => $report->fingerprint ?? 'r'.$report->id)
            ->map(fn ($group) => $this->present($group->first(), $group->count(), $group->pluck('id')->all()))
            ->values();

        return Inertia::render('reports/index', [
            'reports' => $grouped,
            'projects' => Project::active()->orderBy('name')->get(['id', 'name', 'key', 'slug']),
            'filters' => ['project' => $request->string('project')->toString() ?: null],
            'priorities' => IssuePriority::options(),
            'types' => IssueType::options(),
            'members' => $this->tenancy->currentOrFail()->members()
                ->orderBy('name')->get(['users.id', 'users.name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'pending' => Report::awaitingTriage()->count(),
        ]);
    }

    public function accept(Request $request, Report $report, TriageReport $action): RedirectResponse
    {
        $this->authorize('triage', $report);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', Rule::in(array_column(IssuePriority::cases(), 'value'))],
            'type' => ['nullable', Rule::in(array_column(IssueType::cases(), 'value'))],
            'assignee_id' => ['nullable', Rule::exists('workspace_user', 'user_id')
                ->where('workspace_id', $this->tenancy->id())],
            'also' => ['array'],          // sibling reports in the same fingerprint group
            'also.*' => ['integer'],
        ]);

        $issue = $action->accept($report, $request->user(), $validated);

        // The rest of the group folds into the issue it just became.
        foreach ($this->siblings($report, $validated['also'] ?? []) as $sibling) {
            $action->merge($sibling, $issue, $request->user());
        }

        return back()->with('success', "{$issue->key} created from report.");
    }

    public function merge(Request $request, Report $report, TriageReport $action): RedirectResponse
    {
        $this->authorize('triage', $report);

        $validated = $request->validate([
            'key' => ['required', 'string'],
            'also' => ['array'],
            'also.*' => ['integer'],
        ]);

        $issue = Issue::where('key', strtoupper($validated['key']))->first();

        if ($issue === null) {
            return back()->withErrors(['key' => 'No issue with that key in this workspace.']);
        }

        $action->merge($report, $issue, $request->user());

        foreach ($this->siblings($report, $validated['also'] ?? []) as $sibling) {
            $action->merge($sibling, $issue, $request->user());
        }

        return back()->with('success', "Merged into {$issue->key}.");
    }

    public function dismiss(Request $request, Report $report, TriageReport $action): RedirectResponse
    {
        $this->authorize('triage', $report);

        $validated = $request->validate([
            'state' => ['required', Rule::in([ReportState::Spam->value, ReportState::Discarded->value])],
            'also' => ['array'],
            'also.*' => ['integer'],
        ]);

        $state = ReportState::from($validated['state']);

        $action->dismiss($report, $request->user(), $state);

        foreach ($this->siblings($report, $validated['also'] ?? []) as $sibling) {
            $action->dismiss($sibling, $request->user(), $state);
        }

        return back();
    }

    /** Stream a report screenshot. Never public: these can show production data. */
    public function screenshot(Report $report): StreamedResponse
    {
        $this->authorize('view', $report);

        abort_if($report->screenshot_path === null, 404);

        return Storage::disk('local')->response($report->screenshot_path);
    }

    /**
     * Other untriaged reports in the same fingerprint group.
     *
     * Re-fetched rather than trusted from the request: the ids are scoped, so a
     * crafted payload cannot reach reports outside the group or the workspace.
     *
     * @param  array<int, int>  $ids
     * @return \Illuminate\Support\Collection<int, Report>
     */
    private function siblings(Report $report, array $ids)
    {
        if ($ids === [] || $report->fingerprint === null) {
            return collect();
        }

        return Report::awaitingTriage()
            ->whereIn('id', $ids)
            ->whereKeyNot($report->id)
            ->where('project_id', $report->project_id)
            ->where('fingerprint', $report->fingerprint)
            ->get();
    }

    /** @return array<string, mixed> */
    private function present(Report $report, int $count, array $ids): array
    {
        return [
            'id' => $report->id,
            'ids' => $ids,
            'count' => $count,
            'title' => $report->title,
            'body' => $report->body,
            'project' => $report->project->only(['key', 'name', 'slug']),
            'reporter' => [
                'name' => $report->reporter_name,
                'email' => $report->reporter_email,
            ],
            'environment' => $report->environment,
            'console' => $report->console,
            'network' => $report->network,
            'error' => $report->error,
            'fingerprint' => $report->fingerprint,
            'screenshot_url' => $report->screenshot_path
                ? route('reports.screenshot', $report)
                : null,
            'created_at' => $report->created_at->toIso8601String(),
        ];
    }
}
