<?php

namespace App\Jobs;

use App\Models\ChatIntegration;
use App\Models\Workspace;
use App\Support\Chat\ChatNotice;
use App\Support\Chat\ChatSender;
use App\Support\Chat\UnsafeChatUrl;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One message into one channel.
 *
 * Queued and retried for the same reasons as DeliverWebhook: Slack being slow must
 * not make closing an issue slow, and a provider having a bad five minutes is the
 * normal case rather than an exception.
 */
class DeliverChatMessage implements ShouldQueue
{
    use Queueable;

    /** Roughly a minute, ten minutes, an hour: down briefly, down for lunch, down. */
    public array $backoff = [60, 600, 3600];

    public int $tries = 4;

    public function __construct(
        public int $integrationId,
        public int $workspaceId,
        public string $event,
        public ChatNotice $notice,
    ) {}

    public function handle(Tenancy $tenancy, ChatSender $sender): void
    {
        $workspace = Workspace::find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        $tenancy->run($workspace, function () use ($sender) {
            $integration = ChatIntegration::find($this->integrationId);

            // Deleted or switched off since this was queued. Nothing to report.
            if ($integration === null || ! $integration->is_active) {
                return;
            }

            try {
                $sender->send($integration, $this->event, $this->notice, $this->attempts());
            } catch (UnsafeChatUrl $e) {
                // Not retried: it will not become safe by waiting. The attempt has
                // already been recorded against the integration.
                $this->fail($e);
            }
        });
    }
}
