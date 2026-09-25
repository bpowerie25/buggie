<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Models\Issue;
use App\Support\Issues\IssueQuery;
use App\Support\Issues\IssueQueryFilter;
use App\Support\Tenancy\Tenancy;
use App\Support\Time\Duration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        private Tenancy $tenancy,
    ) {}

    private const COLUMNS = [
        'key', 'title', 'status', 'state', 'type', 'priority', 'project',
        'assignee', 'reporter', 'labels', 'visible_to_client', 'occurrences',
        'created_at', 'updated_at', 'closed_at', 'start_on', 'due_on', 'estimate', 'time_spent',
        'phase', 'parent', 'url',
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

        /*
         * The custom field columns, resolved once before anything is written.
         *
         * Keyed by `key` rather than by id, so two projects that both define
         * "browser" share one column instead of producing two half-empty ones. The
         * export can span projects; the header cannot depend on the first row.
         */
        $fields = CustomField::query()
            ->unless($staff, fn (Builder $q) => $q->where('visible_to_client', true))
            ->inOrder()
            ->get()
            ->unique('key')
            ->values();

        $builder = $this->filter->apply($builder, $query, $user)
            ->with([
                'status:id,name,category',
                'assignee:id,name',
                'reporter:id,name',
                'labels:id,name',
                'project:id,key,slug,name',
                'phase:id,name',
                'parent:id,key',
                // Constrained rather than filtered afterwards: an internal field's
                // value must not be read into memory on a client's export at all.
                'customFieldValues' => fn ($q) => $q->whereIn('custom_field_id', $fields->pluck('id')),
                'customFieldValues.field:id,key',
            ])
            // Summed in the query, not per row: reading $issue->timeEntries inside
            // the loop is an N+1 and a lazy-loading violation, which strict mode
            // turns into an exception after the headers have gone — a truncated file.
            ->withSum('timeEntries as time_spent_minutes', 'minutes')
            ->orderBy('id');

        $filename = 'buggie-issues-'.now()->format('Y-m-d').'.csv';

        // Read once rather than per row: it is the same workspace for every issue,
        // and touching $issue->workspace inside the loop is both an N+1 and a lazy
        // loading violation, which strict mode turns into an exception mid-stream —
        // after the headers have gone, so it surfaces as a truncated file.
        $slug = $this->tenancy->currentOrFail()->slug;

        return response()->streamDownload(function () use ($builder, $staff, $slug, $fields) {
            $out = fopen('php://output', 'w');

            // Excel reads a file without this as Latin-1 and mangles every accented
            // name in it. A byte-order mark is ugly and the alternative is worse.
            fwrite($out, "\u{FEFF}");

            fputcsv($out, [
                ...self::COLUMNS,
                // Prefixed for the same reason the query language prefixes them: a
                // project is free to name a field "title".
                ...$fields->map(fn ($field) => 'field:'.$field->key),
            ]);

            // Chunked, so exporting a workspace with fifty thousand issues does not
            // load fifty thousand models into memory to write them out one at a time.
            $builder->chunk(500, function ($issues) use ($out, $staff, $slug, $fields) {
                foreach ($issues as $issue) {
                    fputcsv($out, $this->row($issue, $staff, $slug, $fields));
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<int, string|int|null> */
    private function row(Issue $issue, bool $staff, string $slug, Collection $fields): array
    {
        $values = $issue->customFieldValues->keyBy(fn ($value) => $value->field?->key);

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
            $issue->start_on?->toDateString(),
            $issue->due_on?->toDateString(),
            // How long things were expected to take and did take is the team's
            // business, as it is everywhere else a client looks.
            $staff && $issue->estimate_minutes ? Duration::format($issue->estimate_minutes) : '',
            $staff && $issue->time_spent_minutes ? Duration::format((int) $issue->time_spent_minutes) : '',
            $this->safe($issue->phase?->name),
            // A parent can be internal work, and its key says it exists.
            $staff ? $issue->parent?->key : '',
            workspace_url($slug, "issues/{$issue->key}"),
            // Through safe() like every other free-text column: a custom field value
            // is typed by a person, and "=1+1" in a spreadsheet is a formula.
            ...$fields->map(fn ($field) => $this->safe($values[$field->key]?->value)),
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
