<?php

namespace App\Models;

use App\Enums\IssueEventType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// created_at is fillable because $timestamps is off: the feed is append-only and
// events are written with an explicit time, never touched afterwards.
#[Fillable(['issue_id', 'user_id', 'type', 'data', 'is_internal', 'created_at'])]
class IssueEvent extends Model
{
    use BelongsToWorkspace, HasFactory;

    public $timestamps = false;

    // The column is timestamp(6), but Eloquent's default date format writes whole
    // seconds. Both are needed for the activity feed to sort deterministically.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'type' => IssueEventType::class,
            'data' => 'array',
            'is_internal' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }
}
