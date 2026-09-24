<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leave for one member of staff, or — with nobody named — a public holiday for all
 * of them. Whole days, inclusive at both ends.
 */
#[Fillable(['user_id', 'starts_on', 'ends_on', 'note', 'created_by_id'])]
class TimeOff extends Model
{
    use BelongsToWorkspace;

    protected $table = 'time_off';

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isHoliday(): bool
    {
        return $this->user_id === null;
    }

    /** Any part of it falling between two dates. */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->where('starts_on', '<=', $to)->where('ends_on', '>=', $from);
    }
}
