<?php

namespace Tests\Feature;

use App\Actions\TriageReport;
use App\Enums\ProjectRole;
use App\Enums\ReporterIdentity;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Models\WidgetKey;
use App\Models\Workspace;
use App\Support\Reports\ReporterLink;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who sent a widget report, how sure we are, and what that is allowed to grant.
 *
 * Kennco is a client member of the project. Only a verified identity — the customer's
 * server signing "id:email" with the key's secret — links a report to her, unless the
 * workspace has chosen to trust unverified addresses. Linking is only ever setting
 * reporter_id; the "own issues" rule does the rest.
 */
class ReporterIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    private WidgetKey $key;

    private User $kennco;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');

        [$this->project, $this->key] = $this->tenant(function () {
            $project = Project::factory()->create(['key' => 'KD']);

            return [$project, WidgetKey::factory()->create(['project_id' => $project->id])];
        });

        $this->kennco = User::factory()->create(['email' => 'ann@kennco.test', 'name' => 'Ann Kennco']);
        $this->workspace->members()->attach($this->kennco->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->project->clients()->attach($this->kennco->id, ['role' => ProjectRole::Client->value]);
    }

    // --- each identity level ----------------------------------------------------

    #[Test]
    public function nothing_given_is_anonymous(): void
    {
        $this->report(['reporter' => []])->assertStatus(202);

        $this->assertLevel(ReporterIdentity::Anonymous);
    }

    #[Test]
    public function a_typed_email_is_unverified(): void
    {
        $this->report(['reporter' => ['email' => 'ann@kennco.test', 'source' => 'typed']])->assertStatus(202);

        $this->assertLevel(ReporterIdentity::EmailUnverified);
    }

    #[Test]
    public function identify_without_a_hash_is_identified(): void
    {
        $this->report(['reporter' => ['ref' => '4821', 'email' => 'ann@kennco.test', 'name' => 'Ann', 'source' => 'identify']])->assertStatus(202);

        $this->assertLevel(ReporterIdentity::Identified);
    }

    #[Test]
    public function a_valid_hash_over_id_and_email_is_verified(): void
    {
        $this->report(['reporter' => $this->signed('4821', 'ann@kennco.test')])->assertStatus(202);

        $this->assertLevel(ReporterIdentity::Verified);
    }

    #[Test]
    public function a_bad_hash_is_downgraded_to_identified_and_logged(): void
    {
        Log::spy();

        // A real hash for this id, paired with somebody else's address: the attack
        // that signing the email as well as the id exists to stop.
        $stolen = $this->signed('4821', 'someone.else@kennco.test');
        $stolen['email'] = 'ann@kennco.test';

        $this->report(['reporter' => $stolen])->assertStatus(202);

        $this->assertLevel(ReporterIdentity::Identified);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'user_hash')
            && $context['widget_key'] === $this->key->public_key
            && ! str_contains(json_encode($context), $stolen['user_hash']))->once();
    }

    #[Test]
    public function the_hash_is_never_stored(): void
    {
        $hash = $this->signed('4821', 'ann@kennco.test')['user_hash'];

        $this->report([
            'reporter' => $this->signed('4821', 'ann@kennco.test'),
            'environment' => ['identity' => ['id' => '4821', 'user_hash' => $hash]],
        ]);

        // The widget strips it from the environment; the server must not rely on that.
        $this->assertStringNotContainsString($hash, json_encode(Report::withoutGlobalScopes()->sole()->getAttributes()));
    }

    // --- modes --------------------------------------------------------------------

    #[Test]
    public function require_verified_identity_refuses_anything_unsigned(): void
    {
        $this->key->update(['mode' => 'verified']);

        foreach ([[], ['email' => 'ann@kennco.test', 'source' => 'typed'], ['ref' => '1', 'email' => 'ann@kennco.test', 'source' => 'identify', 'user_hash' => 'nope']] as $reporter) {
            $this->report(['reporter' => $reporter])
                ->assertStatus(422)
                ->assertJsonPath('message', 'This site only accepts reports from signed-in users. Please sign in and try again, or contact the team directly.');
        }

        $this->assertSame(0, Report::withoutGlobalScopes()->count());

        $this->report(['reporter' => $this->signed('4821', 'ann@kennco.test')])->assertStatus(202);
    }

    #[Test]
    public function an_anonymous_key_ignores_what_the_page_says(): void
    {
        $this->key->update(['mode' => 'anonymous']);

        $this->report(['reporter' => $this->signed('4821', 'ann@kennco.test')])->assertStatus(202);

        $report = Report::withoutGlobalScopes()->sole();
        $this->assertSame('anonymous', $report->reporter_identity);
        $this->assertNull($report->reporter_email);
        $this->assertNull($report->reporter_ref);
    }

    #[Test]
    public function the_origin_is_checked_before_identity(): void
    {
        $this->key->update(['allowed_origins' => ['https://kennco.test'], 'mode' => 'verified']);

        $this->withHeaders(['Origin' => 'https://evil.test'])
            ->postJson("/api/ingest/{$this->key->public_key}", $this->payload(['reporter' => $this->signed('4821', 'ann@kennco.test')]))
            ->assertStatus(403);
    }

    // --- linking --------------------------------------------------------------------

    #[Test]
    public function a_verified_client_is_linked_and_can_then_see_the_issue(): void
    {
        $this->report(['reporter' => $this->signed('4821', 'ANN@kennco.test')]);

        $issue = $this->accept();

        $this->assertSame($this->kennco->id, $issue->reporter_id);
        $this->assertSame(ReporterIdentity::Verified, $issue->reporter_identity);

        $this->actingAs($this->kennco)
            ->get($this->workspaceUrl($this->workspace, "/issues/{$issue->key}"))
            ->assertOk();
    }

    #[Test]
    public function an_unverified_email_grants_nothing_unless_the_workspace_trusts_it(): void
    {
        $this->report(['reporter' => ['email' => 'ann@kennco.test', 'name' => 'Ann', 'source' => 'typed']]);
        $issue = $this->accept();

        $this->assertNotSame($this->kennco->id, $issue->reporter_id);
        $this->assertSame('ann@kennco.test', $issue->reporter_email);
        $this->assertSame('Ann', $issue->reporter_name);
        $this->actingAs($this->kennco)->get($this->workspaceUrl($this->workspace, "/issues/{$issue->key}"))->assertNotFound();

        $this->workspace->forceFill(['settings' => [ReporterLink::TRUST_UNVERIFIED => true]])->save();
        $this->report(['title' => 'Again', 'reporter' => ['email' => 'ann@kennco.test', 'source' => 'typed']]);
        $trusted = $this->accept();

        $this->assertSame($this->kennco->id, $trusted->reporter_id);
        $this->actingAs($this->kennco)->get($this->workspaceUrl($this->workspace, "/issues/{$trusted->key}"))->assertOk();
    }

    #[Test]
    public function staff_can_link_by_hand_but_only_to_a_client_on_the_project(): void
    {
        $this->report(['reporter' => ['email' => 'ann@kennco.test', 'source' => 'typed']]);
        $issue = $this->accept();

        $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, "/issues/{$issue->key}"))
            ->assertInertia(fn ($page) => $page
                ->where('issue.widget_reporter.label', 'Unverified email')
                ->where('issue.widget_reporter.page_url', 'https://kennco.test/checkout')
                ->where('issue.widget_reporter.candidates.0.name', 'Ann Kennco')
                ->where('issue.widget_reporter.candidates.0.matches', true));

        $stranger = User::factory()->create();
        $this->actingAs($this->owner)->post($this->workspaceUrl($this->workspace, "/issues/{$issue->key}/reporter"), ['user_id' => $stranger->id])
            ->assertSessionHasErrors('user_id');

        // A client cannot attribute an issue to anybody, themselves included.
        $this->actingAs($this->kennco)->post($this->workspaceUrl($this->workspace, "/issues/{$issue->key}/reporter"), ['user_id' => $this->kennco->id])
            ->assertForbidden();
        $this->assertNotSame($this->kennco->id, $issue->fresh()->reporter_id);

        $this->actingAs($this->owner)->post($this->workspaceUrl($this->workspace, "/issues/{$issue->key}/reporter"), ['user_id' => $this->kennco->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->kennco)->get($this->workspaceUrl($this->workspace, "/issues/{$issue->key}"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('issue.widget_reporter', null));
    }

    #[Test]
    public function widget_reports_still_land_in_triage_first(): void
    {
        $this->report(['reporter' => $this->signed('4821', 'ann@kennco.test')]);

        $this->assertSame(0, $this->tenant(fn () => Issue::count()));
        $this->assertSame(1, $this->tenant(fn () => Report::awaitingTriage()->count()));
    }

    // --- the secret -----------------------------------------------------------------

    #[Test]
    public function the_secret_never_reaches_a_browser_except_once_after_rotation(): void
    {
        $secret = $this->key->fresh()->secret;
        $this->assertStringStartsWith('whs_', $secret);

        $settings = $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, "/projects/{$this->project->slug}/edit"));
        $settings->assertOk()->assertDontSee($secret, false);

        $this->get(central_url("w/{$this->key->public_key}.js"))->assertDontSee($secret, false);
        $this->getJson("/api/ingest/{$this->key->public_key}/config")->assertOk()->assertDontSee($secret, false);
        $this->assertArrayNotHasKey('secret', $this->key->fresh()->toArray());

        // Rotating shows the new one once, to whoever rotated it, and never again.
        $this->actingAs($this->owner)->post($this->workspaceUrl($this->workspace, "/widget-keys/{$this->key->public_key}/secret"))
            ->assertSessionHas('widget_secret');
        $new = $this->key->fresh()->secret;
        $this->assertNotSame($secret, $new);

        $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, "/projects/{$this->project->slug}/edit"))
            ->assertInertia(fn ($page) => $page->where('revealedSecret.secret', $new));

        $this->actingAs($this->owner)->get($this->workspaceUrl($this->workspace, "/projects/{$this->project->slug}/edit"))
            ->assertDontSee($new, false);

        // And the old secret no longer verifies.
        $this->report(['reporter' => $this->signed('4821', 'ann@kennco.test', $secret)]);
        $this->assertLevel(ReporterIdentity::Identified);
    }

    #[Test]
    public function only_staff_who_manage_the_project_can_rotate(): void
    {
        $this->actingAs($this->kennco)->post($this->workspaceUrl($this->workspace, "/widget-keys/{$this->key->public_key}/secret"))->assertForbidden();
    }

    // --- rate limits --------------------------------------------------------------

    #[Test]
    public function submission_is_rate_limited(): void
    {
        $statuses = collect(range(1, 12))->map(fn ($i) => $this->report(['title' => "Report {$i}"])->status());

        $this->assertContains(429, $statuses->all(), 'Twelve reports a minute from one address were all accepted.');
    }

    // --- helpers --------------------------------------------------------------------

    /** @return array<string, string> */
    private function signed(string $id, string $email, ?string $secret = null): array
    {
        $secret ??= $this->key->fresh()->secret;

        return [
            'ref' => $id,
            'email' => $email,
            'name' => 'Ann',
            'source' => 'identify',
            'user_hash' => hash_hmac('sha256', "{$id}:{$email}", $secret),
        ];
    }

    private function report(array $overrides = []): TestResponse
    {
        return $this->postJson("/api/ingest/{$this->key->public_key}", $this->payload($overrides));
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $base = [
            'title' => 'Checkout button does nothing',
            'body' => null,
            'reporter' => [],
            'environment' => ['url' => 'https://kennco.test/checkout'],
            'console' => [],
            'network' => [],
        ];

        foreach ($overrides as $key => $value) {
            $base[$key] = $key === 'environment' ? [...$base['environment'], ...$value] : $value;
        }

        return $base;
    }

    private function assertLevel(ReporterIdentity $level): void
    {
        $this->assertSame($level->value, Report::withoutGlobalScopes()->latest('id')->first()->reporter_identity);
    }

    private function accept(): Issue
    {
        $report = $this->tenant(fn () => Report::latest('id')->firstOrFail());

        return $this->tenant(fn () => app(TriageReport::class)->accept($report, $this->owner)->fresh());
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
