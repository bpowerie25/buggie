<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'key', 'slug', 'description', 'default_assignee_id', 'is_archived', 'settings',
])]
class Project extends Model
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_archived' => 'boolean',
            'issue_sequence' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class)->orderBy('position');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function widgetKeys(): HasMany
    {
        return $this->hasMany(WidgetKey::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function defaultStatus(): ?Status
    {
        return $this->statuses()->where('is_default', true)->first()
            ?? $this->statuses()->orderBy('position')->first();
    }

    public function defaultAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_assignee_id');
    }

    /** Clients explicitly granted access. Staff are not listed here. */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * Reserve the next issue number for this project.
     *
     * Locks the project row so concurrent requests cannot hand out the same number.
     * Must be called inside a transaction.
     */
    public function nextIssueNumber(): int
    {
        $current = static::query()
            ->whereKey($this->getKey())
            ->lockForUpdate()
            ->value('issue_sequence');

        // Null means the row is out of scope or gone. Silently restarting at 1 would
        // hand out an issue key that already exists.
        if ($current === null) {
            throw new \RuntimeException(
                "Cannot reserve an issue number for project [{$this->getKey()}]: "
                .'not found in the current workspace.'
            );
        }

        $number = $current + 1;

        static::query()->whereKey($this->getKey())->update(['issue_sequence' => $number]);

        $this->issue_sequence = $number;

        return $number;
    }
}
