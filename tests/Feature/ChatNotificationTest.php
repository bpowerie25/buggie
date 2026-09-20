<?php

namespace Tests\Feature;

use App\Actions\AddComment;
use App\Actions\CreateIssue;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverChatMessage;
use App\Models\ChatIntegration;
use App\Models\Issue;
use App\Models\Project;
use App\Support\Chat\ChatNotice;
use App\Support\Chat\ChatSender;
use App\Support\Tenancy\Tenancy;
use App\Support\Webhooks\SafeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slack and Teams channels.
 *
 * Every "it is not sent" here is paired with a "it *is* sent" in the same test. An
 * assertion that nothing was dispatched passes for free if the feature is wired up
 * to nothing at all, which is exactly the bug it is meant to catch.
 */
class ChatNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const SLACK_URL = 'https://hooks.slack.com/services/T0000/B0000/zzzzsecretzzzz';

    protected function setUp(): void
    {
        parent::setUp();

        // The safety check does a real DNS lookup and the test environment has no
        // outbound network. Faked, so these tests are about chat rather than about
        // whether the internet is reachable from a container.
        SafeUrl::resolveUsing(fn (string $host) => match ($host) {
            'hooks.slack.com' => ['3.5.30.1'],
            'acme.webhook.office.com' => ['52.96.0.1'],
            'prod-05.westeurope.logic.azure.com' => ['20.50.2.3'],
            'internal.example.com' => ['10.0.0.1'],
            default => [],
        });
    }

    protected function tearDown(): void
    {
        SafeUrl::resolveNormally();

        parent::tearDown();
    }

    /** @param array<string, mixed> $state */
    private function integration(array $state = []): ChatIntegration
    {
        return ChatIntegration::create([
            'name' => 'Bugs',
            'provider' => 'slack',
            'url' => self::SLACK_URL,
            'events' => [WebhookEvent::IssueCreated->value],
            ...$state,
        ]);
    }

    /** @return array<string, mixed> */
    private function doc(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]],
        ];
    }

    // ------------------------------------------------------------- configuring

    #[Test]
    public function a_channel_can_be_added(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/chat'), [
                'name' => '#bugs',
                'provider' => 'slack',
                'url' => self::SLACK_URL,
                'events' => [WebhookEvent::IssueCreated->value],
            ])
            ->assertRedirect();

        $integration = ChatIntegration::withoutGlobalScopes()->sole();

        $this->assertSame('#bugs', $integration->name);
        $this->assertSame($workspace->id, $integration->workspace_id);
        $this->assertSame(self::SLACK_URL, $integration->url);
        // Off unless asked for: a channel may well have a client in it.
        $this->assertFalse($integration->internal_activity);
    }

    #[Test]
    public function an_address_inside_the_network_is_refused_where_somebody_types_it(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        foreach ([
            'http://169.254.169.254/latest/meta-data/',
            'http://127.0.0.1:6379',
            'http://10.0.0.5/hook',
            'https://internal.example.com/hook',
            'file:///etc/passwd',
        ] as $url) {
            $this->actingAs($owner)
                ->post($this->workspaceUrl($workspace, '/settings/chat'), [
                    'name' => 'Sneaky',
                    'provider' => 'slack',
                    'url' => $url,
                    'events' => [WebhookEvent::IssueCreated->value],
                ])
                ->assertStatus(422);
        }

        $this->assertSame(0, ChatIntegration::withoutGlobalScopes()->count());

        // The control. Without it every assertion above passes for a form that
        // refuses everything, including the address people actually have.
        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/chat'), [
                'name' => 'Real',
                'provider' => 'slack',
                'url' => self::SLACK_URL,
                'events' => [WebhookEvent::IssueCreated->value],
            ])
            ->assertRedirect();

        $this->assertSame(1, ChatIntegration::withoutGlobalScopes()->count());
    }

    #[Test]
    public function editing_without_re_pasting_the_address_keeps_it(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $integration = app(Tenancy::class)->run($workspace, fn () => $this->integration());

        $this->actingAs($owner)
            ->patch($this->workspaceUrl($workspace, "/settings/chat/{$integration->id}"), [
                'name' => 'Renamed',
                'provider' => 'slack',
                'url' => '',
                'events' => [WebhookEvent::IssueClosed->value],
            ])
            ->assertRedirect();

        $fresh = ChatIntegration::withoutGlobalScopes()->find($integration->id);

        $this->assertSame('Renamed', $fresh->name);
        // The form is never sent the address, so a blank field is the normal case
        // when changing anything else. It must not wipe the credential.
        $this->assertSame(self::SLACK_URL, $fresh->url);
    }

    // ------------------------------------------------------- the address itself

    #[Test]
    public function the_stored_address_never_reaches_the_browser(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, fn () => $this->integration());

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/settings/workspace'))
            ->assertOk()
            ->assertDontSee('zzzzsecretzzzz')
            ->assertInertia(fn ($page) => $page
                ->has('chatIntegrations.0', fn ($row) => $row
                    // Whether one is set is all the page needs to know.
                    ->where('has_url', true)
                    ->missing('url')
                    ->etc())
                ->etc());
    }

    #[Test]
    public function the_address_is_encrypted_at_rest(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $integration = app(Tenancy::class)->run($workspace, fn () => $this->integration());

        $stored = DB::table('chat_integrations')->where('id', $integration->id)->value('url');

        // A stolen database dump must not be a stolen channel.
        $this->assertStringNotContainsString('zzzzsecretzzzz', $stored);
        $this->assertStringNotContainsString('hooks.slack.com', $stored);

        // The control: it is still readable by the application.
        $this->assertSame(
            self::SLACK_URL,
            app(Tenancy::class)->run($workspace, fn () => ChatIntegration::find($integration->id)->url),
        );
    }

    // -------------------------------------------------------------- what fires

    #[Test]
    public function an_issue_reaches_the_channel_that_asked_and_not_the_one_that_did_not(): void
    {
        Queue::fake();

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        [$subscribed, $unsubscribed] = app(Tenancy::class)->run($workspace, function () {
            $subscribed = $this->integration(['name' => 'Wants issues']);
            $unsubscribed = $this->integration([
                'name' => 'Wants reports',
                'events' => [WebhookEvent::ReportReceived->value],
            ]);

            app(CreateIssue::class)->handle(
                Project::factory()->create(['key' => 'WEB']),
                ['title' => 'Pay now does nothing'],
            );

            return [$subscribed, $unsubscribed];
        });

        Queue::assertPushed(
            DeliverChatMessage::class,
            fn (DeliverChatMessage $job) => $job->integrationId === $subscribed->id
                && $job->event === WebhookEvent::IssueCreated->value,
        );

        Queue::assertNotPushed(
            DeliverChatMessage::class,
            fn (DeliverChatMessage $job) => $job->integrationId === $unsubscribed->id,
        );
    }

    #[Test]
    public function a_channel_watching_one_project_hears_nothing_about_another(): void
    {
        Queue::fake();

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $watched = app(Tenancy::class)->run($workspace, function () {
            $web = Project::factory()->create(['key' => 'WEB']);
            $app = Project::factory()->create(['key' => 'APP']);

            $watched = $this->integration(['project_id' => $web->id]);

            app(CreateIssue::class)->handle($app, ['title' => 'Elsewhere']);

            Queue::assertNotPushed(DeliverChatMessage::class);

            // The control, in the same breath: the same channel does hear about the
            // project it holds, so the silence above is about the filter working.
            app(CreateIssue::class)->handle($web, ['title' => 'Here']);

            return $watched;
        });

        Queue::assertPushed(
            DeliverChatMessage::class,
            fn (DeliverChatMessage $job) => $job->integrationId === $watched->id,
        );
    }

    #[Test]
    public function a_switched_off_channel_is_silent_and_a_live_one_is_not(): void
    {
        Queue::fake();

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        [$off, $on] = app(Tenancy::class)->run($workspace, function () {
            $off = $this->integration(['name' => 'Off', 'is_active' => false]);
            $on = $this->integration(['name' => 'On']);

            app(CreateIssue::class)->handle(
                Project::factory()->create(['key' => 'WEB']),
                ['title' => 'Something happened'],
            );

            return [$off, $on];
        });

        Queue::assertNotPushed(
            DeliverChatMessage::class,
            fn (DeliverChatMessage $job) => $job->integrationId === $off->id,
        );

        Queue::assertPushed(
            DeliverChatMessage::class,
            fn (DeliverChatMessage $job) => $job->integrationId === $on->id,
        );
    }

    #[Test]
    public function an_internal_note_reaches_only_a_channel_that_says_it_is_the_teams_own(): void
    {
        Queue::fake();

        [$workspace, $staff] = $this->workspaceWithMember(slug: 'acme');

        [$team, $shared] = app(Tenancy::class)->run($workspace, function () use ($staff) {
            $team = $this->integration([
                'name' => 'Team',
                'events' => [WebhookEvent::CommentCreated->value],
                'internal_activity' => true,
            ]);

            $shared = $this->integration([
                'name' => 'Shared with the client',
                'events' => [WebhookEvent::CommentCreated->value],
            ]);

            $issue = Issue::factory()->create([
                'project_id' => Project::factory()->create(['key' => 'WEB'])->id,
            ]);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('Internal: the client has not paid us.'),
                'is_internal' => true,
            ], $staff);

            Queue::assertNotPushed(
                DeliverChatMessage::class,
                fn (DeliverChatMessage $job) => $job->integrationId === $shared->id,
            );

            // The control. A public comment reaches both, so the silence above is
            // about the note being internal rather than about comments never being
            // announced at all.
            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('Fixed and deployed.'),
                'is_internal' => false,
            ], $staff);

            return [$team, $shared];
        });

        Queue::assertPushed(
            DeliverChatMessage::class,
            fn (DeliverChatMessage $job) => $job->integrationId === $shared->id,
        );

        // The team's own channel hears both.
        Queue::assertPushed(
            DeliverChatMessage::class,
            fn (DeliverChatMessage $job) => $job->integrationId === $team->id,
        );
    }

    #[Test]
    public function comment_text_is_never_put_in_a_message(): void
    {
        Queue::fake();

        [$workspace, $staff] = $this->workspaceWithMember(slug: 'acme');

        app(Tenancy::class)->run($workspace, function () use ($staff) {
            $this->integration([
                'events' => [WebhookEvent::CommentCreated->value],
                'internal_activity' => true,
            ]);

            $issue = Issue::factory()->create([
                'project_id' => Project::factory()->create(['key' => 'WEB'])->id,
            ]);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('The staging password is hunter2.'),
                'is_internal' => true,
            ], $staff);
        });

        Queue::assertPushed(DeliverChatMessage::class, function (DeliverChatMessage $job) {
            $rendered = json_encode($job->notice);

            // Buggie cannot see who is in a channel it was handed a URL for, so the
            // text of a note never leaves — only that somebody left one.
            $this->assertStringNotContainsString('hunter2', $rendered);

            // The control: the message is not simply empty.
            $this->assertStringContainsString('WEB-', $rendered);

            return true;
        });
    }

    // ---------------------------------------------------------------- delivery

    #[Test]
    public function a_message_is_posted_and_the_attempt_recorded(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $integration = app(Tenancy::class)->run($workspace, fn () => $this->integration());

        (new DeliverChatMessage($integration->id, $workspace->id, 'ping', new ChatNotice('Test', 'Hello')))
            ->handle(app(Tenancy::class), app(ChatSender::class));

        Http::assertSent(fn ($request) => $request->url() === self::SLACK_URL
            && isset($request->data()['blocks']));

        $this->assertDatabaseHas('chat_deliveries', [
            'chat_integration_id' => $integration->id,
            'status' => 200,
        ]);
    }

    #[Test]
    public function a_refusal_is_recorded_with_the_providers_own_words(): void
    {
        Http::fake(['*' => Http::response('invalid_token', 403)]);

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $integration = app(Tenancy::class)->run($workspace, fn () => $this->integration());

        try {
            (new DeliverChatMessage($integration->id, $workspace->id, 'ping', new ChatNotice('Test', 'Hello')))
                ->handle(app(Tenancy::class), app(ChatSender::class));
        } catch (\Throwable) {
            // Expected: a failed delivery is retried, so it throws.
        }

        $delivery = DB::table('chat_deliveries')->where('chat_integration_id', $integration->id)->first();

        $this->assertSame(403, (int) $delivery->status);
        $this->assertStringContainsString('invalid_token', $delivery->error);
    }

    #[Test]
    public function an_address_that_turned_private_after_saving_is_not_called(): void
    {
        Http::fake();

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $integration = app(Tenancy::class)->run($workspace, fn () => $this->integration());

        // Forced past the save-time check, as a repointed DNS record would be.
        $integration->forceFill(['url' => 'http://169.254.169.254/'])->saveQuietly();

        try {
            (new DeliverChatMessage($integration->id, $workspace->id, 'ping', new ChatNotice('Test', 'Hello')))
                ->handle(app(Tenancy::class), app(ChatSender::class));
        } catch (\Throwable) {
            // Expected.
        }

        Http::assertNothingSent();

        $this->assertDatabaseHas('chat_deliveries', [
            'chat_integration_id' => $integration->id,
            'status' => null,
        ]);
    }

    // ------------------------------------------------------------- the button

    #[Test]
    public function the_test_button_reports_the_providers_error(): void
    {
        Http::fake(['*' => Http::response('invalid_token', 403)]);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $integration = app(Tenancy::class)->run($workspace, fn () => $this->integration());

        $response = $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/settings/chat/{$integration->id}/test"));

        $response->assertRedirect()->assertSessionHasErrors('chat');

        // Their words, not ours: which of a mistyped address, a deleted channel and
        // a revoked integration it is are three different problems.
        $this->assertStringContainsString(
            'invalid_token',
            session('errors')->first('chat'),
        );

        $this->assertDatabaseHas('chat_deliveries', [
            'chat_integration_id' => $integration->id,
            'event' => 'ping',
            'status' => 403,
        ]);
    }

    #[Test]
    public function the_test_button_says_so_when_it_worked(): void
    {
        // The control for the test above: without it, a button that always reports
        // an error would pass.
        Http::fake(['*' => Http::response('ok', 200)]);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $integration = app(Tenancy::class)->run($workspace, fn () => $this->integration());

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/settings/chat/{$integration->id}/test"))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_test_send_never_puts_the_address_in_the_response(): void
    {
        Http::fake(['*' => Http::response('no_service', 404)]);

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $integration = app(Tenancy::class)->run($workspace, fn () => $this->integration());

        // The failure path is the one that would leak it: an error message built
        // from an exception is where a URL usually turns up.
        $response = $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, "/settings/chat/{$integration->id}/test"));

        $response->assertRedirect()->assertSessionHasErrors('chat');

        $message = session('errors')->first('chat');

        $this->assertStringNotContainsString('zzzzsecretzzzz', $message);
        $this->assertStringNotContainsString('hooks.slack.com', $message);

        // The control: it is still the provider's own words rather than nothing.
        $this->assertStringContainsString('no_service', $message);
    }

    // ---------------------------------------------------------------- tenancy

    #[Test]
    public function a_member_of_another_workspace_cannot_touch_a_channel(): void
    {
        [$acme] = $this->workspaceWithMember(slug: 'acme');
        [$globex, $stranger] = $this->workspaceWithMember(slug: 'globex');

        $integration = app(Tenancy::class)->run($acme, fn () => $this->integration());

        $this->actingAs($stranger)
            ->delete($this->workspaceUrl($globex, "/settings/chat/{$integration->id}"))
            ->assertNotFound();

        $this->assertSame(1, ChatIntegration::withoutGlobalScopes()->count());
    }
}
