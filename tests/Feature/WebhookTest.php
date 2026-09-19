<?php

namespace Tests\Feature;

use App\Actions\AddComment;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Webhook;
use App\Support\Tenancy\Tenancy;
use App\Support\Webhooks\SafeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The safety check does a real DNS lookup and the test environment has no
        // outbound network. Faked, so these tests are about webhooks rather than
        // about whether the internet is reachable from a container.
        SafeUrl::resolveUsing(fn (string $host) => match ($host) {
            'example.com' => ['93.184.215.14'],
            'hooks.slack.com' => ['3.5.30.1'],
            'internal.example.com' => ['10.0.0.1'],
            default => [],
        });
    }

    protected function tearDown(): void
    {
        SafeUrl::resolveNormally();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ safety

    #[Test]
    public function a_webhook_cannot_point_inside_the_network(): void
    {
        // A webhook address is supplied by a customer and fetched by our server from
        // inside our own network. Without this, anybody with a workspace could read
        // the cloud metadata service or map what we can reach.
        foreach ([
            'http://169.254.169.254/latest/meta-data/',
            'http://127.0.0.1:6379',
            'http://10.0.0.5/hook',
            'http://192.168.1.1/',
            'http://172.16.4.4/',
        ] as $url) {
            [$safe] = SafeUrl::check($url);

            $this->assertFalse($safe, "{$url} should be refused.");
        }
    }

    #[Test]
    public function an_ordinary_public_address_is_allowed(): void
    {
        // The control. Without it, the test above passes for a checker that refuses
        // everything.
        [$safe] = SafeUrl::check('https://hooks.slack.com/services/T000/B000/xxx');

        $this->assertTrue($safe);
    }

    #[Test]
    public function only_http_addresses_are_called(): void
    {
        foreach (['file:///etc/passwd', 'gopher://x/', 'ftp://example.com/'] as $url) {
            [$safe] = SafeUrl::check($url);

            $this->assertFalse($safe, "{$url} should be refused.");
        }
    }

    #[Test]
    public function saving_a_private_address_is_refused_with_a_reason(): void
    {
        // Refused where somebody types it, because that is the only chance to say why.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/webhooks'), [
                'name' => 'Sneaky',
                'url' => 'http://169.254.169.254/',
                'events' => [WebhookEvent::IssueCreated->value],
            ])
            ->assertStatus(422);

        $this->assertSame(0, Webhook::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------ firing

    private function webhook(array $state = []): Webhook
    {
        return Webhook::create([
            'name' => 'Slack',
            // example.com, because it resolves: the safety check does a real DNS
            // lookup, and a host that does not resolve is refused — correctly, but
            // it would make these tests about DNS rather than about webhooks.
            'url' => 'https://example.com/hook',
            'events' => [WebhookEvent::IssueCreated->value],
            ...$state,
        ]);
    }

    #[Test]
    public function creating_an_issue_queues_a_delivery(): void
    {
        Queue::fake();

        [$workspace, $staff] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () {
            $this->webhook();

            app(\App\Actions\CreateIssue::class)->handle(
                Project::factory()->create(['key' => 'WEB']),
                ['title' => 'Pay now does nothing'],
            );
        });

        Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->event === 'issue.created');
    }

    #[Test]
    public function a_webhook_only_hears_about_what_it_asked_for(): void
    {
        Queue::fake();

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () {
            $this->webhook(['events' => [WebhookEvent::ReportReceived->value]]);

            app(\App\Actions\CreateIssue::class)->handle(
                Project::factory()->create(['key' => 'WEB']),
                ['title' => 'Nobody asked about this'],
            );
        });

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    #[Test]
    public function a_project_webhook_ignores_other_projects(): void
    {
        Queue::fake();

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () {
            $watched = Project::factory()->create(['key' => 'WEB']);
            $other = Project::factory()->create(['key' => 'APP']);

            $this->webhook(['project_id' => $watched->id]);

            app(\App\Actions\CreateIssue::class)->handle($other, ['title' => 'Elsewhere']);
        });

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    #[Test]
    public function an_internal_note_is_never_sent(): void
    {
        // A webhook is an outside audience, and the whole point of an internal note
        // is that it has none.
        Queue::fake();

        [$workspace, $staff] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($staff) {
            $this->webhook(['events' => [WebhookEvent::CommentCreated->value]]);

            $issue = Issue::factory()->create([
                'project_id' => Project::factory()->create(['key' => 'WEB'])->id,
            ]);

            app(AddComment::class)->handle($issue, [
                'body' => ['type' => 'doc', 'content' => []],
                'is_internal' => true,
            ], $staff);
        });

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    #[Test]
    public function a_switched_off_webhook_is_silent(): void
    {
        Queue::fake();

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () {
            $this->webhook(['is_active' => false]);

            app(\App\Actions\CreateIssue::class)->handle(
                Project::factory()->create(['key' => 'WEB']),
                ['title' => 'Nobody hears this'],
            );
        });

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    // ---------------------------------------------------------------- delivery

    #[Test]
    public function a_delivery_is_signed_so_the_receiver_can_tell_it_is_ours(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $webhook = app(Tenancy::class)->run($workspace, fn () => $this->webhook());

        (new DeliverWebhook($webhook->id, $workspace->id, 'ping', ['message' => 'hello']))
            ->handle(app(Tenancy::class));

        Http::assertSent(function ($request) use ($webhook) {
            $signature = $request->header('X-Buggie-Signature')[0] ?? '';

            // Signed over the exact bytes sent, or the check means nothing.
            return $signature === 'sha256='.hash_hmac('sha256', $request->body(), $webhook->secret);
        });
    }

    #[Test]
    public function every_attempt_is_recorded_so_somebody_can_debug_it(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $webhook = app(Tenancy::class)->run($workspace, fn () => $this->webhook());

        try {
            (new DeliverWebhook($webhook->id, $workspace->id, 'ping', []))->handle(app(Tenancy::class));
        } catch (\Throwable) {
            // Expected: a failed delivery is retried, so it throws.
        }

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_id' => $webhook->id,
            'status' => 500,
        ]);
    }

    #[Test]
    public function a_url_that_turned_private_after_saving_is_not_called(): void
    {
        // Re-checked at delivery, not only when saved: DNS can be repointed at an
        // internal address after the fact.
        Http::fake();

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $webhook = app(Tenancy::class)->run($workspace, fn () => $this->webhook());

        // Forced past the save-time check, as a repointed DNS record would be.
        $webhook->forceFill(['url' => 'http://169.254.169.254/'])->saveQuietly();

        try {
            (new DeliverWebhook($webhook->id, $workspace->id, 'ping', []))->handle(app(Tenancy::class));
        } catch (\Throwable) {
            // Expected.
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function a_public_name_that_resolves_inside_the_network_is_refused(): void
    {
        // The reason the check resolves rather than reading the hostname: a
        // public-looking name can point anywhere, and http://internal.example.com
        // proves nothing on its own.
        [$safe] = SafeUrl::check('https://internal.example.com/hook');

        $this->assertFalse($safe);
    }

    #[Test]
    public function a_host_that_cannot_be_resolved_is_refused(): void
    {
        // Fails closed: it might be a typo, or a name that only answers from inside
        // a network we are not meant to reach, and there is no telling from here.
        [$safe] = SafeUrl::check('https://nowhere.invalid/hook');

        $this->assertFalse($safe);
    }
}
