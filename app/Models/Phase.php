<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stage of a project — Discovery, Design, Build, Launch — that issues are grouped
 * under on the timeline. It has no dates of its own; see the migration.
 */
#[Fillable(['project_id', 'name', 'position'])]
class Phase extends Model
{
    use BelongsToWorkspace, HasFactory;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function scopeInOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
