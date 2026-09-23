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
    'priority', 'reporter_id', 'assignee_id', 'visibility', 'due_on', 'version_id',
])]
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
            'number' => 'integer',
            'occurrence_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'due_on' => 'date',
            // Deliberately absent from #[Fillable]: the chaser owns this, and a
            // request that could set it could silence a reminder.
            'due_reminded_on' => 'date',
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
        return $query
            ->where('visibility', IssueVisibility::Client->value)
            ->whereIn('project_id', $user->projects()->select('projects.id'));
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
