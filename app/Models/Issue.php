<?php

namespace App\Models;

use App\Enums\IssueEventType;
use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\IssueVisibility;
use App\Enums\WatchReason;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'project_id', 'title', 'description', 'description_text', 'type', 'status_id',
    'priority', 'reporter_id', 'assignee_id', 'visibility', 'start_on', 'due_on', 'version_id',
])]
#[\Illuminate\Database\Eloquent\Attributes\ScopedBy([\App\Models\Scopes\LiveProjectScope::class])]
class Issue extends Model
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'description' => 'array',
            'environment' => 'array',
            'type' => IssueType::class,
            'priority' => IssuePriority::class,
            'visibility' => IssueVisibility::class,
            'client_audience' => \App\Enums\ClientAudience::class,
            'number' => 'integer',
            'occurrence_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'start_on' => 'date',
            'due_on' => 'date',
            // Deliberately absent from #[Fillable]: the chaser owns this, and a
            // request that could set it could silence a reminder.
            'due_reminded_on' => 'date',
            // The client conversation's state, none of it fillable: only
            // ClientConversation, UpdateIssue and the chaser write these.
            'awaiting_client_since' => 'datetime',
            'client_reminded_at' => 'datetime',
            'auto_closed_at' => 'datetime',
            'client_replied_at' => 'datetime',
            // Who sent a widget report and how sure we are. Informational; access
            // comes only from reporter_id. See ReporterLink.
            'reporter_identity' => \App\Enums\ReporterIdentity::class,
            'board_pinned_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function customFieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Issue::class, 'parent_id');
    }

    /**
     * Whether this issue may be given a parent at all.
     *
     * One level of hierarchy is the whole design. An issue that already has children
     * cannot become a child itself, or the tree deepens and every count on every
     * screen becomes a recursive walk.
     */
    public function canHaveParent(): bool
    {
        return ! $this->children()->exists();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class)->orderBy('name');
    }

    /**
     * The widget reports this issue was raised from, newest first.
     *
     * An issue accepted from triage keeps its screenshot as an attachment, but the
     * console, the network table, the browser and the route lived only on the report
     * — so everything the widget went to the trouble of collecting disappeared at
     * exactly the moment somebody decided the bug was worth fixing.
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class)->latest();
    }

    /** The release this is fixed in, or planned for. */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->oldest();
    }

    public function events(): HasMany
    {
        return $this->hasMany(IssueEvent::class)->oldest();
    }

    public function relations(): HasMany
    {
        return $this->hasMany(IssueRelation::class);
    }

    /**
     * What this issue's schedule was when somebody loaded it, for refusing a change
     * made on top of somebody else's.
     *
     * The dates as well as updated_at, because timestamps are whole seconds: two
     * drags in the same second still disagree unless they chose the same dates, and
     * then there is nothing to lose.
     */
    public function scheduleVersion(): string
    {
        return substr(sha1(implode('|', [
            $this->updated_at?->format('Y-m-d H:i:s'),
            $this->start_on?->toDateString(),
            $this->due_on?->toDateString(),
        ])), 0, 16);
    }

    /** Where it was before it started waiting on the client, to go back to. */
    public function statusBeforeWaiting(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_before_waiting_id');
    }

    /**
     * Clients this issue is shared with by name. Access, not notification: see the
     * migration for why this is not the watchers table.
     */
    public function clientShares(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'issue_client_shares')
            ->withPivot(['shared_by_id', 'created_at']);
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'issue_watchers')
            ->withPivot('reason')
            ->withTimestamps();
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /**
     * Append to the activity feed.
     *
     * Events default to internal, matching comments: something a client can see is a
     * decision someone makes, never an accident.
     */
    public function recordEvent(
        IssueEventType $type,
        array $data = [],
        ?User $actor = null,
        bool $isInternal = true,
    ): IssueEvent {
        return $this->events()->create([
            'user_id' => $actor?->id ?? auth()->id(),
            'type' => $type,
            'data' => $data,
            'is_internal' => $isInternal,
            'created_at' => now(),
        ]);
    }

    /** Add a watcher without disturbing an existing reason. */
    public function watch(?User $user, WatchReason $reason): void
    {
        if ($user === null) {
            return;
        }

        $this->watchers()->syncWithoutDetaching([
            $user->id => ['reason' => $reason->value],
        ]);
    }

    /**
     * Stop watching.
     *
     * Deliberately not "unless they are the assignee": somebody who has been handed
     * an issue and does not want the running commentary is entitled to that, and the
     * assignment still stands.
     */
    public function unwatch(?User $user): void
    {
        $user === null || $this->watchers()->detach($user->id);
    }

    public function isWatchedBy(?User $user): bool
    {
        return $user !== null && $this->watchers()->whereKey($user->id)->exists();
    }

    public function isOpen(): bool
    {
        return $this->status->category->isOpen();
    }

    /** Issues whose status sits in an open category. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $q) => $q->open());
    }

    /**
     * Raised by a client and not yet looked at: still in its project's triage status.
     *
     * Listed on the Triage screen beside the reports. Moving the issue to any other
     * status is what takes it off, so there is no separate "triaged" flag to forget.
     */
    public function scopeAwaitingTriage(Builder $query): Builder
    {
        return $query
            ->whereHas('status', fn (Builder $q) => $q->where('is_triage', true))
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('workspace_user')
                ->whereColumn('workspace_user.user_id', 'issues.reporter_id')
                ->whereColumn('workspace_user.workspace_id', 'issues.workspace_id')
                ->where('workspace_user.role', \App\Enums\WorkspaceRole::Client->value));
    }

    /**
     * What the Triage screen lists beside the reports: a client's new issue still in
     * "New", and any issue a client has answered that nobody on the team has opened
     * since. The second is how a reply is noticed when nobody holds or watches it.
     */
    public function scopeForTriage(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->awaitingTriage()
            ->orWhereNotNull('issues.client_replied_at'));
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $q) => $q->closed());
    }

    /**
     * Restrict to what a client may see: their own projects, client-visible only.
     * Applied by IssuePolicy and the controllers; never rely on the UI for this.
     */
    public function scopeVisibleToClient(Builder $query, User $user): Builder
    {
        /*
         * Two tiers, and the difference is the point of the grant.
         *
         * A **client manager** sees every client-visible issue in their project —
         * what a single "client" role used to mean for everybody, which quietly
         * assumed one client per project. A **client** sees only their own part of
         * it: what they reported, and what they were drawn into by commenting or
         * being mentioned, which is what makes somebody a watcher.
         *
         * `visibility = client` still gates everything. The tier decides how much of
         * the client-visible half somebody sees, never whether internal work leaks.
         */
        $managed = $user->projects()
            ->wherePivot('role', \App\Enums\ProjectRole::ClientManager->value)
            ->select('projects.id');

        $granted = $user->projects()->select('projects.id');

        /*
         * The issue's audience can widen that default, never narrow it, and never
         * past the project: every branch below sits inside "holds the project", so a
         * share naming somebody who has since lost the grant gives them nothing.
         *
         * - project:  every client holding the project, whatever their tier.
         * - specific: the default audience, plus the clients named in the shares.
         *   Shares count only while the issue says `specific`, so switching back to
         *   the default takes them away even if the rows are still there.
         */
        return $query
            ->where('visibility', IssueVisibility::Client->value)
            ->whereIn('issues.project_id', $granted)
            ->where(fn (Builder $audience) => $audience
                ->whereIn('issues.project_id', $managed)
                ->orWhere('issues.client_audience', \App\Enums\ClientAudience::Project->value)
                ->orWhere('issues.reporter_id', $user->id)
                ->orWhereHas('watchers', fn ($w) => $w->whereKey($user->id))
                ->orWhere(fn (Builder $shared) => $shared
                    ->where('issues.client_audience', \App\Enums\ClientAudience::Specific->value)
                    ->whereHas('clientShares', fn ($s) => $s->whereKey($user->id))));
    }

    /** Weighted full-text search over title and flattened description. */
    public function scopeSearch(Builder $query, string $terms): Builder
    {
        $terms = trim($terms);

        if ($terms === '') {
            return $query;
        }

        // An issue key typed into the search box should jump straight to it.
        if (preg_match('/^[A-Z][A-Z0-9]*-\d+$/i', $terms)) {
            return $query->where('key', strtoupper($terms));
        }

        return $query->whereRaw(
            "search_vector @@ websearch_to_tsquery('english', ?)",
            [$terms],
        );
    }
}
