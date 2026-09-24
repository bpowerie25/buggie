<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that changed about a member's access: for now, a client's tier on a
 * project. Shown on the members screen, to the people who can make the change.
 */
#[Fillable(['actor_id', 'user_id', 'project_id', 'type', 'data', 'created_at'])]
class MemberEvent extends Model
{
    use BelongsToWorkspace;

    public const TIER_CHANGED = 'tier_changed';

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public static function tierChanged(User $actor, User $client, Project $project, string $from, string $to): self
    {
        return static::create([
            'actor_id' => $actor->id,
            'user_id' => $client->id,
            'project_id' => $project->id,
            'type' => self::TIER_CHANGED,
            'data' => ['from' => $from, 'to' => $to],
            'created_at' => now(),
        ]);
    }
}
