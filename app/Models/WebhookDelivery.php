<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to call a webhook.
 *
 * Kept so somebody can answer "is it working?" with something other than a shrug. A
 * webhook whose deliveries nobody can see is a webhook nobody can debug.
 */
class WebhookDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = ['webhook_id', 'event', 'status', 'error', 'attempt', 'duration_ms', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    public function succeeded(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
