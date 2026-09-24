<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'project_id', 'allowed_origins', 'mode', 'require_email',
    'capture_screenshot', 'is_active',
])]
// The secret signs reporter identities. Hidden as well as unfillable: it must never
// reach a browser — not the embed script, not page props, not a JSON response.
#[\Illuminate\Database\Eloquent\Attributes\Hidden(['secret'])]
class WidgetKey extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
            'require_email' => 'boolean',
            'capture_screenshot' => 'boolean',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            // Encrypted at rest with APP_KEY: a database dump is not a way to forge a
            // verified report.
            'secret' => 'encrypted',
            'secret_rotated_at' => 'datetime',
        ];
    }

    /**
     * The key is generated here rather than by callers: it is NOT NULL and not
     * fillable, so every create() would otherwise have to force-fill it before the
     * insert, and forgetting is a runtime error rather than a visible mistake.
     */
    protected static function booted(): void
    {
        static::creating(function (self $key) {
            $key->public_key ??= self::generateKey();
            $key->secret ??= self::generateSecret();
            $key->secret_rotated_at ??= now();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_key';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public static function generateSecret(): string
    {
        return 'whs_'.Str::random(40);
    }

    /** A new secret, returned so it can be shown once. The old one stops working. */
    public function rotateSecret(): string
    {
        $secret = self::generateSecret();

        $this->forceFill(['secret' => $secret, 'secret_rotated_at' => now()])->save();

        return $secret;
    }

    /**
     * Whether a user_hash proves this id and email: HMAC-SHA256 of "id:email" with
     * the secret, compared in constant time. Both parts are signed because a report
     * is linked to a client by its email, and a hash over the id alone would let a
     * signed-in user pair their own valid hash with somebody else's address.
     */
    public function verifiesIdentity(?string $id, ?string $email, ?string $hash): bool
    {
        if ($this->secret === null || $id === null || $id === '' || $email === null || $email === '' || $hash === null || $hash === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $id.':'.$email, $this->secret), strtolower($hash));
    }

    public function mode(): \App\Enums\WidgetMode
    {
        return \App\Enums\WidgetMode::tryFrom((string) $this->mode) ?? \App\Enums\WidgetMode::Identified;
    }

    public static function generateKey(): string
    {
        return 'pk_'.Str::lower(Str::random(24));
    }

    /**
     * Origin allowlist.
     *
     * An empty list accepts any origin, which is the only workable default for a
     * paste-this-snippet install, so the project settings screen says so plainly.
     * Entries may be exact (`https://acme.com`) or a wildcard subdomain
     * (`https://*.acme.com`).
     */
    public function allowsOrigin(?string $origin): bool
    {
        $allowed = $this->allowed_origins ?? [];

        if ($allowed === []) {
            return true;
        }

        if ($origin === null || $origin === '') {
            return false;
        }

        $origin = rtrim(strtolower($origin), '/');

        foreach ($allowed as $pattern) {
            $pattern = rtrim(strtolower(trim($pattern)), '/');

            if ($pattern === $origin) {
                return true;
            }

            if (str_contains($pattern, '*') && Str::is($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }
}
