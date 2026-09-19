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
    'name', 'key', 'slug', 'description', 'site_url', 'default_assignee_id', 'is_archived',
    'settings',
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

    protected static function booted(): void
    {
        static::creating(function (self $project) {
            // The suffix in bugs+{token}@in.buggie.eu. Random rather than derived from
            // the slug, so guessing one project's address does not reveal another's.
            $project->inbound_token ??= \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(16));
        });
    }

    public function inboundAddress(): string
    {
        return 'bugs+'.$this->inbound_token.'@'.config('buggie.inbound_domain');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class)->orderBy('position');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * The origin allowlist a new widget key starts with.
     *
     * Usually the UAT or staging site rather than the live one: reporting generally
     * belongs where testing happens, and a widget on production is a "Report a bug"
     * button in front of the client's own customers.
     *
     * Derived from `site_url` rather than stored twice, so changing where the
     * application lives does not leave a stale list behind on the project.
     *
     * Returns an empty list when no site URL is set, which the ingest endpoint reads
     * as "any origin" — the only thing that can work when we do not know where the
     * application runs.
     */
    public function defaultWidgetOrigins(): array
    {
        if (! $this->site_url) {
            return [];
        }

        $parts = parse_url($this->site_url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return [];
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $host = strtolower($parts['host']);

        $origins = [$scheme.'://'.$host.$port];

        // A site served at both acme.com and www.acme.com sends whichever origin the
        // visitor happened to be on. Allowing only the one they typed here produces a
        // widget that works for some of their users and not others, with nothing on
        // screen to explain it.
        $sibling = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;
        $origins[] = $scheme.'://'.$sibling.$port;

        return array_values(array_unique($origins));
    }

    public function versions(): HasMany
    {
        return $this->hasMany(Version::class);
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
     * Projects this person may know about at all.
     *
     * Staff see every project in the workspace. A client sees only the ones they were
     * granted — not merely a filtered issue list, but no knowledge that the others
     * exist. An agency runs several clients in one workspace, and one client learning
     * the names of another's projects is a leak even if they can read none of the work.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $workspace = app(\App\Support\Tenancy\Tenancy::class)->current();

        if ($workspace && ($user->membershipIn($workspace)?->isStaff() ?? false)) {
            return $query;
        }

        return $query->whereIn('id', $user->projects()->select('projects.id'));
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
