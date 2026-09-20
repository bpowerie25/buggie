<?php

namespace App\Support\Chat;

use App\Models\ChatDelivery;
use App\Models\ChatIntegration;
use App\Support\Webhooks\SafeUrl;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The one place a message actually leaves the building.
 *
 * Shared by the queued job and by the Test button, so that an attempt is recorded
 * whichever route it took. A failure nobody can see is the thing this feature is
 * most likely to suffer from: a channel goes quiet and there is nothing to look at.
 */
class ChatSender
{
    /**
     * @throws UnsafeChatUrl when the address is not one we will call
     * @throws RuntimeException when the provider refused it, carrying their own words
     */
    public function send(ChatIntegration $integration, string $event, ChatNotice $notice, int $attempt = 1): void
    {
        $endpoint = $integration->url;

        // Re-checked here, not only when it was saved. DNS can be repointed after
        // the fact to aim a once-public name at an internal address, and this is
        // the last moment before our server makes the request.
        [$safe, $why] = SafeUrl::check($endpoint);

        if (! $safe) {
            $this->record($integration, $event, null, $why, $attempt);

            throw new UnsafeChatUrl($why ?? 'That address cannot be called.');
        }

        $body = $integration->provider->formatter()->body($notice, $endpoint);

        $started = microtime(true);

        try {
            $response = Http::asJson()
                ->withHeaders(['User-Agent' => 'Buggie-Chat/1'])
                ->timeout(10)
                ->connectTimeout(5)
                // Redirects are not followed: a redirect is how an address that
                // passed the safety check sends us somewhere that would not.
                ->withoutRedirecting()
                ->post($endpoint, $body);
        } catch (Throwable $e) {
            $this->record($integration, $event, null, $e->getMessage(), $attempt, $started);

            throw $e;
        }

        $this->record(
            $integration,
            $event,
            $response->status(),
            $response->successful() ? null : self::reason($response->status(), $response->body()),
            $attempt,
            $started,
        );

        if (! $response->successful()) {
            // Their words, not ours. "Could not send" tells nobody whether the URL
            // is mistyped, the channel was deleted or the integration was revoked —
            // and Slack and Teams both say which.
            throw new RuntimeException(self::reason($response->status(), $response->body()));
        }

        $integration->forceFill(['last_delivered_at' => now()])->saveQuietly();
    }

    private static function reason(int $status, string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');

        // An HTML error page is not a reason. Kept short enough to read in a flash
        // message, because that is where most people will meet it.
        $body = mb_substr($body, 0, 200);

        return $body === ''
            ? "The address replied {$status}."
            : "The address replied {$status}: {$body}";
    }

    private function record(
        ChatIntegration $integration,
        string $event,
        ?int $status,
        ?string $error,
        int $attempt,
        ?float $started = null,
    ): void {
        ChatDelivery::create([
            'chat_integration_id' => $integration->id,
            'event' => $event,
            'status' => $status,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
            'attempt' => $attempt,
            'duration_ms' => $started === null ? null : (int) ((microtime(true) - $started) * 1000),
            'created_at' => now(),
        ]);
    }
}
