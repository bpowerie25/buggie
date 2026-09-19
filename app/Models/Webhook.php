<?php

namespace App\Models;

use App\Enums\WebhookEvent;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['project_id', 'name', 'url', 'events', 'is_active'])]
#[Hidden(['secret'])]
class Webhook extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'last_delivered_at' => 'datetime',
            // Encrypted at rest, like the SMTP password: a stolen database dump
            // should not let somebody forge deliveries.
            'secret' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $webhook) {
            $webhook->secret ??= 'whsec_'.Str::random(40);
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class)->latest();
    }

    public function wants(WebhookEvent $event, ?int $projectId): bool
    {
        if (! $this->is_active || ! in_array($event->value, (array) $this->events, true)) {
            return false;
        }

        // A webhook with no project watches the whole workspace.
        return $this->project_id === null || $this->project_id === $projectId;
    }
}
