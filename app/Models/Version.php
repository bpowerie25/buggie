<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'name', 'description', 'released_at'])]
class Version extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return ['released_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    /**
     * Unreleased first, then most recently released.
     *
     * What somebody is working towards matters more than what already shipped, and
     * the last release matters more than the one before it.
     */
    public function scopeInWorkingOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('released_at IS NOT NULL')
            ->orderByRaw('released_at DESC NULLS LAST')
            ->orderBy('name');
    }
}
