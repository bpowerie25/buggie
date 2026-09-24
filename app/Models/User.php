<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Models\Concerns\HasTwoFactorAuthentication;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Users are global, not workspace-owned: one account can belong to several
 * workspaces (your own, plus every client workspace you were invited to).
 */
#[Fillable([
    'name', 'email', 'password', 'avatar_path', 'timezone',
    'last_workspace_id', 'notification_settings',
])]
// The two-factor columns are hidden as well as unfillable: a second factor that
// leaks into a serialised user — page props, an API payload, a log line — is a
// second factor somebody else also holds.
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasTwoFactorAuthentication, Notifiable;

    /**
     * Stated, so a freshly created user answers the operator gate without a reload;
     * strict mode throws on reading an attribute the model was never given.
     *
     * Deliberately not fillable. The first account on a self-hosted install and
     * `buggie:operator` are the only things that set it, both with forceFill.
     */
    protected $attributes = [
        'is_operator' => false,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_settings' => 'array',
            'is_operator' => 'boolean',

            // Encrypted at rest for the same reason mail.password is: a stolen
            // database dump must not be a stolen second factor. The recovery codes
            // need no such treatment — they are stored as hashes, and there is
            // nothing to decrypt them back into.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->withPivot(['role', 'invited_by_id', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    /** Projects a client has been granted access to. Empty for staff. */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withPivot('role')->withTimestamps();
    }

    public function membershipIn(Workspace $workspace): ?WorkspaceRole
    {
        $role = $this->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->value('workspace_user.role');

        return $role ? WorkspaceRole::from($role) : null;
    }

    public function belongsToWorkspace(Workspace $workspace): bool
    {
        return $this->membershipIn($workspace) !== null;
    }

    /** Opt-out rather than opt-in: silence should be chosen, not the default. */
    public function wantsNotification(\App\Enums\NotificationReason $reason): bool
    {
        return (bool) ($this->notification_settings[$reason->value] ?? $reason->defaultEnabled());
    }

    /**
     * A personal access token, scoped to one workspace.
     *
     * Sanctum's own createToken has nowhere to put the workspace, and setting it
     * afterwards means inserting a row that briefly belongs to no workspace — which
     * the NOT NULL constraint refuses, quite rightly. One insert, fully formed.
     *
     * @param  array<int, string>  $abilities
     */
    public function createTokenForWorkspace(
        Workspace $workspace,
        string $name,
        array $abilities = ['read'],
        ?\DateTimeInterface $expiresAt = null,
    ): \Laravel\Sanctum\NewAccessToken {
        $plainTextToken = $this->generateTokenString();

        $token = $this->tokens()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return new \Laravel\Sanctum\NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return strtoupper(collect($parts)->take(2)->map(
            fn (string $p) => mb_substr($p, 0, 1)
        )->implode(''));
    }
}
