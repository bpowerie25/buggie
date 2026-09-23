<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened, kept so it can be read in the application.
 *
 * The same event also produces a `pending_notifications` row, which is a send queue
 * and is deleted once the digest goes out. This row is the record, and it outlives
 * the email — which matters rather a lot on an install where mail was never
 * configured, because there the email is the notification that never existed.
 *
 * Rows are not work product; `buggie:prune` ages them out.
 *
 * @property int $id
 * @property int $user_id
 * @property int $issue_id
 * @property int|null $actor_id
 * @property string $reason
 * @property array<string, mixed> $data
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $read_at
 */
#[Fillable(['user_id', 'issue_id', 'actor_id', 'reason', 'data', 'created_at'])]
class InAppNotification extends Model
{
    use BelongsToWorkspace;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Rows written to this person that still carry text they are allowed to read.
     *
     * Notifier refuses to record internal activity for a client, so a client's rows
     * never contain an internal comment's excerpt. Somebody who was staff when the
     * note was written and has since been demoted does hold such rows, and on an
     * issue that is genuinely shared with clients their excerpt would otherwise be
     * readable by exactly the person the internal/public split exists to keep it
     * from. So the question is asked again here, when the row is read.
     *
     * This says nothing about which issues the person may see. That is IssuePolicy's
     * job, and NotificationController asks it per row.
     */
    public function scopeAddressedTo(Builder $query, User $user): Builder
    {
        return $query
            ->where('user_id', $user->id)
            ->unless(
                $this->isStaff($user),
                // coalesce, not `where not`: a missing key compares as NULL in
                // Postgres and `NOT NULL` is NULL, which would drop every row that
                // has no `internal` key at all — which is most of them.
                fn (Builder $q) => $q->whereRaw("coalesce((data->>'internal')::boolean, false) = false"),
            );
    }

    /**
     * The above, plus the issue-visibility rules, in SQL.
     *
     * IssuePolicy is the authority on what somebody may see and it is consulted per
     * row wherever a notification is actually rendered. It cannot be consulted for
     * the unread badge: that runs on every page load, and a policy call per row
     * turns a counter into a table scan with a query attached to each row of it.
     *
     * So the badge gets this instead — the same rules, expressed once, in the same
     * `Issue::visibleToClient` scope the policy's client branch and the issue list
     * are built on. `visibleTo` and the policy can in principle drift; if they do,
     * the badge reads higher than the list, which is a wrong number rather than a
     * disclosure, and that is the direction to fail in.
     *
     * Also used by "mark all read", so that clearing the badge clears exactly what
     * the badge was counting.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query
            ->addressedTo($user)
            ->unless(
                $this->isStaff($user),
                fn (Builder $q) => $q->whereHas(
                    'issue',
                    fn (Builder $issues) => $issues->visibleToClient($user),
                ),
            );
    }

    /** Staff in the workspace currently bound — which is not where they started. */
    private function isStaff(User $user): bool
    {
        $workspace = app(Tenancy::class)->current();

        return $workspace !== null && ($user->membershipIn($workspace)?->isStaff() ?? false);
    }

    public function markRead(): void
    {
        if ($this->read_at !== null) {
            return;
        }

        $this->forceFill(['read_at' => now()])->save();
    }
}
