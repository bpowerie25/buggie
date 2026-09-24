<?php

namespace App\Models;

use App\Enums\ReportState;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id', 'widget_key_id', 'title', 'body', 'reporter_name', 'reporter_email',
    'reporter_ref', 'reporter_identity', 'environment', 'console', 'network', 'error', 'screenshot_path',
    'fingerprint', 'state', 'issue_id', 'ip_hash',
])]
class Report extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'environment' => 'array',
            'console' => 'array',
            'network' => 'array',
            'error' => 'array',
            'state' => ReportState::class,
            'triaged_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function widgetKey(): BelongsTo
    {
        return $this->belongsTo(WidgetKey::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function triagedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triaged_by_id');
    }

    public function scopeAwaitingTriage(Builder $query): Builder
    {
        return $query->where('state', ReportState::New->value);
    }
}
