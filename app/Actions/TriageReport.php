<?php

namespace App\Actions;

use App\Enums\IssueEventType;
use App\Enums\IssueVisibility;
use App\Enums\ReportState;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\Report;
use App\Models\User;
use App\Support\RichText\TiptapDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The four decisions available in the triage inbox. Each one ends with the report out
 * of the inbox, because an inbox that cannot be emptied is just a second backlog.
 */
class TriageReport
{
    public function __construct(private CreateIssue $createIssue) {}

    /** @param array<string, mixed> $attributes */
    public function accept(Report $report, User $actor, array $attributes = []): Issue
    {
        return DB::transaction(function () use ($report, $actor, $attributes) {
            $issue = $this->createIssue->handle($report->project, [
                'title' => $attributes['title'] ?? $report->title,
                'description' => $this->description($report),
                'priority' => $attributes['priority'] ?? 0,
                'assignee_id' => $attributes['assignee_id'] ?? null,
                'type' => $attributes['type'] ?? 'bug',
                // A report from a named person stays visible to them; they asked.
                'visibility' => $report->reporter_email
                    ? IssueVisibility::Client->value
                    : IssueVisibility::Internal->value,
            ], $actor);

            // Carry the captured context onto the issue, where it is actually useful.
            $issue->forceFill([
                'fingerprint' => $report->fingerprint,
                'environment' => $report->environment,
                'first_seen_at' => $report->created_at,
                'last_seen_at' => $report->created_at,
            ])->save();

            $this->attachScreenshot($report, $issue, $actor);

            $report->forceFill([
                'state' => ReportState::Promoted,
                'issue_id' => $issue->id,
                'triaged_by_id' => $actor->id,
                'triaged_at' => now(),
            ])->save();

            return $issue;
        });
    }

    public function merge(Report $report, Issue $issue, User $actor): Issue
    {
        return DB::transaction(function () use ($report, $issue, $actor) {
            $issue->forceFill([
                'occurrence_count' => $issue->occurrence_count + 1,
                'last_seen_at' => now(),
                // Adopt the fingerprint so later reports of this bug group themselves.
                'fingerprint' => $issue->fingerprint ?? $report->fingerprint,
            ])->save();

            $issue->recordEvent(IssueEventType::Occurrence, [
                'report_id' => $report->id,
                'count' => $issue->occurrence_count,
                'url' => $report->environment['url'] ?? null,
                'merged_by_hand' => true,
            ], $actor);

            $this->attachScreenshot($report, $issue, $actor);

            $report->forceFill([
                'state' => ReportState::Merged,
                'issue_id' => $issue->id,
                'triaged_by_id' => $actor->id,
                'triaged_at' => now(),
            ])->save();

            return $issue;
        });
    }

    public function dismiss(Report $report, User $actor, ReportState $state): void
    {
        $report->forceFill([
            'state' => $state,
            'triaged_by_id' => $actor->id,
            'triaged_at' => now(),
        ])->save();
    }

    /** Fold the reporter's words and the captured context into a tiptap document. */
    private function description(Report $report): array
    {
        $paragraphs = [];

        if ($report->body) {
            foreach (preg_split('/\n{2,}/', trim($report->body)) ?: [] as $chunk) {
                $paragraphs[] = $this->paragraph($chunk);
            }
        }

        if ($message = $report->error['message'] ?? null) {
            $paragraphs[] = $this->paragraph('Error: '.$message);
        }

        $reporter = $report->reporter_name ?? $report->reporter_email ?? 'an anonymous reporter';
        $where = $report->environment['url'] ?? 'an unknown page';
        $paragraphs[] = $this->paragraph("Reported from {$where} by {$reporter}.");

        $document = ['type' => 'doc', 'content' => $paragraphs];

        // Guard against an all-empty document, which the editor renders as nothing.
        return TiptapDocument::isEmpty($document)
            ? ['type' => 'doc', 'content' => [$this->paragraph($report->title)]]
            : $document;
    }

    /** @return array<string, mixed> */
    private function paragraph(string $text): array
    {
        return [
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }

    private function attachScreenshot(Report $report, Issue $issue, User $actor): void
    {
        if ($report->screenshot_path === null) {
            return;
        }

        $disk = Storage::disk('local');

        Attachment::create([
            'attachable_type' => $issue->getMorphClass(),
            'attachable_id' => $issue->id,
            'disk' => 'local',
            'path' => $report->screenshot_path,
            'filename' => 'screenshot.jpg',
            'mime' => 'image/jpeg',
            'size' => $disk->exists($report->screenshot_path)
                ? $disk->size($report->screenshot_path)
                : 0,
            'uploaded_by_id' => $actor->id,
        ]);

        $issue->recordEvent(IssueEventType::AttachmentAdded, ['filename' => 'screenshot.jpg'], $actor);
    }
}
