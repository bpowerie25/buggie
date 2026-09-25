<?php

namespace App\Support\Imports;

/**
 * Which tracker a CSV came out of, and where its columns are.
 *
 * Mantis and Jira both export CSV and both name their columns differently, so the
 * headers are enough to tell them apart. Anything else is treated generically, which
 * also covers a spreadsheet somebody typed by hand — the common case for a small
 * agency that has been tracking bugs in Google Sheets.
 */
class CsvFormat
{
    public const JIRA = 'jira';

    public const MANTIS = 'mantis';

    public const GENERIC = 'generic';

    /**
     * Column names we understand, per format, in order of preference.
     *
     * More than one name per field because exports differ by version and by which
     * columns somebody ticked on the way out.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const COLUMNS = [
        self::JIRA => [
            'source_key' => ['issue key', 'key'],
            'title' => ['summary'],
            'description' => ['description'],
            'status' => ['status'],
            'priority' => ['priority'],
            'type' => ['issue type', 'issuetype'],
            'assignee' => ['assignee'],
            'reporter' => ['reporter'],
            'created_at' => ['created'],
            'labels' => ['labels'],
            'due_on' => ['due date', 'due'],
        ],
        self::MANTIS => [
            'source_key' => ['id', 'issue id'],
            'title' => ['summary'],
            'description' => ['description'],
            'status' => ['status'],
            'priority' => ['priority', 'severity'],
            'type' => ['category'],
            'assignee' => ['assigned to', 'assigned_to', 'handler'],
            'reporter' => ['reporter'],
            'created_at' => ['date submitted', 'submitted'],
            'labels' => ['tags'],
            'due_on' => ['due date'],
        ],
        self::GENERIC => [
            'source_key' => ['id', 'key', 'ref', 'reference'],
            'title' => ['title', 'summary', 'subject', 'name'],
            'description' => ['description', 'details', 'body', 'notes'],
            'status' => ['status', 'state'],
            'priority' => ['priority', 'severity'],
            'type' => ['type', 'category'],
            'assignee' => ['assignee', 'assigned to', 'owner'],
            'reporter' => ['reporter', 'author', 'raised by'],
            'created_at' => ['created', 'created at', 'date', 'opened'],
            'labels' => ['labels', 'tags'],
            // The planning columns: when, how long, which stage, part of what.
            'start_on' => ['start', 'start date', 'starts', 'start on'],
            'due_on' => ['due', 'due date', 'deadline', 'due on'],
            'estimate' => ['estimate', 'estimate (hours)', 'estimated hours', 'hours'],
            'phase' => ['phase', 'stage'],
            'parent' => ['parent', 'parent key', 'part of'],
        ],
    ];

    /** @param array<int, string> $headers */
    public static function detect(array $headers): string
    {
        $normalised = array_map(self::normalise(...), $headers);

        // "Issue key" is Jira's and nobody else's. "Assigned To" with "Reporter" is
        // Mantis. Anything else is treated generically rather than guessed at.
        if (in_array('issue key', $normalised, true)) {
            return self::JIRA;
        }

        if (in_array('assigned to', $normalised, true) && in_array('reporter', $normalised, true)) {
            return self::MANTIS;
        }

        return self::GENERIC;
    }

    /**
     * Which column index holds which field.
     *
     * @param  array<int, string>  $headers
     * @return array<string, int>
     */
    public static function map(array $headers, string $format): array
    {
        $normalised = array_map(self::normalise(...), $headers);
        $mapping = [];

        foreach (self::COLUMNS[$format] ?? self::COLUMNS[self::GENERIC] as $field => $candidates) {
            foreach ($candidates as $candidate) {
                $index = array_search($candidate, $normalised, true);

                if ($index !== false) {
                    $mapping[$field] = $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    public static function label(string $format): string
    {
        return match ($format) {
            self::JIRA => 'Jira',
            self::MANTIS => 'MantisBT',
            default => 'CSV',
        };
    }

    private static function normalise(string $header): string
    {
        // Jira repeats column names for multi-valued fields ("Comment", "Comment"),
        // and exports often carry a byte-order mark on the first one.
        // Underscores read as spaces, so a file exported from Buggie ("due_on") comes
        // back in under the same names a person would type ("due on").
        return trim(mb_strtolower(preg_replace('/[\s_]+/', ' ', trim($header, "\u{FEFF} \t\n\r"))));
    }
}
