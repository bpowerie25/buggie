<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\InviteToWorkspace;
use App\Actions\UpdateIssue;
use App\Enums\ClientAudience;
use App\Enums\IssueEventType;
use App\Enums\NotificationReason;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\InAppNotification;
use App\Models\Invitation;
use App\Models\Issue;
use App\Models\MemberEvent;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which clients see a client-visible issue.
 *
 * The tier on the project grant is the default: a client manager sees every
 * client-visible issue, a client only what they reported or watch. An issue's
 * audience can widen that — to every client on the project, or to named clients —
 * and never past the project, and never onto an internal issue.
 *
 * Kennco has Jane and Tom (own issues only) and Mia (client manager). Zed is a client
 * of the same agency on another project, and must never see any of it.
 */
class ClientAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private Project $kennco;

    private User $jane;

    private User $tom;

    private User $mia;

    private User $zed;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'agency');
        $this->member = $this->join(WorkspaceRole::Member, 'Staff Member');

        $this->kennco = $this->tenant(fn () => Project::factory()->create(['name' => 'Kennco', 'key' => 'KEN']));
        $globex = $this->tenant(fn () => Project::factory()->create(['name' => 'Globex', 'key' => 'GLX']));

        $this->jane = $this->client('Jane', $this->kennco, ProjectRole::Client);
        $this->tom = $this->client('Tom', $this->kennco, ProjectRole::Client);
        $this->mia = $this->client('Mia', $this->kennco, ProjectRole::ClientManager);
        $this->zed = $this->client('Zed', $globex, ProjectRole::ClientManager);
    }

    // --- the combinations -----------------------------------------------------

    #[Test]
    public function default_audience_hides_it_from_a_plain_client_unless_they_reported_or_watch_it(): void
    {
        $issue = $this->issue('Default audience');

        $this->assertSees($this->mia, $issue);          // manager tier
        $this->assertDoesNotSee($this->jane, $issue);   // plain client, not involved
        $this->assertDoesNotSee($this->zed, $issue);

        $reported = $this->issue('Jane reported this', reporter: $this->jane);
        $this->assertSees($this->jane, $reported);
        $this->assertDoesNotSee($this->tom, $reported);

        $issue->watchers()->attach($this->tom->id, ['reason' => 'mentioned']);
        $this->assertSees($this->tom, $issue);
    }

    #[Test]
    public function all_clients_on_the_project_see_it_whatever_their_tier(): void
    {
        $issue = $this->issue('Everyone at Kennco');
        $this->setAudience($issue, ['client_audience' => 'project']);

        foreach ([$this->jane, $this->tom, $this->mia] as $client) {
            $this->assertSees($client, $issue);
        }

        $this->assertDoesNotSee($this->zed, $issue);
    }

    #[Test]
    public function a_specific_share_is_seen_by_the_named_client_only(): void
    {
        $issue = $this->issue('Just for Jane');
        $this->setAudience($issue, ['client_audience' => 'specific', 'client_share_ids' => [$this->jane->id]]);

        $this->assertSees($this->jane, $issue);
        $this->assertDoesNotSee($this->tom, $issue);
        $this->assertSees($this->mia, $issue, 'Specific is the default audience plus the named.');
        $this->assertDoesNotSee($this->zed, $issue);
    }

    #[Test]
    public function a_client_not_on_the_project_never_sees_it(): void
    {
        $issue = $this->issue('Kennco only');

        // Cannot be named...
        $this->actingAs($this->owner)
            ->patch($this->issueUrl($issue), ['client_audience' => 'specific', 'client_share_ids' => [$this->zed->id]])
            ->assertSessionHasErrors('client_share_ids');

        // ...and a share written straight into the table gives nothing either, in
        // any audience.
        DB::table('issue_client_shares')->insert(['issue_id' => $issue->id, 'user_id' => $this->zed->id, 'created_at' => now()]);

        foreach (['default', 'project', 'specific'] as $audience) {
            DB::table('issues')->where('id', $issue->id)->update(['client_audience' => $audience]);
            $this->assertDoesNotSee($this->zed, $issue, "Zed saw it with the [{$audience}] audience.");
        }
    }

    #[Test]
    public function an_internal_issue_is_never_seen_whatever_its_audience(): void
    {
        $issue = $this->issue('Internal', ['visibility' => 'internal'], reporter: $this->owner);
        $this->setAudience($issue, ['client_audience' => 'specific', 'client_share_ids' => [$this->jane->id]]);

        foreach (['specific', 'project'] as $audience) {
            DB::table('issues')->where('id', $issue->id)->update(['client_audience' => $audience]);

            foreach ([$this->jane, $this->tom, $this->mia] as $client) {
                $this->assertDoesNotSee($client, $issue, "{$client->name} saw an internal issue [{$audience}].");
            }
        }
    }

    #[Test]
    public function losing_the_project_takes_a_share_with_it(): void
    {
        $issue = $this->issue('Shared then revoked');
        $this->setAudience($issue, ['client_audience' => 'specific', 'client_share_ids' => [$this->jane->id]]);

        $this->jane->projects()->detach($this->kennco->id);

        $this->assertDoesNotSee($this->jane, $issue);
    }

    #[Test]
    public function going_back_to_the_default_takes_the_shares_away(): void
    {
        $issue = $this->issue('Shared then unshared');
        $this->setAudience($issue, ['client_audience' => 'specific', 'client_share_ids' => [$this->jane->id]]);
        $this->setAudience($issue, ['client_audience' => 'default']);

        $this->assertDoesNotSee($this->jane, $issue);
        $this->assertSame(0, DB::table('issue_client_shares')->where('issue_id', $issue->id)->count());
    }

    #[Test]
    public function a_share_survives_unwatching(): void
    {
        $issue = $this->issue('Shared and watched');
        $issue->watchers()->attach($this->jane->id, ['reason' => 'mentioned']);
        $this->setAudience($issue, ['client_audience' => 'specific', 'client_share_ids' => [$this->jane->id]]);

        $this->actingAs($this->jane)->delete($this->issueUrl($issue, '/watch'));

        $this->assertFalse($issue->watchers()->whereKey($this->jane->id)->exists());
        $this->assertSees($this->jane, $issue);
    }

    // --- who may change it -----------------------------------------------------

    #[Test]
    public function a_client_cannot_change_the_audience(): void
    {
        $issue = $this->issue('Mia manages', reporter: $this->mia);

        $this->actingAs($this->mia)
            ->patch($this->issueUrl($issue), ['client_audience' => 'project'])
            ->assertForbidden();

        $this->assertSame(ClientAudience::Default, $this->reload($issue)->client_audience);
    }

    #[Test]
    public function staff_change_it_and_the_feed_records_it_internally(): void
    {
        $issue = $this->issue('Audit me', reporter: $this->jane);

        $this->actingAs($this->member)
            ->patch($this->issueUrl($issue), ['client_audience' => 'specific', 'client_share_ids' => [$this->tom->id]])
            ->assertSessionHasNoErrors();

        $event = $this->reload($issue)->events()->where('type', IssueEventType::AudienceChanged->value)->sole();
        $this->assertEquals(['from' => 'default', 'to' => 'specific', 'clients' => ['Tom']], $event->data);
        $this->assertTrue($event->is_internal, 'An event naming clients reached the client-facing feed.');

        // Jane reported it and can see it, but not who else it was shared with.
        $this->actingAs($this->jane)
            ->get($this->issueUrl($issue))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('issue.audience_label', null)
                ->where('issue.client_share_ids', [])
                ->where('projectClients', []))
            ->assertDontSee('audience_changed');
    }

    #[Test]
    public function the_badge_says_who_can_actually_see_it(): void
    {
        $issue = $this->issue('Badge', reporter: $this->jane);
        $label = fn () => $this->actingAs($this->owner)->get($this->issueUrl($issue));

        $label()->assertInertia(fn ($page) => $page->where('issue.audience_label', 'Visible to: Jane, Mia'));

        $this->setAudience($issue, ['client_audience' => 'project']);
        $label()->assertInertia(fn ($page) => $page->where('issue.audience_label', 'All Kennco clients'));

        $this->setAudience($issue, ['client_audience' => 'specific', 'client_share_ids' => [$this->tom->id]]);
        $label()->assertInertia(fn ($page) => $page->where('issue.audience_label', 'Visible to: Jane, Mia, Tom'));

        $this->setAudience($issue, ['visibility' => 'internal']);
        $label()->assertInertia(fn ($page) => $page->where('issue.audience_label', 'Internal only'));
    }

    // --- the tier on the members screen ----------------------------------------

    #[Test]
    public function an_admin_changes_a_clients_tier_and_it_is_logged(): void
    {
        $issue = $this->issue('Kennco work');
        $this->assertDoesNotSee($this->jane, $issue);

        $this->actingAs($this->owner)
            ->patch($this->workspaceUrl($this->workspace, "/settings/members/{$this->jane->id}/projects/{$this->kennco->id}/tier"), [
                'tier' => 'client_manager',
            ])
            ->assertSessionHas('success');

        $this->assertSees($this->jane, $issue);

        $event = $this->tenant(fn () => MemberEvent::sole());
        $this->assertSame([$this->owner->id, $this->jane->id, $this->kennco->id], [$event->actor_id, $event->user_id, $event->project_id]);
        $this->assertEquals(['from' => 'client', 'to' => 'client_manager'], $event->data);

        $this->actingAs($this->owner)
            ->get($this->workspaceUrl($this->workspace, '/settings/members'))
            ->assertInertia(fn ($page) => $page->where('memberEvents.0.subject', 'Jane')->where('memberEvents.0.to', 'Client manager'));
    }

    #[Test]
    public function nobody_but_an_admin_changes_a_tier(): void
    {
        $url = $this->workspaceUrl($this->workspace, "/settings/members/{$this->jane->id}/projects/{$this->kennco->id}/tier");

        foreach ([$this->mia, $this->jane, $this->member] as $who) {
            $this->actingAs($who)->patch($url, ['tier' => 'client_manager'])->assertForbidden();
        }

        $this->assertSame('client', $this->jane->projects()->whereKey($this->kennco->id)->value('project_user.role'));
    }

    #[Test]
    public function a_tier_is_changed_only_on_a_project_the_client_holds(): void
    {
        $globex = $this->zed->projects()->sole();

        $this->actingAs($this->owner)
            ->patch($this->workspaceUrl($this->workspace, "/settings/members/{$this->jane->id}/projects/{$globex->id}/tier"), ['tier' => 'client_manager'])
            ->assertNotFound();

        $this->assertFalse($this->jane->projects()->whereKey($globex->id)->exists());
    }

    #[Test]
    public function an_invitation_carries_the_tier_and_defaults_to_own_issues(): void
    {
        Notification::fake();

        $this->actingAs($this->owner)->post($this->workspaceUrl($this->workspace, '/settings/members'), [
            'email' => 'boss@kennco.test',
            'role' => 'client',
            'project_ids' => [$this->kennco->id],
            'tiers' => [$this->kennco->id => 'client_manager'],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post($this->workspaceUrl($this->workspace, '/settings/members'), [
            'email' => 'staff@kennco.test',
            'role' => 'client',
            'project_ids' => [$this->kennco->id],
        ])->assertSessionHasNoErrors();

        foreach (['boss@kennco.test' => 'client_manager', 'staff@kennco.test' => 'client'] as $email => $tier) {
            $person = User::factory()->create(['email' => $email]);
            $invitation = $this->tenant(fn () => Invitation::where('email', $email)->sole());

            app(InviteToWorkspace::class)->accept($invitation, $person);

            $this->assertSame($tier, $person->projects()->whereKey($this->kennco->id)->value('project_user.role'), $email);
        }
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * Every way a client reads issues must agree: the detail page, the list, the
     * search, the export, the API and the notification list. They all go through
     * one scope; this proves they still do.
     */
    private function assertSees(User $client, Issue $issue, string $message = ''): void
    {
        $this->assertVisibility($client, $issue, true, $message);
    }

    private function assertDoesNotSee(User $client, Issue $issue, string $message = ''): void
    {
        $this->assertVisibility($client, $issue, false, $message);
    }

    private function assertVisibility(User $client, Issue $issue, bool $visible, string $message): void
    {
        $why = $message ?: "{$client->name} ".($visible ? 'could not see' : 'saw')." [{$issue->title}]";
        $see = $visible ? 'assertSee' : 'assertDontSee';

        $this->actingAs($client)->get($this->issueUrl($issue))->assertStatus($visible ? 200 : 404);

        $this->actingAs($client)->get($this->workspaceUrl($this->workspace, '/issues?q=is:all'))->{$see}($issue->title, false);
        $this->actingAs($client)->get($this->workspaceUrl($this->workspace, '/issues?q='.urlencode($issue->title)))->{$see}($issue->key, false);
        // A streamed download, so read rather than asserted on.
        $csv = $this->actingAs($client)->get($this->workspaceUrl($this->workspace, '/issues/export?q=is:all'))->streamedContent();
        $this->assertSame($visible, str_contains($csv, $issue->key), $why.' (export)');

        // Signed out first, or Sanctum takes the session user and never reads the token.
        $this->app['auth']->forgetGuards();
        $token = $client->createTokenForWorkspace($this->workspace, 'test')->plainTextToken;
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->get($this->workspaceUrl($this->workspace, '/api/v1/issues'))
            ->{$see}($issue->key, false);
        $this->app['auth']->forgetGuards();

        $notified = $this->tenant(function () use ($client, $issue) {
            InAppNotification::create([
                'user_id' => $client->id, 'issue_id' => $issue->id,
                'reason' => NotificationReason::Commented->value, 'data' => [], 'created_at' => now(),
            ]);

            return InAppNotification::query()->visibleTo($client)->where('issue_id', $issue->id)->exists();
        });

        $this->assertSame($visible, $notified, $why.' (notifications)');
    }

    private function issue(string $title, array $attributes = [], ?User $reporter = null): Issue
    {
        return $this->tenant(fn () => app(CreateIssue::class)->handle(
            $this->kennco,
            ['title' => $title, 'visibility' => 'client', ...$attributes],
            $reporter ?? $this->owner,
        ));
    }

    private function setAudience(Issue $issue, array $changes): void
    {
        $this->tenant(fn () => app(UpdateIssue::class)->handle($this->reload($issue), $changes, $this->owner));
    }

    private function reload(Issue $issue): Issue
    {
        return $this->tenant(fn () => Issue::findOrFail($issue->id));
    }

    private function issueUrl(Issue $issue, string $suffix = ''): string
    {
        return $this->workspaceUrl($this->workspace, "/issues/{$issue->key}{$suffix}");
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
