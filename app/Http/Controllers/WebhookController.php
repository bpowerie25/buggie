<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Project;
use App\Models\Webhook;
use App\Support\Tenancy\Tenancy;
use App\Support\Webhooks\SafeUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WebhookController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('update', $this->tenancy->currentOrFail());

        $validated = $this->validated($request);

        $webhook = Webhook::create($validated);

        // Shown once, on the response that creates it, like an API token — except
        // the receiver needs it to verify signatures, so it is also readable later.
        return back()->with('success', "Webhook created. Signing secret: {$webhook->secret}");
    }

    public function update(Request $request, Webhook $webhook): RedirectResponse
    {
        $this->authorize('update', $this->tenancy->currentOrFail());

        $webhook->update($this->validated($request, $webhook));

        return back()->with('success', 'Webhook saved.');
    }

    public function destroy(Webhook $webhook): RedirectResponse
    {
        $this->authorize('update', $this->tenancy->currentOrFail());

        $webhook->delete();

        return back()->with('success', 'Webhook deleted.');
    }

    /** Send a real delivery, so somebody can see whether it arrives. */
    public function test(Webhook $webhook): RedirectResponse
    {
        $this->authorize('update', $this->tenancy->currentOrFail());

        DeliverWebhook::dispatch(
            $webhook->id,
            $webhook->workspace_id,
            'ping',
            ['message' => 'This is a test delivery from Buggie.'],
        );

        return back()->with('success', 'Test queued. The result will appear below.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Webhook $existing = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'url' => ['required', 'string', 'max:2048'],
            'project_id' => [
                'nullable',
                Rule::exists('projects', 'id')->where('workspace_id', $this->tenancy->id()),
            ],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_column(WebhookEvent::cases(), 'value'))],
            'is_active' => ['boolean'],
        ]);

        // Checked here as well as at delivery. Refusing at the point somebody types
        // it is the only chance to tell them why.
        [$safe, $why] = SafeUrl::check($validated['url']);

        abort_if(! $safe, 422, $why ?? 'That address cannot be called.');

        return $validated;
    }
}
