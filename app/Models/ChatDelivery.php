<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to post into a channel.
 *
 * The same reasoning as WebhookDelivery: a channel that quietly stopped receiving
 * messages is indistinguishable from a quiet week unless the attempts are kept. The
 * error text matters more here than for webhooks, because Slack and Teams both
 * answer a revoked or mistyped URL with a specific reason worth reading.
 */
class ChatDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'chat_integration_id', 'event', 'status', 'error', 'attempt', 'duration_ms', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(ChatIntegration::class, 'chat_integration_id');
    }

    public function succeeded(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
