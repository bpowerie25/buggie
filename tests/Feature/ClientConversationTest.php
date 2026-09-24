<?php

namespace Tests\Feature;

use App\Actions\AddComment;
use App\Actions\CreateIssue;
use App\Actions\CreateProject;
use App\Actions\UpdateIssue;
use App\Enums\IssueEventType;
use App\Enums\NotificationReason;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\PortalToken;
use App\Models\Project;
use App\Models\Report;
use App\Models\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whose turn it is on an issue: carried by the status, never by the assignee.
 *
 * Matrix is the agency; KD is Kennco's project, made from the client website template,
 * so it has "Building" and "Awaiting client". Jane (reporter) and Tom are clients with
 * their own issues only; Mia is Kennco's client manager; Zed is a client on another
 * project and must never hear a word.
 */
class ClientConversationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $dev;

    private User $watcherStaff;

    private Project $kd;

    private User $jane;

    private User $tom;

    private User $mia;

    private User $zed;

    private Issue $issue;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        $this->workspace->update(['name' => 'Matrix']);
        $this->owner->update(['name' => 'Owen Owner']);

        $this->dev = $this->join(WorkspaceRole::Member, 'Dana Dev');
        $this->watcherStaff = $this->join(WorkspaceRole::Member, 'Wes Watcher');

        $this->kd = $this->tenant(fn () => app(CreateProject::class)->handle([
            'name' => 'Kennco', 'key' => 'KD', 'template' => 'client_website',
        ]));
        $other = $this->tenant(fn () => Project::factory()->create(['key' => 'GLX']));

        $this->jane = $this->client('Brian Power', $this->kd, ProjectRole::Client);
        $this->tom = $this->client('Tom', $this->kd, ProjectRole::Client);
        $this->mia = $this->client('Mia', $this->kd, ProjectRole::ClientManager);
        $this->zed = $this->client('Zed', $other, ProjectRole::ClientManager);

        // Jane files it; the team picks it up, holds it and starts building.
        $this->issue = $this->tenant(fn () => app(CreateIssue::class)->handle($this->kd, ['title' => 'Contact form does not send'], $this->jane));
        $this->tenant(fn () => app(UpdateIssue::class)->handle($this->issue, [
            'status_id' => $this->statusNamed('Building')->id,
            'assignee_id' => $this->dev->id,
        ], $this->owner));

        DB::table('pending_notifications')->delete();
    }

    // --- 1. reply and wait ------------------------------------------------------

    #[Test]
    public function reply_and_await_moves_to_awaiting_client_and_tells_only_the_audience(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url('/await-client'), ['body' => $this->doc('Which browser were you using?')])
            ->assertSessionHas('success');

        $issue = $this->reload();
        $this->assertSame('Awaiting client', $issue->status->name);
        $this->assertSame($this->statusNamed('Building')->id, $issue->status_before_waiting_id);
        $this->assertNotNull($issue->awaiting_client_since);
        $this->assertSame($this->dev->id, $issue->assignee_id, 'The assignee must never change on its own.');

        $comment = $issue->comments()->latest('id')->first();
        $this->assertFalse($comment->is_internal);

        // Jane reported it and Mia manages the project: both are its audience.
        $this->assertNotified($this->jane, NotificationReason::AwaitingReply);
        $this->assertNotified($this->mia, NotificationReason::AwaitingReply);
        // Tom holds the project but not this issue; Zed holds neither.
        $this->assertNotNotified($this->tom);
        $this->assertNotNotified($this->zed);

        // To a client the reply comes from "Matrix", not from Owen.
        $row = DB::table('pending_notifications')->where('user_id', $this->jane->id)->first();
        $this->assertSame('Matrix', json_decode($row->data, true)['from']);

        $this->assertSame(1, $issue->events()->where('type', IssueEventType::AwaitingClient->value)->where('is_internal', false)->count());
    }

    #[Test]
    public function the_button_and_the_endpoint_are_only_for_a_client_visible_issue_with_somewhere_to_wait(): void
    {
        $this->actingAs($this->owner)->get($this->url())
            ->assertInertia(fn ($page) => $page->where('composer.can_await', true)->where('composer.awaiting_status', 'Awaiting client'));

        // Internal: nobody to wait on.
        $this->tenant(fn () => app(UpdateIssue::class)->handle($this->reload(), ['visibility' => 'internal'], $this->owner));
        $this->actingAs($this->owner)->get($this->url())->assertInertia(fn ($page) => $page->where('composer.can_await', false));
        $this->actingAs($this->owner)->post($this->url('/await-client'), ['body' => $this->doc('Hello?')])->assertStatus(422);

        // A project with no awaiting-client status.
        $this->tenant(fn () => app(UpdateIssue::class)->handle($this->reload(), ['visibility' => 'client'], $this->owner));
        $this->kd->statuses()->update(['is_awaiting_client' => false]);
        $this->actingAs($this->owner)->get($this->url())->assertInertia(fn ($page) => $page->where('composer.can_await', false));

        // A client never gets the composer's staff details at all.
        $this->actingAs($this->jane)->get($this->url())->assertInertia(fn ($page) => $page->where('composer', null));
    }

    // --- 2. the client replies --------------------------------------------------

    #[Test]
    public function a_client_reply_restores_the_status_and_tells_the_assignee(): void
    {
        $this->awaitClient();

        $this->actingAs($this->jane)
            ->post($this->url('/comments'), ['body' => $this->doc('Chrome, on a Mac.')])
            ->assertRedirect();

        $issue = $this->reload();
        $this->assertSame('Building', $issue->status->name);
        $this->assertNull($issue->status_before_waiting_id);
        $this->assertNull($issue->awaiting_client_since);
        $this->assertNotNull($issue->client_replied_at);
        $this->assertSame($this->dev->id, $issue->assignee_id);

        $event = $issue->events()->where('type', IssueEventType::ClientReplied->value)->sole();
        $this->assertSame('Building', $event->data['to']['name']);
        $this->assertFalse($event->is_internal);

        $this->assertNotified($this->dev, NotificationReason::ClientReplied);
    }

    #[Test]
    public function the_badge_comes_down_when_the_team_looks_or_answers(): void
    {
        $this->awaitClient();
        $this->clientSays('Chrome.');

        $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, '/issues?q=is:all'))
            ->assertInertia(fn ($page) => $page->where('issues.0.client_replied', true));

        // A client never sees the badge.
        $this->actingAs($this->jane)->get($this->url())->assertInertia(fn ($page) => $page->where('issue.client_replied', false));

        $this->actingAs($this->dev)->get($this->url())->assertOk();
        $this->assertNull($this->reload()->client_replied_at);

        // An internal note does not count as answering the client...
        $this->clientSays('Also Firefox.');
        $this->tenant(fn () => app(AddComment::class)->handle($this->reload(), ['body' => $this->doc('Note to self'), 'is_internal' => true], $this->dev));
        $this->assertNotNull($this->reload()->client_replied_at);

        // ...a public reply does.
        $this->tenant(fn () => app(AddComment::class)->handle($this->reload(), ['body' => $this->doc('Thanks!'), 'is_internal' => false], $this->dev));
        $this->assertNull($this->reload()->client_replied_at);
    }

    #[Test]
    public function unassigned_it_goes_to_staff_watchers_and_with_none_it_waits_in_triage(): void
    {
        $this->tenant(fn () => app(UpdateIssue::class)->handle($this->reload(), ['assignee_id' => null], $this->owner));
        $this->reload()->watchers()->attach($this->watcherStaff->id, ['reason' => 'mentioned']);
        $this->awaitClient();

        $this->clientSays('Chrome.');
        $this->assertNotified($this->watcherStaff, NotificationReason::ClientReplied);

        // Nobody on the team watching either.
        DB::table('pending_notifications')->delete();
        DB::table('issue_watchers')->where('issue_id', $this->issue->id)->whereIn('user_id', [$this->watcherStaff->id, $this->owner->id, $this->dev->id])->delete();
        $this->awaitClient();
        DB::table('issue_watchers')->where('issue_id', $this->issue->id)->whereIn('user_id', [$this->owner->id])->delete();

        $this->clientSays('Still Chrome.');
        $this->assertSame(0, DB::table('pending_notifications')->where('reason', NotificationReason::ClientReplied->value)->count());

        $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, '/inbox'))
            ->assertInertia(fn ($page) => $page->where('clientIssues.0.key', $this->issue->key)->whereNot('clientIssues.0.replied_at', null));
    }

    #[Test]
    public function a_client_comment_that_is_not_an_answer_changes_nothing_but_tells_the_assignee(): void
    {
        $before = $this->reload();

        $this->clientSays('Any news?');

        $after = $this->reload();
        $this->assertSame($before->status_id, $after->status_id);
        $this->assertSame($before->assignee_id, $after->assignee_id);
        $this->assertSame(0, $after->events()->where('type', IssueEventType::ClientReplied->value)->count());
        $this->assertNotified($this->dev, NotificationReason::ClientReplied);
    }

    #[Test]
    public function an_internal_note_never_tells_a_client_and_never_moves_the_issue(): void
    {
        $this->awaitClient();
        DB::table('pending_notifications')->delete();

        $this->actingAs($this->dev)->post($this->url('/comments'), ['body' => $this->doc('They are on IE, surely'), 'is_internal' => true]);

        $this->assertSame('Awaiting client', $this->reload()->status->name);
        foreach ([$this->jane, $this->tom, $this->mia, $this->zed] as $client) {
            $this->assertNotNotified($client);
        }
    }

    #[Test]
    public function a_portal_reply_and_an_email_reply_count_as_the_client_answering(): void
    {
        $this->awaitClient();

        $token = $this->tenant(fn () => PortalToken::issueFor($this->reload(), 'reporter@kennco.test'));
        $this->post(central_url('portal/'.$token->token.'/comment'), ['body' => 'Chrome.'])->assertRedirect();
        $this->assertSame('Building', $this->reload()->status->name);

        $this->awaitClient();
        config(['buggie.mailgun_signing_key' => 'k']);
        $ts = (string) time();
        $this->postJson('/api/mail/inbound', [
            'recipient' => "reply+{$this->issue->key}.{$this->kd->fresh()->inbound_token}@in.buggie.test",
            'from' => "Brian Power <{$this->jane->email}>",
            'stripped-text' => 'Firefox too.',
            'timestamp' => $ts, 'token' => 't1', 'signature' => hash_hmac('sha256', $ts.'t1', 'k'),
        ])->assertOk();
        $this->assertSame('Building', $this->reload()->status->name);
    }

    #[Test]
    public function a_client_cannot_change_the_status_through_any_door(): void
    {
        $done = $this->kd->statuses()->where('category', 'done')->firstOrFail();
        $before = $this->reload()->status_id;

        $this->actingAs($this->jane)->patch($this->url(), ['status_id' => $done->id])->assertForbidden();
        $this->actingAs($this->jane)->patch($this->workspaceUrl($this->workspace, '/issues/bulk'), ['keys' => [$this->issue->key], 'changes' => ['status_id' => $done->id]])->assertForbidden();
        $this->actingAs($this->jane)->patch($this->url('/rank'), ['status_id' => $done->id])->assertForbidden();
        $this->actingAs($this->jane)->post($this->url('/await-client'), ['body' => $this->doc('Waiting on myself')])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $token = $this->jane->createTokenForWorkspace($this->workspace, 'jane', ['read', 'write'])->plainTextToken;
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->patchJson($this->workspaceUrl($this->workspace, "/api/v1/issues/{$this->issue->key}"), ['status_id' => $done->id])
            ->assertForbidden();

        $this->assertSame($before, $this->reload()->status_id);
    }

    // --- 3. reminder and auto-close --------------------------------------------

    #[Test]
    public function nothing_is_chased_unless_the_project_asks(): void
    {
        $this->awaitClient();
        $this->travel(60)->days();

        $this->artisan('issues:chase-clients')->assertSuccessful();

        $issue = $this->reload();
        $this->assertSame('Awaiting client', $issue->status->name);
        $this->assertNull($issue->client_reminded_at);
        $this->assertSame(0, DB::table('pending_notifications')->where('reason', NotificationReason::ClientReminder->value)->count());
    }

    #[Test]
    public function the_reminder_goes_once_to_the_audience(): void
    {
        $this->kd->forceFill(['settings' => ['awaiting_reminder_days' => 3]])->save();
        $this->awaitClient();

        $this->travel(2)->days();
        $this->artisan('issues:chase-clients');
        $this->assertNull($this->reload()->client_reminded_at, 'Reminded too early.');

        $this->travel(2)->days();
        $this->artisan('issues:chase-clients');
        $this->artisan('issues:chase-clients');

        $this->assertNotNull($this->reload()->client_reminded_at);
        $this->assertSame(1, DB::table('pending_notifications')->where('user_id', $this->jane->id)->where('reason', NotificationReason::ClientReminder->value)->count());
        $this->assertSame(0, DB::table('pending_notifications')->where('user_id', $this->tom->id)->count());
        $this->assertSame(0, DB::table('pending_notifications')->where('user_id', $this->zed->id)->count());
    }

    #[Test]
    public function it_auto_closes_once_and_a_late_reply_reopens_it(): void
    {
        $this->kd->forceFill(['settings' => ['awaiting_close_days' => 14]])->save();
        $this->awaitClient();

        $this->travel(15)->days();
        $this->artisan('issues:chase-clients');
        $this->artisan('issues:chase-clients');

        $issue = $this->reload();
        $this->assertSame('Out of scope', $issue->status->name, 'Closed, not resolved: the first canceled status.');
        $this->assertNotNull($issue->auto_closed_at);
        $this->assertSame(1, $issue->events()->where('type', IssueEventType::AutoClosed->value)->count());
        $this->assertSame(1, $issue->comments()->where('source', 'system')->where('is_internal', false)->count());

        $this->clientSays('Sorry, was away. Chrome.');

        $issue = $this->reload();
        $this->assertSame('Building', $issue->status->name);
        $this->assertTrue($issue->status->category->isOpen());
        $this->assertNull($issue->auto_closed_at);
    }

    // --- 4. assignee must be staff ---------------------------------------------

    #[Test]
    public function a_client_cannot_be_assigned_through_any_door(): void
    {
        $refused = fn ($response) => $response->assertSessionHasErrors('assignee_id');

        $refused($this->actingAs($this->owner)->patch($this->url(), ['assignee_id' => $this->jane->id]));
        $refused($this->actingAs($this->owner)->post($this->workspaceUrl($this->workspace, '/issues'), [
            'project_id' => $this->kd->id, 'title' => 'x', 'type' => 'bug', 'priority' => 0, 'visibility' => 'client', 'assignee_id' => $this->jane->id,
        ]));
        $refused($this->actingAs($this->owner)->patch($this->workspaceUrl($this->workspace, '/issues/bulk'), [
            'keys' => [$this->issue->key], 'changes' => ['assignee_id' => $this->jane->id],
        ]));
        $this->actingAs($this->owner)->put($this->workspaceUrl($this->workspace, "/projects/{$this->kd->slug}"), [
            'name' => 'Kennco', 'default_assignee_id' => $this->jane->id,
        ])->assertSessionHasErrors('default_assignee_id');

        $report = $this->tenant(fn () => Report::factory()->create(['project_id' => $this->kd->id]));
        $refused($this->actingAs($this->owner)->post($this->workspaceUrl($this->workspace, "/inbox/{$report->id}/accept"), ['assignee_id' => $this->jane->id]));

        $this->app['auth']->forgetGuards();
        $token = $this->owner->createTokenForWorkspace($this->workspace, 'ops', ['read', 'write'])->plainTextToken;
        $api = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
        $api->patchJson($this->workspaceUrl($this->workspace, "/api/v1/issues/{$this->issue->key}"), ['assignee_id' => $this->jane->id])->assertStatus(422);
        $api->postJson($this->workspaceUrl($this->workspace, '/api/v1/issues'), [
            'project_id' => $this->kd->id, 'title' => 'x', 'type' => 'bug', 'priority' => 0, 'visibility' => 'client', 'assignee_id' => $this->jane->id,
        ])->assertStatus(422);

        // And the action refuses whoever calls it, bypassing every form.
        $this->expectException(ValidationException::class);
        $this->tenant(fn () => app(UpdateIssue::class)->handle($this->reload(), ['assignee_id' => $this->jane->id], $this->owner));
    }

    #[Test]
    public function a_client_default_assignee_is_never_used_and_is_reported(): void
    {
        // How a client could have been handed every new issue silently.
        DB::table('projects')->where('id', $this->kd->id)->update(['default_assignee_id' => $this->jane->id]);
        DB::table('issues')->where('id', $this->issue->id)->update(['assignee_id' => $this->jane->id]);

        $fresh = $this->tenant(fn () => app(CreateIssue::class)->handle($this->kd->fresh(), ['title' => 'New one'], $this->owner));
        $this->assertNull($fresh->assignee_id);

        $this->artisan('buggie:client-assignees')
            ->expectsOutputToContain($this->issue->key)
            ->expectsOutputToContain('KD')
            ->assertSuccessful();

        $this->assertSame($this->jane->id, DB::table('issues')->where('id', $this->issue->id)->value('assignee_id'), 'Reported, never changed.');
    }

    #[Test]
    public function the_assignee_picker_offers_staff_only(): void
    {
        $this->actingAs($this->owner)->get($this->url())
            ->assertInertia(fn ($page) => $page->where('facets.assignees', fn ($people) => collect($people)->pluck('name')->sort()->values()->all()
                === ['Dana Dev', 'Owen Owner', 'Wes Watcher']));
    }

    // --- 6. what a client's payload holds --------------------------------------

    #[Test]
    public function a_clients_payload_holds_no_internal_work(): void
    {
        $this->tenant(function () {
            $issue = $this->reload();
            app(AddComment::class)->handle($issue, ['body' => $this->doc('SECRET internal note'), 'is_internal' => true], $this->dev);
            app(UpdateIssue::class)->handle($issue, ['priority' => 4, 'estimate_minutes' => 120], $this->owner);
            app(UpdateIssue::class)->handle($issue, ['client_audience' => 'specific', 'client_share_ids' => [$this->tom->id]], $this->owner);
        });
        $this->awaitClient();

        $props = $this->actingAs($this->jane)->get($this->url())->viewData('page')['props'];
        // Without the route table, which names every route in the app ("time.estimate"
        // among them) and is the same for everybody.
        $raw = json_encode(collect($props)->except('ziggy')->all());

        $this->assertStringNotContainsString('SECRET internal note', $raw);
        $this->assertTrue(collect($props['comments'])->every(fn ($c) => $c['is_internal'] === false));

        $types = collect($props['events'])->pluck('type');
        foreach (['assigned', 'priority_changed', 'audience_changed', 'visibility_changed'] as $internal) {
            $this->assertNotContains($internal, $types, "[{$internal}] reached a client.");
        }
        $this->assertContains('awaiting_client', $types);
        $this->assertContains('status_changed', $types);

        $this->assertNull($props['time']);
        $this->assertStringNotContainsString('estimate', $raw);
        $this->assertSame([], $props['issue']['watchers']);
        $this->assertStringNotContainsString('Tom', $raw, 'Another client was named to Jane.');

        // The team speaks as the workspace, by default.
        $this->assertStringNotContainsString('Owen Owner', $raw);
        $this->assertStringNotContainsString('Dana Dev', $raw);
        $staffComment = collect($props['comments'])->firstWhere('author.role', 'staff');
        $this->assertSame('Matrix', $staffComment['author']['name']);
        $this->assertNull($staffComment['author']['id']);
    }

    #[Test]
    public function a_workspace_can_show_its_staff_by_name(): void
    {
        $this->awaitClient();
        $this->actingAs($this->owner)->patch($this->workspaceUrl($this->workspace, '/settings/workspace'), ['name' => 'Matrix', 'show_staff_names' => true]);

        $props = $this->actingAs($this->jane)->get($this->url())->viewData('page')['props'];

        $this->assertSame('Owen Owner', collect($props['comments'])->firstWhere('author.role', 'staff')['author']['name']);
    }

    #[Test]
    public function staff_see_who_wrote_what_and_who_it_reaches(): void
    {
        $this->awaitClient();
        $this->clientSays('Chrome.');

        $props = $this->actingAs($this->owner)->get($this->url())->viewData('page')['props'];
        $comments = collect($props['comments']);

        $this->assertSame('staff', $comments->firstWhere('author.name', 'Owen Owner')['author']['role']);
        $this->assertSame('client', $comments->firstWhere('author.name', 'Brian Power')['author']['role']);
        $this->assertSame('Visible to: Brian Power, Mia', $comments->firstWhere('author.name', 'Owen Owner')['audience']);
        $this->assertSame('Visible to: Brian Power, Mia', $props['composer']['public']);
        $this->assertSame('Internal — staff only', $props['composer']['internal']);
        $this->assertTrue(collect($props['events'])->every(fn ($e) => array_key_exists('is_internal', $e)));
    }

    // --- helpers ---------------------------------------------------------------

    private function awaitClient(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url('/await-client'), ['body' => $this->doc('Which browser?')])
            ->assertSessionHasNoErrors();
    }

    private function clientSays(string $text): void
    {
        $this->actingAs($this->jane)->post($this->url('/comments'), ['body' => $this->doc($text)])->assertRedirect();
    }

    private function assertNotified(User $user, NotificationReason $reason): void
    {
        $this->assertTrue(
            DB::table('pending_notifications')->where('user_id', $user->id)->where('reason', $reason->value)->exists(),
            "{$user->name} was not told ({$reason->value}).",
        );
    }

    private function assertNotNotified(User $user): void
    {
        $this->assertFalse(
            DB::table('pending_notifications')->where('user_id', $user->id)->exists(),
            "{$user->name} was told something.",
        );
    }

    private function statusNamed(string $name): Status
    {
        return $this->kd->statuses()->where('name', $name)->firstOrFail();
    }

    private function reload(): Issue
    {
        return $this->tenant(fn () => Issue::with('status')->findOrFail($this->issue->id));
    }

    private function url(string $suffix = ''): string
    {
        return $this->workspaceUrl($this->workspace, "/issues/{$this->issue->key}{$suffix}");
    }

    /** @return array<string, mixed> */
    private function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    private function client(string $name, Project $project, ProjectRole $tier): User
    {
        $user = $this->join(WorkspaceRole::Client, $name);
        $project->clients()->attach($user->id, ['role' => $tier->value]);

        return $user;
    }

    private function join(WorkspaceRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->workspace->members()->attach($user->id, ['role' => $role->value, 'joined_at' => now()]);

        return $user;
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
