<?php

namespace App\Models;

use App\Enums\AccessRequestStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\AccessRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stranger asking to be let in.
 *
 * Scoped like any other tenant model, so a workspace admin's screen can only ever
 * hold their own workspace's requests. The exception is a request made on the
 * central domain, which belongs to no workspace and is for operators alone; those
 * are created through forOperators(), and read with acrossAllWorkspaces().
 */
#[Fillable(['name', 'email', 'organisation', 'message'])]
class AccessRequest extends Model
{
    /** @use HasFactory<AccessRequestFactory> */
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AccessRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * A request with no workspace, for operators to decide.
     *
     * Without model events, because BelongsToWorkspace would otherwise stamp the
     * current workspace or, on the central domain, refuse for want of one. Named, so
     * that the one place a tenant row is written without a tenant is easy to find.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function forOperators(array $attributes): self
    {
        return static::withoutEvents(function () use ($attributes) {
            $request = new static($attributes);
            $request->workspace_id = null;
            $request->save();

            return $request;
        });
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class)->withoutGlobalScopes();
    }

    public function isPending(): bool
    {
        return $this->status === AccessRequestStatus::Pending;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AccessRequestStatus::Pending->value);
    }
}
