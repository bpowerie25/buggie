<?php

namespace App\Models;

use App\Support\Billing\Plan;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Cashier\Billable;

/**
 * The tenant. Everything else in the system hangs off one of these.
 *
 * Note this model is NOT workspace-scoped itself — it is the thing being scoped to.
 */
#[Fillable(['name', 'slug', 'owner_id', 'settings', 'trial_ends_at'])]
class Workspace extends Model
{
    use Billable, HasFactory, SoftDeletes;

    /**
     * Subdomains that can never belong to a customer, because we need them
     * (or because handing them out invites phishing).
     */
    public const RESERVED_SLUGS = [
        'www', 'api', 'app', 'admin', 'administrator', 'cdn', 'static', 'assets',
        'mail', 'email', 'smtp', 'in', 'inbound', 'help', 'support', 'docs', 'doc',
        'status', 'blog', 'about', 'billing', 'account', 'accounts', 'login',
        'signup', 'register', 'auth', 'oauth', 'sso', 'dashboard', 'widget', 'w',
        'cdn-widget', 'ingest', 'test', 'staging', 'dev', 'demo', 'buggie', 'root',
        'security', 'abuse', 'postmaster', 'webmaster', 'null', 'undefined',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot(['role', 'invited_by_id', 'invited_at', 'joined_at', 'weekly_hours', 'discipline'])
            ->withTimestamps();
    }

    /** What a workspace starts with, before anybody edits the list. */
    public const DEFAULT_DISCIPLINES = ['Developer', 'Designer', 'Project manager', 'QA', 'Content'];

    /**
     * The disciplines staff can be given, in the order the workload screen groups
     * them. Until somebody edits the list it is the defaults plus any already in use,
     * so a discipline typed in before there was a list is not quietly lost from it.
     *
     * @return array<int, string>
     */
    public function disciplines(): array
    {
        if (is_array($this->settings['disciplines'] ?? null)) {
            return array_values($this->settings['disciplines']);
        }

        return collect(self::DEFAULT_DISCIPLINES)
            ->merge($this->members()->wherePivotNotNull('discipline')->pluck('workspace_user.discipline'))
            ->unique(fn (string $d) => mb_strtolower($d))
            ->values()
            ->all();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class);
    }

    /**
     * The plan this workspace is actually entitled to right now.
     *
     * A live subscription wins; otherwise a running trial gets the trial plan; failing
     * both, the free plan. Nothing here reads Stripe — the subscription row is the
     * record, kept current by webhooks.
     */
    public function plan(): Plan
    {
        // Self-hosted installs are not metered: it is somebody's own server and
        // there is nothing to sell them.
        if (! config('buggie.hosted')) {
            return Plan::find('self_hosted');
        }

        $subscription = $this->subscription();

        if ($subscription && $subscription->valid() && ! $subscription->onTrial()) {
            $plan = Plan::forPriceId((string) $subscription->stripe_price);

            if ($plan !== null) {
                return $plan;
            }
        }

        if ($this->onGenericTrial() || $this->trial_ends_at?->isFuture()) {
            return Plan::find(config('plans.trial'));
        }

        return Plan::find(config('plans.default'));
    }

    /** Reports this calendar month — the metered quantity. */
    /**
     * Metered reports since the 1st.
     *
     * Duplicates past `plans.collapse_after` are not metered: they keep no payload,
     * so they cost nothing to store, and billing for them would contradict the one
     * thing this product promises loudest — that forty people hitting one broken
     * checkout is one issue rather than forty tickets.
     */
    public function reportsThisMonth(): int
    {
        return Report::withoutGlobalScopes()
            ->where('workspace_id', $this->id)
            ->where('metered', true)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /** @return array<string, array{used: int, limit: int|null, over: bool, near: bool}> */
    public function usage(): array
    {
        $plan = $this->plan();

        $counts = [
            'projects' => Project::withoutGlobalScopes()->where('workspace_id', $this->id)->count(),
            'members' => $this->members()->count(),
            'reports_per_month' => $this->reportsThisMonth(),
        ];

        $usage = [];

        foreach ($counts as $name => $used) {
            $limit = $plan->limit($name);

            $usage[$name] = [
                'used' => $used,
                'limit' => $limit,
                'over' => $limit !== null && $used >= $limit,
                'near' => $limit !== null && $used >= (int) floor($limit * config('plans.warn_at')),
            ];
        }

        return $usage;
    }

    public function isWithinLimit(string $name, int $additional = 1): bool
    {
        $limit = $this->plan()->limit($name);

        if ($limit === null) {
            return true;
        }

        return ($this->usage()[$name]['used'] + $additional - 1) < $limit;
    }

    /** Cashier looks here for the address to put on invoices. */
    public function stripeEmail(): ?string
    {
        return $this->owner?->email;
    }

    public function stripeName(): ?string
    {
        return $this->name;
    }

    public static function slugIsAvailable(string $slug): bool
    {
        return ! in_array(strtolower($slug), self::RESERVED_SLUGS, true)
            && ! static::withTrashed()->where('slug', $slug)->exists();
    }
}
