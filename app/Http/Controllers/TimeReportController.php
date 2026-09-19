<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Time\Duration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the team spent its time on.
 *
 * The reason to log time at all: an agency at the end of a month needs one screen
 * that says where the hours went, per client and per person, and can hand the rows
 * to whoever does the invoicing.
 */
class TimeReportController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TimeEntry::class);

        [$from, $to] = $this->range($request);

        $entries = $this->query($request, $from, $to)
            ->with(['user:id,name', 'issue:id,key,title,project_id', 'issue.project:id,name,slug'])
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        return Inertia::render('time/index', [
            'entries' => $entries->map(fn (TimeEntry $entry) => [
                'id' => $entry->id,
                'minutes' => $entry->minutes,
                'duration' => $entry->formatted(),
                'spent_on' => $entry->spent_on->toDateString(),
                'note' => $entry->note,
                'billable' => $entry->billable,
                // A deleted account keeps its hours; they were still worked, and may
                // already be on an invoice.
                'user' => $entry->user?->name ?? 'Someone who has left',
                'issue' => $entry->issue ? [
                    'key' => $entry->issue->key,
                    'title' => $entry->issue->title,
                    'project' => $entry->issue->project?->name,
                ] : null,
                'can_delete' => $request->user()->can('delete', $entry),
            ]),

            // Totals come from the database, not from the 500 rows above: a month
            // with more entries than that would otherwise show a total quietly
            // smaller than the truth.
            'totals' => $this->totals($request, $from, $to),
            'byPerson' => $this->grouped($request, $from, $to, 'user'),
            'byProject' => $this->grouped($request, $from, $to, 'project'),

            'filters' => [
                'from' => $from,
                'to' => $to,
                'user_id' => $request->integer('user_id') ?: null,
                'project_id' => $request->integer('project_id') ?: null,
                'billable' => $request->query('billable'),
            ],
            'people' => User::whereHas('workspaces', fn ($q) => $q->whereKey($this->workspaceId()))
                ->orderBy('name')->get(['id', 'name']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', TimeEntry::class);

        [$from, $to] = $this->range($request);

        $builder = $this->query($request, $from, $to)
            ->with(['user:id,name', 'issue:id,key,title,project_id', 'issue.project:id,name'])
            ->orderBy('spent_on');

        $filename = "buggie-time-{$from}-to-{$to}.csv";

        return response()->streamDownload(function () use ($builder) {
            $out = fopen('php://output', 'w');

            fwrite($out, "\u{FEFF}");

            fputcsv($out, [
                'date', 'person', 'project', 'issue', 'title',
                // Both, deliberately. Minutes are the record; hours are what an
                // invoice is written in, and doing that conversion in a spreadsheet
                // is where rounding arguments come from.
                'minutes', 'hours', 'billable', 'note',
            ]);

            $builder->chunk(500, function ($entries) use ($out) {
                foreach ($entries as $entry) {
                    fputcsv($out, [
                        $entry->spent_on->toDateString(),
                        $entry->user?->name ?? 'Someone who has left',
                        $entry->issue?->project?->name,
                        $entry->issue?->key,
                        $this->safe($entry->issue?->title),
                        $entry->minutes,
                        Duration::toHours($entry->minutes),
                        $entry->billable ? 'yes' : 'no',
                        $this->safe($entry->note),
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return Builder<TimeEntry> */
    private function query(Request $request, string $from, string $to): Builder
    {
        return TimeEntry::query()
            ->between($from, $to)
            ->when($request->integer('user_id'), fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when(
                $request->integer('project_id'),
                fn (Builder $q, $id) => $q->whereHas('issue', fn (Builder $i) => $i->where('project_id', $id)),
            )
            ->when(
                $request->query('billable') !== null && $request->query('billable') !== '',
                fn (Builder $q) => $q->where('billable', $request->boolean('billable')),
            );
    }

    /** @return array{minutes: int, hours: string, billable_minutes: int, entries: int} */
    private function totals(Request $request, string $from, string $to): array
    {
        $all = (int) $this->query($request, $from, $to)->sum('minutes');
        $billable = (int) $this->query($request, $from, $to)->where('billable', true)->sum('minutes');

        return [
            'minutes' => $all,
            'duration' => Duration::format($all),
            'hours' => Duration::toHours($all),
            'billable_minutes' => $billable,
            'billable_duration' => Duration::format($billable),
            'billable_hours' => Duration::toHours($billable),
            'entries' => $this->query($request, $from, $to)->count(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function grouped(Request $request, string $from, string $to, string $by): array
    {
        $rows = $this->query($request, $from, $to)
            ->when($by === 'user', fn (Builder $q) => $q
                ->selectRaw('user_id as id, sum(minutes) as minutes')
                ->groupBy('user_id'))
            ->when($by === 'project', fn (Builder $q) => $q
                ->join('issues', 'issues.id', '=', 'time_entries.issue_id')
                ->selectRaw('issues.project_id as id, sum(minutes) as minutes')
                ->groupBy('issues.project_id'))
            ->get();

        $names = $by === 'user'
            ? User::whereIn('id', $rows->pluck('id'))->pluck('name', 'id')
            : Project::whereIn('id', $rows->pluck('id'))->pluck('name', 'id');

        return $rows
            ->map(fn ($row) => [
                'name' => $names[$row->id] ?? ($by === 'user' ? 'Someone who has left' : 'Unknown'),
                'minutes' => (int) $row->minutes,
                'duration' => Duration::format((int) $row->minutes),
                'hours' => Duration::toHours((int) $row->minutes),
            ])
            ->sortByDesc('minutes')
            ->values()
            ->all();
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request): array
    {
        // This calendar month by default: the period an agency actually reports on.
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now();

        return [$from->toDateString(), $to->toDateString()];
    }

    private function workspaceId(): int
    {
        return app(\App\Support\Tenancy\Tenancy::class)->currentOrFail()->id;
    }

    /** A note or title beginning =, + or @ is a formula when a spreadsheet opens it. */
    private function safe(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
