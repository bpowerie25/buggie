<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'issue_id', 'user_id', 'author_name', 'author_email',
    'body', 'body_text', 'is_internal', 'source',
])]
class Comment extends Model
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    // The column is timestamp(6), but Eloquent's default date format writes whole
    // seconds. Both are needed for the activity feed to sort deterministically.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'body' => 'array',
            'is_internal' => 'boolean',
            'edited_at' => 'datetime',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** Comments from the portal or email have no account behind them. */
    public function displayName(): string
    {
        return $this->author?->name ?? $this->author_name ?? $this->author_email ?? 'Unknown';
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }
}
