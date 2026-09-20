<?php

namespace App\Http\Controllers;

use App\Enums\ChatProvider;
use App\Enums\WebhookEvent;
use App\Models\ChatIntegration;
use App\Support\Chat\ChatNotice;
use App\Support\Chat\ChatSender;
use App\Support\Tenancy\Tenancy;
use App\Support\Webhooks\SafeUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Slack and Teams channels, configured per workspace.
 *
 * The URL never comes back out of here. It is the whole credential — whoever holds
 * it can post into that channel as us — so the page is told whether one is set and
 * nothing more, exactly as the mail settings screen does with the SMTP password.
 */
class ChatIntegrationController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('update', $this->tenancy->currentOrFail());

        ChatIntegration::create($this->validated($request));

        return back()->with('success', 'Channel added. Send a test to be sure it arrives.');
    }

    public function update(Request $request, ChatIntegration $chatIntegration): RedirectResponse
    {
        $this->authorize('update', $this->tenancy->currentOrFail());

        $chatIntegration->update($this->validated($request, $chatIntegration));

        return back()->with('success', 'Channel saved.');
    }

    public function destroy(ChatIntegration $chatIntegration): RedirectResponse
    {
        $this->authorize('update', $this->tenancy->currentOrFail());

        $chatIntegration->delete();

        return back()->with('success', 'Channel removed.');
    }

    /**
     * Post one real message now, and say what the provider said if it refused.
     *
     * Sent inline rather than queued, unlike every other delivery, because the
     * answer is the entire point of the button. Queued, the screen could only ever
     * say "test queued" and the person would be back to reading a delivery log.
     * It is bounded by the sender's ten-second timeout.
     */
    public function test(Request $request, ChatIntegration $chatIntegration, ChatSender $sender): RedirectResponse
    {
        $this->authorize('update', $this->tenancy->currentOrFail());

        $notice = new ChatNotice(
            heading: 'Test message from Buggie',
            title: 'If you can read this, the channel is connected.',
            url: workspace_url($this->tenancy->currentOrFail()->slug, 'settings/workspace'),
            fields: ['Sent by' => $request->user()->name],
        );

        try {
            $sender->send($chatIntegration, 'ping', $notice);
        } catch (Throwable $e) {
            // Their words, not ours. Whether the URL is mistyped, the channel was
            // deleted or the integration was revoked are three different problems
            // with three different fixes, and only the provider knows which it is.
            return back()->withErrors(['chat' => 'Could not send: '.$e->getMessage()]);
        }

        return back()->with('success', "Sent to {$chatIntegration->name}.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ChatIntegration $existing = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'provider' => ['required', Rule::in(array_column(ChatProvider::cases(), 'value'))],
            // Required when creating; blank when editing means "leave it alone",
            // because the form has never been sent the current one to send back.
            'url' => [$existing === null ? 'required' : 'nullable', 'string', 'max:2048'],
            'project_id' => [
                'nullable',
                Rule::exists('projects', 'id')->where('workspace_id', $this->tenancy->id()),
            ],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_column(WebhookEvent::cases(), 'value'))],
            'is_active' => ['boolean'],
            'internal_activity' => ['boolean'],
        ]);

        if (($validated['url'] ?? '') === '' || $validated['url'] === null) {
            unset($validated['url']);

            return $validated;
        }

        // Checked here as well as at delivery. The moment somebody types it is the
        // only chance to tell them why it will not work.
        [$safe, $why] = SafeUrl::check($validated['url']);

        abort_if(! $safe, 422, $why ?? 'That address cannot be called.');

        return $validated;
    }
}
