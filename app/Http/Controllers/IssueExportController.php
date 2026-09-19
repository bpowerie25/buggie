<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Support\Issues\IssueQuery;
use App\Support\Issues\IssueQueryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Issues as CSV.
 *
 * Partly because people ask for it, and partly because "you can leave whenever you
 * like" is half the argument for an AGPL tracker and is not true unless the data
 * comes out in something a spreadsheet opens.
 */
class IssueExportController extends Controller
{
    public function __construct(
        private IssueQueryFilter $filter,
        private \App\Support\Tenancy\Tenancy $tenancy,
    ) {}

    private const COLUMNS = [
        'key', 'title', 'status', 'state', 'type', 'priority', 'project',
        'assignee', 'reporter', 'labels', 'visible_to_client', 'occurrences',
        'created_at', 'updated_at', 'closed_at', 'due_on', 'url',
    ];

    public function __invoke(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Issue::class);

        $user = $request->user();
        $staff = $user->membershipIn($this->tenancy->currentOrFail())?->isStaff() ?? false;

        $query = IssueQuery::parse($request->string('q')->toString());

        // Built from the same query the list uses, filtered the same way. An export
        // that assembles its own query is an export that quietly disagrees with the
        // screen, and the disagreement is always in the unsafe direction.
        $builder = Issue::query()->unless(
            $staff,
            fn (Builder $q) => $q->visibleToClient($user),
        );

        $builder = $this->filter->apply($builder, $query, $user)
            ->with([
                'status:id,name,category',
                'assignee:id,name',
                'reporter:id,name',
                'labels:id,name',
                'project:id,key,slug,name',
            ])
            ->orderBy('id');

        $filename = 'buggie-issues-'.now()->format('Y-m-d').'.csv';

        // Read once rather than per row: it is the same workspace for every issue,
        // and touching $issue->workspace inside the loop is both an N+1 and a lazy
        // loading violation, which strict mode turns into an exception mid-stream —
        // after the headers have gone, so it surfaces as a truncated file.
        $slug = $this->tenancy->currentOrFail()->slug;

        return response()->streamDownload(function () use ($builder, $staff, $slug) {
            $out = fopen('php://output', 'w');

            // Excel reads a file without this as Latin-1 and mangles every accented
            // name in it. A byte-order mark is ugly and the alternative is worse.
            fwrite($out, "\u{FEFF}");

            fputcsv($out, self::COLUMNS);

            // Chunked, so exporting a workspace with fifty thousand issues does not
            // load fifty thousand models into memory to write them out one at a time.
            $builder->chunk(500, function ($issues) use ($out, $staff, $slug) {
                foreach ($issues as $issue) {
                    fputcsv($out, $this->row($issue, $staff, $slug));
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<int, string|int|null> */
    private function row(Issue $issue, bool $staff, string $slug): array
    {
        return [
            $issue->key,
            $this->safe($issue->title),
            $issue->status->name,
            $issue->status->category->value,
            $issue->type->value,
            $issue->priority->label(),
            $issue->project->name,
            $issue->assignee?->name,
            $issue->reporter?->name,
            $issue->labels->pluck('name')->implode(', '),
            // Meaningless to a client — everything they can export is visible to them
            // by definition — and useful to staff, who are deciding what to share.
            $staff ? ($issue->visibility->value === 'client' ? 'yes' : 'no') : '',
            $issue->occurrence_count,
            $issue->created_at?->toIso8601String(),
            $issue->updated_at?->toIso8601String(),
            $issue->closed_at?->toIso8601String(),
            $issue->due_on?->toDateString(),
            workspace_url($slug, "issues/{$issue->key}"),
        ];
    }

    /**
     * Defuse spreadsheet formula injection.
     *
     * A title beginning =, +, - or @ is executed as a formula when the file is opened,
     * and issue titles are written by whoever reported the bug. Prefixing with a
     * single quote is what every spreadsheet reads as "this is text".
     */
    private function safe(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
