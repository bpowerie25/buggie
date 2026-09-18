<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'query', 'layout', 'group_by', 'position', 'user_id', 'created_by_id'])]
class SavedView extends Model
{
    use BelongsToWorkspace, HasFactory;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function isShared(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Views this user may see: the shared ones plus their own.
     *
     * Shared views belong to the team. Their names describe internal process and can
     * name customers, so a client sees only views they made themselves.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $workspace = app(\App\Support\Tenancy\Tenancy::class)->current();
        $isStaff = $workspace && ($user->membershipIn($workspace)?->isStaff() ?? false);

        if (! $isStaff) {
            return $query->where('user_id', $user->id);
        }

        return $query->where(fn (Builder $q) => $q
            ->whereNull('user_id')
            ->orWhere('user_id', $user->id));
    }
}
