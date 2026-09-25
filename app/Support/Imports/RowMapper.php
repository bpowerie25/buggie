<?php

namespace App\Support\Imports;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Models\Issue;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
use App\Support\Issues\Assignable;
use App\Support\RichText\TiptapDocument;
use App\Support\Time\Duration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One CSV row, turned into something an issue can be made from.
 *
 * Every tracker names its statuses and priorities differently, so this maps by
 * meaning rather than by exact match, and says when it could not — an import that
 * silently drops the priority of four hundred issues is worse than one that tells
 * you it guessed.
 */
class RowMapper
{
    /** @var Collection<int, Status> */
    private Collection $statuses;

    /** @var Collection<int, User> */
    private Collection $members;

    /** @var Collection<int, Phase> */
    private Collection $phases;

    /** @var array{key: array<string, int>, source: array<string, int>}|null the project's issues, by key and by imported key */
    private ?array $index = null;

    public function __construct(private Project $project)
    {
        $this->statuses = $project->statuses()->get();
        // Staff only: an import names an assignee, and a client is never one. A client
        // named in the file is reported as unmatched rather than assigned.
        $this->members = $project->workspace->members()
            ->wherePivotIn('role', Assignable::roles())
            ->get();
        $this->phases = $project->phases()->get();
    }

    /**
     * @param  array<string, string>  $row
     * @return array{attributes: array<string, mixed>, notes: array<int, string>}
     */
    public function map(array $row): array
    {
        $notes = [];

        $status = $this->status($row['status'] ?? '', $notes);
        $priority = $this->priority($row['priority'] ?? '', $notes);
        $assignee = $this->person($row['assignee'] ?? '');

        if (($row['assignee'] ?? '') !== '' && $assignee === null) {
            // Named rather than swallowed: "who was this assigned to?" is the first
            // question somebody asks after an import.
            $notes[] = "No member matches assignee \"{$row['assignee']}\"; left unassigned.";
        }

        return [
            'attributes' => [
                'title' => mb_substr($row['title'] ?? '', 0, 250),
                'description' => $this->description($row['description'] ?? ''),
                'status_id' => $status,
                'priority' => $priority,
                'type' => $this->type($row['type'] ?? ''),
                'assignee_id' => $assignee?->id,
                'source_key' => ($row['source_key'] ?? '') === '' ? null : mb_substr($row['source_key'], 0, 60),
                'created_at' => $this->date($row['created_at'] ?? ''),
                'start_on' => $this->day($row['start_on'] ?? '', 'Start', $notes),
                'due_on' => $this->day($row['due_on'] ?? '', 'Due', $notes),
                'estimate_minutes' => $this->estimate($row['estimate'] ?? '', $notes),
                // By name; one that does not exist yet is created by the import.
                'phase' => trim($row['phase'] ?? '') === '' ? null : mb_substr(trim($row['phase']), 0, 60),
                // A Buggie key or another row's own key; resolved after every row is in.
                'parent' => trim($row['parent'] ?? '') === '' ? null : mb_substr(trim($row['parent']), 0, 60),
            ],
            'notes' => [
                ...$notes,
                ...(($row['phase'] ?? '') !== '' && $this->phaseId($row['phase']) === null
                    ? ['Phase "'.trim($row['phase']).'" does not exist yet; it will be created.']
                    : []),
            ],
        ];
    }

    /**
     * What a row would change on an existing issue: the fields whose cell is filled in
     * and differs. A blank cell leaves the field as it is — a spreadsheet with an
     * empty Assignee column is somebody who did not fill it in, not an instruction to
     * unassign four hundred issues.
     *
     * Keyed as UpdateIssue takes them, plus estimate_minutes, phase and parent,
     * which the import applies itself. A status or type the mapper had to guess at is
     * still a change: it said so in the notes.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    public function changes(Issue $issue, array $row): array
    {
        ['attributes' => $a] = $this->map($row);
        $filled = fn (string $column) => trim($row[$column] ?? '') !== '';
        $changes = [];

        if ($filled('title') && $a['title'] !== $issue->title) {
            $changes['title'] = $a['title'];
        }

        if ($filled('description') && TiptapDocument::toPlainText($a['description']) !== (string) $issue->description_text) {
            $changes['description'] = $a['description'];
        }

        if ($filled('status') && $a['status_id'] !== $issue->status_id) {
            $changes['status_id'] = $a['status_id'];
        }

        if ($filled('priority') && $a['priority'] !== $issue->priority->value) {
            $changes['priority'] = $a['priority'];
        }

        if ($filled('type') && $a['type'] !== $issue->type->value) {
            $changes['type'] = $a['type'];
        }

        if ($filled('assignee') && $a['assignee_id'] !== null && $a['assignee_id'] !== $issue->assignee_id) {
            $changes['assignee_id'] = $a['assignee_id'];
        }

        foreach (['start_on', 'due_on'] as $date) {
            if ($filled($date) && $a[$date] !== null && $a[$date] !== $issue->{$date}?->toDateString()) {
                $changes[$date] = $a[$date];
            }
        }

        if ($filled('estimate') && $a['estimate_minutes'] !== null && $a['estimate_minutes'] !== $issue->estimate_minutes) {
            $changes['estimate_minutes'] = $a['estimate_minutes'];
        }

        if ($a['phase'] !== null && $this->phaseId($a['phase']) !== $issue->phase_id) {
            $changes['phase'] = $a['phase'];
        }

        // Only when it names somebody else: the parent it already has is no change.
        if ($a['parent'] !== null && ($this->existing($a['parent'])?->id ?? 0) !== $issue->parent_id) {
            $changes['parent'] = $a['parent'];
        }

        return $changes;
    }

    /** An existing phase of this project by name, ignoring case, or null. */
    public function phaseId(string $name): ?int
    {
        $name = mb_strtolower(trim($name));

        return $this->phases->first(fn ($phase) => mb_strtolower($phase->name) === $name)?->id;
    }

    /** A phase by name, created at the end of the list if it is not there yet. */
    public function phaseFor(string $name): int
    {
        if (($id = $this->phaseId($name)) !== null) {
            return $id;
        }

        $phase = $this->project->phases()->create([
            'name' => trim($name),
            'position' => (int) $this->phases->max('position') + 1,
        ]);

        $this->phases->push($phase);

        return $phase->id;
    }

    /**
     * An estimate in hours, as a planning spreadsheet writes it: "4", "2.5", or with
     * units, "3h 30m", "90m". A bare number is hours here, unlike the time box on an
     * issue, because a column headed Estimate full of 2s and 4s means hours.
     *
     * @param  array<int, string>  $notes
     */
    private function estimate(string $value, array &$notes): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (is_numeric(str_replace(',', '.', $value))) {
            $minutes = (int) round((float) str_replace(',', '.', $value) * 60);
        } else {
            $minutes = Duration::tryParse($value);
        }

        if ($minutes === null || $minutes <= 0) {
            $notes[] = "Estimate \"{$value}\" is not a length of time; left out.";

            return null;
        }

        return $minutes;
    }

    /**
     * A calendar date, as the ISO string an issue stores. Said so when it does not
     * parse, rather than quietly dropped.
     *
     * @param  array<int, string>  $notes
     */
    private function day(string $value, string $label, array &$notes): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $date = self::parseDate($value);

        if ($date === null) {
            $notes[] = "{$label} date \"{$value}\" is not a date; left out.";
        }

        return $date?->toDateString();
    }

    /**
     * 2026-10-14, or 14/10/2026 read day first, as a spreadsheet in Ireland or
     * Britain writes it. Parsed any other way, 12/10 would quietly become the
     * tenth of December.
     */
    public static function parseDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        try {
            if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $value, $m)) {
                return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                    ? CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1])
                    : null;
            }

            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The issue a row's key already refers to, if any: one of this project's own keys
     * (WEB-12), or the key an earlier import brought it in under.
     */
    public function existing(?string $key): ?Issue
    {
        $id = $this->matchId($key);

        return $id === null ? null : Issue::with('status')->find($id);
    }

    /**
     * The id alone, from one query for the whole project rather than one per row —
     * the preview asks this of every row in a twenty-thousand-row file.
     */
    public function matchId(?string $key): ?int
    {
        if ($key === null || trim($key) === '') {
            return null;
        }

        if ($this->index === null) {
            $issues = Issue::where('project_id', $this->project->id)->get(['id', 'key', 'source_key']);
            $this->index = [
                'key' => $issues->mapWithKeys(fn ($i) => [mb_strtoupper($i->key) => $i->id])->all(),
                'source' => $issues->whereNotNull('source_key')->mapWithKeys(fn ($i) => [$i->source_key => $i->id])->all(),
            ];
        }

        $key = trim($key);

        return $this->index['key'][mb_strtoupper($key)] ?? $this->index['source'][$key] ?? null;
    }

    /** Forget what was loaded, after rows have been created. */
    public function refresh(): void
    {
        $this->index = null;
    }

    /** @param array<int, string> $notes */
    private function status(string $value, array &$notes): int
    {
        $match = $this->statuses->first(
            fn (Status $status) => mb_strtolower($status->name) === mb_strtolower($value)
        );

        if ($match !== null) {
            return $match->id;
        }

        // Closed-sounding things land in a done status rather than the default open
        // one, because importing a decade of resolved bugs as "Todo" is the single
        // most annoying thing an import can do.
        $closed = ['closed', 'resolved', 'done', 'fixed', 'complete', 'completed', 'verified'];

        if (in_array(mb_strtolower($value), $closed, true)) {
            $done = $this->statuses->first(fn (Status $s) => $s->category->value === 'done');

            if ($done !== null) {
                if ($value !== '') {
                    $notes[] = "Status \"{$value}\" mapped to {$done->name}.";
                }

                return $done->id;
            }
        }

        $default = $this->statuses->firstWhere('is_default', true) ?? $this->statuses->first();

        if ($value !== '' && $default !== null) {
            $notes[] = "Status \"{$value}\" is not in this project; used {$default->name}.";
        }

        return $default->id;
    }

    /** @param array<int, string> $notes */
    private function priority(string $value, array &$notes): int
    {
        return match (mb_strtolower($value)) {
            'blocker', 'immediate', 'urgent', 'highest', 'critical' => IssuePriority::Urgent->value,
            'high', 'major' => IssuePriority::High->value,
            'medium', 'normal' => IssuePriority::Medium->value,
            'low', 'minor', 'trivial', 'lowest' => IssuePriority::Low->value,
            default => IssuePriority::None->value,
        };
    }

    private function type(string $value): string
    {
        return match (mb_strtolower($value)) {
            'bug', 'defect', 'crash' => IssueType::Bug->value,
            'feature', 'new feature', 'story', 'enhancement', 'improvement' => IssueType::Feature->value,
            'task', 'sub-task', 'subtask', 'chore' => IssueType::Task->value,
            'question', 'support' => IssueType::Question->value,
            default => IssueType::Bug->value,
        };
    }

    /** Matched on email first, then exact name. Never fuzzily: a wrong assignee is worse than none. */
    private function person(string $value): ?User
    {
        if ($value === '') {
            return null;
        }

        return $this->members->first(
            fn (User $user) => mb_strtolower($user->email) === mb_strtolower($value)
                || mb_strtolower($user->name) === mb_strtolower($value)
        );
    }

    /** @return array<string, mixed>|null */
    private function description(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        // Exported descriptions are plain text, often with wiki markup that would be
        // worse rendered than left alone. Kept as paragraphs.
        return [
            'type' => 'doc',
            'content' => array_map(
                fn (string $line) => $line === ''
                    ? ['type' => 'paragraph']
                    : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $line]]],
                preg_split('/\r\n|\r|\n/', mb_substr($value, 0, 20_000)) ?: [],
            ),
        ];
    }

    private function date(string $value): ?CarbonImmutable
    {
        // Preserved where it parses: an imported backlog that all arrived today
        // loses the one thing that made it a history.
        return $value === '' ? null : self::parseDate($value);
    }
}
