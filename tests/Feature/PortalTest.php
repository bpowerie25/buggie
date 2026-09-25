<?php

namespace Tests\Feature;

use App\Actions\AddComment;
use App\Actions\TriageReport;
use App\Enums\IssueVisibility;
use App\Models\Issue;
use App\Models\PortalToken;
use App\Models\Project;
use App\Models\Report;
use App\Notifications\PortalAccess;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The reporter's own thread. The token is the entire credential, so these tests are
 * mostly about what it must NOT open.
 */
class PortalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function promoting_a_named_report_emails_the_reporter_a_link(): void
    {
        Notification::fake();
        [$workspace, $staff] = $this->workspaceWithMember(slug: 'acme');

        $report = app(Tenancy::class)->run($workspace, fn () => Report::factory()->create([
            'project_id' => Project::factory()->create(['key' => 'WEB'])->id,
            'reporter_email' => 'ana@shopper.test',
        ]));

        app(Tenancy::class)->run($workspace, fn () => app(TriageReport::class)->accept($report, $staff));

        Notification::assertSentOnDemand(PortalAccess::class);

        $this->assertDatabaseHas('portal_tokens', ['email' => 'ana@shopper.test']);
    }

    #[Test]
    public function an_anonymous_report_gets_no_email(): void
    {
        Notification::fake();
        [$workspace, $staff] = $this->workspaceWithMember(slug: 'acme');

        $report = app(Tenancy::class)->run($workspace, fn () => Report::factory()->anonymous()->create([
            'project_id' => Project::factory()->create()->id,
        ]));

        app(Tenancy::class)->run($workspace, fn () => app(TriageReport::class)->accept($report, $staff));

        // Emailing someone who did not leave an address is not a feature.
        Notification::assertNothingSent();
        $this->assertDatabaseCount('portal_tokens', 0);
    }

    #[Test]
    public function the_reporter_sees_their_issue_but_not_internal_comments(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(slug: 'acme');

        $token = app(Tenancy::class)->run($workspace, function () use ($staff) {
            $issue = Issue::factory()->clientVisible()->create([
                'project_id' => Project::factory()->create(['key' => 'WEB'])->id,
                'title' => 'Checkout is broken',
            ]);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('Dave broke the migration again'),
                'is_internal' => true,
            ], $staff);

            app(AddComment::class)->handle($issue, [
                'body' => $this->doc('We are on it, sorry about that'),
                'is_internal' => false,
            ], $staff);

            return PortalToken::issueFor($issue, 'ana@shopper.test');
        });

        $this->get(central_url('portal/'.$token->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portal/show')
                ->where('issue.title', 'Checkout is broken')
                ->has('comments', 1))
            ->assertDontSee('Dave broke the migration', false);
    }

    #[Test]
    public function the_portal_shows_a_state_not_the_teams_status_name(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $token = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create();
            // A name that would confuse a customer.
            $project->statuses()->where('category', 'canceled')->first()
                ->update(['name' => 'Won\'t Fix']);

            $issue = Issue::factory()->inStatus('canceled')->create(['project_id' => $project->id]);

            return PortalToken::issueFor($issue, 'ana@shopper.test');
        });

        $this->get(central_url('portal/'.$token->token))
            ->assertInertia(fn ($page) => $page->where('issue.state', 'closed'));
    }

    #[Test]
    public function a_reporter_can_reply_and_it_is_always_public(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $token = app(Tenancy::class)->run($workspace, fn () => PortalToken::issueFor(
            Issue::factory()->create(['project_id' => Project::factory()->create()->id]),
            'ana@shopper.test',
        ));

        $this->post(central_url('portal/'.$token->token.'/comment'), [
            'body' => 'Still happening this morning.',
        ])->assertRedirect();

        $this->assertDatabaseHas('comments', [
            'author_email' => 'ana@shopper.test',
            'is_internal' => false,
            'source' => 'portal',
            'user_id' => null,
        ]);

        // An anonymous reply does not share an internal issue with every client on the
        // project; the reporter keeps it through their link regardless.
        $issue = app(Tenancy::class)->run($workspace, fn () => $token->issue->fresh());
        $this->assertSame(IssueVisibility::Internal, $issue->visibility);
        $this->get(central_url('portal/'.$token->token))->assertOk()->assertSee('Still happening this morning.', false);
    }

    #[Test]
    public function an_expired_or_unknown_token_opens_nothing(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $expired = app(Tenancy::class)->run($workspace, fn () => PortalToken::create([
            'issue_id' => Issue::factory()->create(['project_id' => Project::factory()->create()->id])->id,
            'email' => 'ana@shopper.test',
            'expires_at' => now()->subDay(),
        ]));

        $this->get(central_url('portal/'.$expired->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('portal/invalid'));

        $this->get(central_url('portal/totally-made-up'))
            ->assertInertia(fn ($page) => $page->component('portal/invalid'));

        $this->post(central_url('portal/totally-made-up/comment'), ['body' => 'hello'])
            ->assertNotFound();
    }

    #[Test]
    public function a_token_opens_exactly_one_issue(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        [$token, $other] = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create(['key' => 'WEB']);

            return [
                PortalToken::issueFor(
                    Issue::factory()->create(['project_id' => $project->id, 'title' => 'Theirs']),
                    'ana@shopper.test',
                ),
                Issue::factory()->create(['project_id' => $project->id, 'title' => 'Someone elses']),
            ];
        });

        $this->get(central_url('portal/'.$token->token))
            ->assertInertia(fn ($page) => $page->where('issue.title', 'Theirs'));

        // There is no parameter to point it elsewhere, and the app's issue routes do
        // not exist on the central domain at all.
        $this->get(central_url('issues/'.$other->key))->assertNotFound();
    }

    #[Test]
    public function replies_are_rate_limited(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $token = app(Tenancy::class)->run($workspace, fn () => PortalToken::issueFor(
            Issue::factory()->create(['project_id' => Project::factory()->create()->id]),
            'ana@shopper.test',
        ));

        for ($i = 0; $i < 10; $i++) {
            $this->post(central_url('portal/'.$token->token.'/comment'), ['body' => "message {$i}"]);
        }

        $this->post(central_url('portal/'.$token->token.'/comment'), ['body' => 'one too many'])
            ->assertSessionHasErrors('body');
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
}
