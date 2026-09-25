<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use App\Support\Webhooks\SafeUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One POST to one webhook.
 *
 * Queued, because a customer's slow endpoint must not slow down the person who just
 * changed an issue — and retried, because an endpoint being briefly down is the
 * normal case rather than an exception.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /** Roughly a minute, ten minutes, an hour: down briefly, down for lunch, down. */
    public array $backoff = [60, 600, 3600];

    public int $tries = 4;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $webhookId,
        public int $workspaceId,
        public string $event,
        public array $payload,
    ) {}

    public function handle(Tenancy $tenancy): void
    {
        $workspace = Workspace::find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        $tenancy->run($workspace, function () {
            $webhook = Webhook::find($this->webhookId);

            // Deleted or switched off since this was queued. Nothing to report.
            if ($webhook === null || ! $webhook->is_active) {
                return;
            }

            // Re-checked at delivery, not only when saved. DNS can be changed after
            // the fact to point a once-public name at an internal address.
            [$safe, $why] = SafeUrl::check($webhook->url);

            if (! $safe) {
                $this->record($webhook, null, $why);

                // Not retried: it will not become safe by waiting.
                $this->fail(new \RuntimeException($why ?? 'Unsafe webhook URL.'));

                return;
            }

            $body = json_encode([
                'event' => $this->event,
                'delivered_at' => now()->toIso8601String(),
                'data' => $this->payload,
            ], JSON_UNESCAPED_SLASHES);

            $started = microtime(true);

            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Buggie-Webhook/1',
                    'X-Buggie-Event' => $this->event,
                    // So the receiver can tell our POST from anybody else's. Signed
                    // over the exact bytes sent, or the check means nothing.
                    'X-Buggie-Signature' => 'sha256='.hash_hmac('sha256', $body, $webhook->secret),
                ])
                    ->withBody($body, 'application/json')
                    ->timeout(10)
                    ->connectTimeout(5)
                    // Left to us, not the receiver: a redirect is how an endpoint
                    // that passed the safety check sends us somewhere that would not.
                    ->withoutRedirecting()
                    // To the address just checked, not a fresh lookup of the name.
                    ->withOptions(SafeUrl::pinned($webhook->url))
                    ->post($webhook->url);

                $this->record($webhook, $response->status(), null, $started);

                if ($response->failed()) {
                    throw new \RuntimeException("Endpoint replied {$response->status()}.");
                }

                $webhook->forceFill(['last_delivered_at' => now()])->saveQuietly();
            } catch (Throwable $e) {
                if (! isset($response)) {
                    $this->record($webhook, null, $e->getMessage(), $started);
                }

                throw $e;
            }
        });
    }

    private function record(Webhook $webhook, ?int $status, ?string $error, ?float $started = null): void
    {
        WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event' => $this->event,
            'status' => $status,
            // Truncated: an HTML error page is not a useful thing to keep a thousand
            // copies of.
            'error' => $error === null ? null : mb_substr($error, 0, 500),
            'attempt' => $this->attempts(),
            'duration_ms' => $started === null ? null : (int) ((microtime(true) - $started) * 1000),
            'created_at' => now(),
        ]);
    }
}
