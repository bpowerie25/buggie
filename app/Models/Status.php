<?php

namespace App\Models;

use App\Enums\StatusCategory;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'name', 'category', 'color', 'position', 'is_default'])]
class Status extends Model
{
    use BelongsToWorkspace, HasFactory;

    /** Seeded for every new project. Names are editable; categories are not. */
    public const DEFAULTS = [
        ['name' => 'Backlog',     'category' => 'backlog',   'color' => '#94a3b8', 'is_default' => false],
        ['name' => 'Todo',        'category' => 'unstarted', 'color' => '#64748b', 'is_default' => true],
        ['name' => 'In Progress', 'category' => 'started',   'color' => '#f59e0b', 'is_default' => false],
        ['name' => 'In Review',   'category' => 'started',   'color' => '#8b5cf6', 'is_default' => false],
        ['name' => 'Done',        'category' => 'done',      'color' => '#10b981', 'is_default' => false],
        ['name' => "Won't Fix",   'category' => 'canceled',  'color' => '#6b7280', 'is_default' => false],
    ];

    protected function casts(): array
    {
        return [
            'category' => StatusCategory::class,
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function issues(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('category', [
            StatusCategory::Backlog->value,
            StatusCategory::Unstarted->value,
            StatusCategory::Started->value,
        ]);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereIn('category', [
            StatusCategory::Done->value,
            StatusCategory::Canceled->value,
        ]);
    }
}
