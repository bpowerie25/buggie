<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant. Everything else in the system hangs off one of these.
 *
 * Note this model is NOT workspace-scoped itself — it is the thing being scoped to.
 */
#[Fillable(['name', 'slug', 'owner_id', 'settings', 'trial_ends_at'])]
class Workspace extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Subdomains that can never belong to a customer, because we need them
     * (or because handing them out invites phishing).
     */
    public const RESERVED_SLUGS = [
        'www', 'api', 'app', 'admin', 'administrator', 'cdn', 'static', 'assets',
        'mail', 'email', 'smtp', 'in', 'inbound', 'help', 'support', 'docs', 'doc',
        'status', 'blog', 'about', 'billing', 'account', 'accounts', 'login',
        'signup', 'register', 'auth', 'oauth', 'sso', 'dashboard', 'widget', 'w',
        'cdn-widget', 'ingest', 'test', 'staging', 'dev', 'demo', 'buggy', 'root',
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
            ->withPivot(['role', 'invited_by_id', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class);
    }

    public static function slugIsAvailable(string $slug): bool
    {
        return ! in_array(strtolower($slug), self::RESERVED_SLUGS, true)
            && ! static::withTrashed()->where('slug', $slug)->exists();
    }
}
