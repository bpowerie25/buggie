<?php

namespace App\Support\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Whether the backups are actually running.
 *
 * Backups fail quietly and by default nobody finds out until the day they are needed.
 * The script writes what it did here; this reads it and says whether anybody should
 * be worried. The same reasoning as the mail banner: a failure with no symptom is the
 * one worth building machinery for.
 *
 * Deliberately not a health check that runs a backup. It reports on the scheduled one,
 * because "can we take a backup right now" and "have we been taking them" are
 * different questions and only the second one matters at three in the morning.
 */
class BackupStatus
{
    private const FILE = 'backup-status.json';

    /**
     * How long after a nightly run before silence is a problem.
     *
     * Thirty-six hours, not twenty-four: a backup that starts at 03:00 and takes a
     * while, or a clock that drifted, should not raise an alarm every morning. Two
     * missed nights is a real fault; one late one is not.
     */
    private const STALE_AFTER_HOURS = 36;

    /** @return array<string, mixed>|null */
    private function read(): ?array
    {
        try {
            if (! Storage::disk('local')->exists(self::FILE)) {
                return null;
            }

            $decoded = json_decode(Storage::disk('local')->get(self::FILE), true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            // Unreadable is the same as absent as far as anybody looking at a banner
            // is concerned, and a broken status file must never take the app down.
            return null;
        }
    }

    public function lastRunAt(): ?CarbonImmutable
    {
        $at = $this->read()['at'] ?? null;

        if (! is_string($at)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($at);
        } catch (Throwable) {
            return null;
        }
    }

    public function succeeded(): bool
    {
        return ($this->read()['ok'] ?? false) === true;
    }

    public function isOffsite(): bool
    {
        return ($this->read()['offsite'] ?? false) === true;
    }

    public function isStale(): bool
    {
        $at = $this->lastRunAt();

        return $at === null || $at->addHours(self::STALE_AFTER_HOURS)->isPast();
    }

    /**
     * What to tell an operator, or null when there is nothing to say.
     *
     * Ordered by how bad it is. Only one thing is said at a time: a banner listing
     * three problems is a banner nobody reads to the end.
     */
    public function warning(): ?string
    {
        $status = $this->read();

        if ($status === null) {
            return 'No backup has ever been recorded. Check that deploy/backup.sh is scheduled.';
        }

        if (! $this->succeeded()) {
            $stage = $status['stage'] ?? 'unknown';
            $detail = $status['detail'] ?? 'no detail';

            return "The last backup failed at the {$stage} stage: {$detail}.";
        }

        if ($this->isStale()) {
            $at = $this->lastRunAt();

            return 'The last backup was '
                .($at === null ? 'a while ago' : $at->diffForHumans())
                .'. It should run nightly.';
        }

        if (! $this->isOffsite()) {
            return 'Backups are working but stay on this server, so they are lost with it. '
                .'Set BUGGIE_BACKUP_REMOTE.';
        }

        return null;
    }

    /** How loudly to say it. Off-site being unset is a risk, not a fault. */
    public function isSevere(): bool
    {
        $status = $this->read();

        return $status === null || ! $this->succeeded() || $this->isStale();
    }
}
