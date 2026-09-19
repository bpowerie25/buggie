<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Support\Time\Duration;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['issue_id', 'user_id', 'minutes', 'spent_on', 'note', 'billable'])]
class TimeEntry extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'spent_on' => 'date',
            'billable' => 'boolean',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function formatted(): string
    {
        return Duration::format($this->minutes);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('spent_on', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('spent_on', '<=', $to));
    }
}
