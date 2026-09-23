<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['issue_id', 'user_id', 'started_at', 'note', 'billable'])]
class RunningTimer extends Model
{
    use BelongsToWorkspace, HasFactory;

    /**
     * Past this, a timer was forgotten rather than worked.
     *
     * Stopping one that has run longer refuses to guess: nobody worked nineteen hours
     * straight, and writing down that they did is worse than asking. `Duration` caps a
     * single entry at 24 hours anyway, so this stays below it.
     */
    public const FORGOTTEN_AFTER_MINUTES = 12 * 60;

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'billable' => 'boolean'];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whole minutes elapsed, floored — a timer stopped at 89 seconds is one minute. */
    public function elapsedMinutes(): int
    {
        return (int) floor($this->started_at->diffInSeconds(now()) / 60);
    }

    public function wasForgotten(): bool
    {
        return $this->elapsedMinutes() > self::FORGOTTEN_AFTER_MINUTES;
    }
}
