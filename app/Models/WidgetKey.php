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
