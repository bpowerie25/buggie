<?php

namespace App\Models;

use App\Enums\ChatProvider;
use App\Enums\WebhookEvent;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Slack or Teams channel Buggie posts into.
 *
 * `workspace_id` is not fillable on purpose; BelongsToWorkspace stamps it.
 */
#[Fillable(['project_id', 'provider', 'name', 'url', 'events', 'is_active', 'internal_activity'])]
#[Hidden(['url'])]
class ChatIntegration extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'provider' => ChatProvider::class,
            'events' => 'array',
            'is_active' => 'boolean',
            'internal_activity' => 'boolean',
            'last_delivered_at' => 'datetime',
            // The URL *is* the credential: whoever holds it can post into that
            // channel as us, and there is nothing else to present. Encrypted at
            // rest for the same reason as the SMTP password, and hidden above so
            // that serialising one of these can never put it on a page.
            'url' => 'encrypted',
        ];
    }

    /**
     * Whether there is a usable address, without being asked what it is.
     *
     * Not always true, despite the column being required: a row encrypted under a
     * previous APP_KEY cannot be decrypted, which is the same situation as never
     * having been set — and the settings page needs to be able to say so rather
     * than fail to render at all.
     */
    public function hasUrl(): bool
    {
        try {
            return ($this->url ?? '') !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ChatDelivery::class)->latest();
    }

    /**
     * Whether this integration wants to hear about one thing that happened.
     *
     * `$internal` is the activity's own visibility, not the channel's. Something
     * internal is announced only where somebody has said the channel is the team's
     * own, because Buggie cannot see who is in a channel it was handed a URL for.
     */
    public function wants(WebhookEvent $event, ?int $projectId, bool $internal = false): bool
    {
        if (! $this->is_active || ! in_array($event->value, (array) $this->events, true)) {
            return false;
        }

        if ($internal && ! $this->internal_activity) {
            return false;
        }

        // No project means the whole workspace.
        return $this->project_id === null || $this->project_id === $projectId;
    }
}
