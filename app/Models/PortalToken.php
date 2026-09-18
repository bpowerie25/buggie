<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A reporter's way back to the bug they filed, without being made to create an
 * account. The token is the whole credential, so it is long, bound to a single issue,
 * and expires.
 */
#[Fillable(['issue_id', 'email', 'expires_at'])]
class PortalToken extends Model
{
    use BelongsToWorkspace, HasFactory;

    public const LIFETIME_DAYS = 90;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    protected static function booted(): void
    {
        static::creating(function (self $token) {
            $token->token ??= Str::random(48);
            $token->expires_at ??= now()->addDays(self::LIFETIME_DAYS);
        });
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function isValid(): bool
    {
        return $this->expires_at->isFuture();
    }

    public function url(): string
    {
        return central_url('portal/'.$this->token);
    }

    /** One live token per reporter per issue, reissued rather than duplicated. */
    public static function issueFor(Issue $issue, string $email): self
    {
        $existing = static::where('issue_id', $issue->id)
            ->where('email', $email)
            ->where('expires_at', '>', now())
            ->first();

        return $existing ?? static::create(['issue_id' => $issue->id, 'email' => $email]);
    }
}
