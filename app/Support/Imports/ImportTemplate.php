<?php

namespace App\Support\Imports;

use App\Enums\StatusCategory;
use App\Models\Project;
use App\Models\User;

/**
 * A spreadsheet to fill in, for somebody whose backlog lives in their head or in an
 * email thread rather than in another tracker.
 *
 * Only the columns the importer actually uses — a column that is read and then
 * dropped is a column somebody fills in for nothing — and example rows written in
 * this project's own words: its status names, not a generic "To do" that would not
 * match. The examples are keyed EXAMPLE-1 and so on, and the importer skips any row
 * keyed like that, so uploading the template untouched creates nothing.
 */
final class ImportTemplate
{
    public const EXAMPLE_PREFIX = 'EXAMPLE-';

    /** In the order the columns appear. Named as CsvFormat's generic format reads them. */
    public const HEADERS = ['Key', 'Title', 'Description', 'Status', 'Priority', 'Type', 'Assignee', 'Created'];

    public const PRIORITIES = ['Urgent', 'High', 'Medium', 'Low'];

    public const TYPES = ['Bug', 'Feature', 'Task', 'Question'];

    public static function isExample(?string $sourceKey): bool
    {
        return $sourceKey !== null && str_starts_with(strtoupper($sourceKey), self::EXAMPLE_PREFIX);
    }

    /** @return array<int, array<int, string>> */
    public static function rows(Project $project, User $downloader): array
    {
        $statuses = $project->statuses()->orderBy('position')->get();
        $default = $project->defaultStatus()?->name ?? '';
        $started = $statuses->firstWhere('category', StatusCategory::Started)?->name ?? $default;
        $done = $statuses->firstWhere('category', StatusCategory::Done)?->name ?? $default;

        return [
            self::HEADERS,
            [
                self::EXAMPLE_PREFIX.'1', 'Contact form does not send on Safari',
                'Clicking Send does nothing. Chrome is fine.', $default, 'High', 'Bug',
                $downloader->email, now()->subDays(3)->toDateString(),
            ],
            [
                self::EXAMPLE_PREFIX.'2', 'Add a newsletter sign-up to the footer',
                '', $started, 'Medium', 'Feature', '', now()->subDays(10)->toDateString(),
            ],
            [
                self::EXAMPLE_PREFIX.'3', 'Renew the SSL certificate',
                'Expires at the end of the month.', $done, 'Low', 'Task', '', now()->subMonth()->toDateString(),
            ],
        ];
    }

    /**
     * As a CSV Excel opens correctly: a UTF-8 byte-order mark first, or Excel reads
     * the file as the local code page and mangles every accent in a client's name.
     */
    public static function csv(Project $project, User $downloader): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");

        foreach (self::rows($project, $downloader) as $row) {
            fputcsv($out, $row, escape: '');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
