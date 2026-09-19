<?php

namespace App\Support\Imports;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Models\Project;
use App\Models\Status;
use App\Models\User;
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

    public function __construct(private Project $project)
    {
        $this->statuses = $project->statuses()->get();
        $this->members = $project->workspace->members()->get();
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
            ],
            'notes' => $notes,
        ];
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
        if ($value === '') {
            return null;
        }

        try {
            // Preserved where it parses: an imported backlog that all arrived today
            // loses the one thing that made it a history.
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
