<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Enums\NotificationReason;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\PendingNotification;
use App\Models\Project;
use App\Models\Report;
use App\Models\SavedView;
use App\Models\User;
use App\Models\WidgetKey;
use App\Models\Workspace;
use App\Notifications\IssueDigest;
use App\Notifications\VerifyEmailAddress;
use App\Support\Tenancy\Tenancy;
use App\Support\Webhooks\SafeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The second pass of the security review: oracles in the query language, hostile
 * widget payloads, replayed mail, stale sessions, rebinding webhook hosts, staff
 * names reaching clients, and addresses nobody has proved are theirs.
 */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $acme;

    private User $owner;

    private Project $web;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->acme, $this->owner] = $this->workspaceWithMember(slug: 'acme');
        $this->acme->update(['name' => 'Acme Agency']);
        $this->owner->update(['name' => 'Olive Owner']);
        $this->web = $this->tenant(fn () => Project::factory()->create(['name' => 'Website', 'key' => 'WEB', 'slug' => 'web']));

        $this->client = User::factory()->create(['name' => 'Cleo Client']);
        $this->acme->members()->attach($this->client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->web->clients()->attach($this->client->id, ['role' => ProjectRole::ClientManager->value]);
    }

    #[Test]
    public function a_client_cannot_learn_an_internal_parents_key_from_the_filter(): void
    {
        $internal = $this->issue('Internal epic', 'internal');
        $child = $this->issue('Homepage', 'client');
        $this->tenant(fn () => $child->forceFill(['parent_id' => $internal->id])->save());

        $this->assertSame([], $this->titles($this->client, "parent:{$internal->key}"));
        $this->assertSame(['Homepage'], $this->titles($this->client, 'no:parent'));
        // Staff see the structure as it is.
        $this->assertSame(['Homepage'], $this->titles($this->owner, "parent:{$internal->key}"));
    }

    #[Test]
    public function a_widget_report_is_capped_and_keeps_only_http_page_addresses(): void
    {
        $key = $this->tenant(fn () => WidgetKey::factory()->create(['project_id' => $this->web->id]));

        $this->postJson("/api/ingest/{$key->public_key}", [
            'title' => 'Padding', 'environment' => ['junk' => str_repeat('x', 300_000)],
        ])->assertStatus(413);

        $this->postJson("/api/ingest/{$key->public_key}", [
            'title' => 'Hostile link',
            'environment' => ['url' => 'javascript:alert(document.cookie)', 'viewport' => ['w' => 1200]],
            'console' => [['level' => 'error', 'message' => 'boom', 'smuggled' => str_repeat('y', 5000)]],
        ])->assertSuccessful();

        $report = $this->tenant(fn () => Report::latest('id')->firstOrFail());
        $this->assertNull($report->environment['url']);
        $this->assertSame(['w' => 1200], $report->environment['viewport']);
        $this->assertSame([['level' => 'error', 'message' => 'boom']], $report->console);
    }

    #[Test]
    public function a_signed_mail_delivery_is_accepted_once(): void
    {
        config(['buggie.mailgun_signing_key' => 'k']);
        $ts = (string) time();
        $payload = [
            'recipient' => "bugs+{$this->web->inbound_token}@in.buggie.test",
            'from' => 'someone@example.com', 'subject' => 'Once', 'stripped-text' => 'Hello',
            'timestamp' => $ts, 'token' => 'unique-delivery', 'signature' => hash_hmac('sha256', $ts.'unique-delivery', 'k'),
        ];

        $this->postJson('/api/mail/inbound', $payload)->assertOk();
        $this->postJson('/api/mail/inbound', $payload)->assertForbidden();

        $this->assertSame(1, $this->tenant(fn () => Issue::count()));
    }

    #[Test]
    public function a_changed_password_ends_every_other_session(): void
    {
        // A real sign-in, so the session is what carries it from request to request.
        $this->post(central_url('login'), ['email' => $this->owner->email, 'password' => 'password']);
        $this->get($this->workspaceUrl($this->acme, '/issues'))->assertOk();

        // Reset elsewhere — by the owner, from another browser.
        $this->owner->forceFill(['password' => bcrypt('a-new-password-entirely')])->save();
        $this->app['auth']->forgetGuards();

        $this->get($this->workspaceUrl($this->acme, '/issues'))->assertRedirect();
        $this->assertGuest();
    }

    #[Test]
    public function a_webhook_goes_to_the_address_that_was_checked(): void
    {
        SafeUrl::resolveUsing(fn (string $host) => ['93.184.216.34']);

        $this->assertSame(
            ['curl' => [CURLOPT_RESOLVE => ['hooks.example.com:443:93.184.216.34']]],
            SafeUrl::pinned('https://hooks.example.com/buggie'),
        );

        // IPv6 forms that reach internal IPv4 space are refused too.
        foreach (['64:ff9b::a9fe:a9fe', '2002:a9fe:a9fe::1', '::ffff:10.0.0.1'] as $address) {
            SafeUrl::resolveUsing(fn (string $host) => [$address]);
            $this->assertFalse(SafeUrl::check('https://sneaky.example.com/')[0], $address);
        }

        SafeUrl::resolveNormally();
    }

    #[Test]
    public function a_clients_saved_view_is_theirs_alone(): void
    {
        $this->actingAs($this->client)
            ->post($this->workspaceUrl($this->acme, '/views'), [
                'name' => 'Mine', 'query' => 'is:open', 'layout' => 'list', 'group_by' => 'status', 'shared' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->client->id, $this->tenant(fn () => SavedView::sole()->user_id));
    }

    #[Test]
    public function a_client_is_not_sent_the_billing_date_or_the_unreleased_versions(): void
    {
        $this->tenant(fn () => $this->web->versions()->create(['name' => '3.0-secret-rebrand']));
        $issue = $this->issue('Homepage', 'client');

        $this->actingAs($this->client)->get($this->workspaceUrl($this->acme, '/'))
            ->assertInertia(fn ($page) => $page->where('workspace.trial_ends_at', null));
        $this->actingAs($this->client)->get($this->workspaceUrl($this->acme, "/issues/{$issue->key}"))
            ->assertInertia(fn ($page) => $page->where('versions', []));
    }

    #[Test]
    public function a_client_reads_the_team_as_the_workspace_in_their_email_and_export(): void
    {
        $issue = $this->issue('Homepage', 'client');
        $this->tenant(fn () => $issue->forceFill(['assignee_id' => $this->owner->id])->save());

        $entries = $this->tenant(fn () => collect([PendingNotification::create([
            'user_id' => $this->client->id, 'issue_id' => $issue->id, 'actor_id' => $this->owner->id,
            'reason' => NotificationReason::Commented->value, 'data' => [], 'created_at' => now(),
        ])->load('actor')]));

        $mail = $this->tenant(fn () => (new IssueDigest($issue->fresh(['workspace', 'project']), $entries))->toMail($this->client));
        $text = implode(' ', $mail->introLines);
        $this->assertStringContainsString('Acme Agency', $text);
        $this->assertStringNotContainsString('Olive', $text);

        $csv = $this->actingAs($this->client)->get($this->workspaceUrl($this->acme, '/issues/export'))->streamedContent();
        $this->assertStringNotContainsString('Olive', $csv);
        $this->assertStringContainsString('Acme Agency', $csv);
    }

    #[Test]
    public function a_new_account_is_sent_a_link_and_must_use_it_where_the_install_asks(): void
    {
        Notification::fake();
        config(['buggie.registration' => 'open', 'buggie.require_verified_email' => true]);

        $this->post(central_url('register'), [
            'name' => 'New Person', 'email' => 'new@example.com',
            'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
        ]);

        $user = User::where('email', 'new@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailAddress::class);
        $this->assertFalse($user->hasVerifiedEmail());

        $this->actingAs($user)->get(central_url('workspaces/create'))->assertRedirect(central_url('email/verify'));

        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($link)->assertRedirect();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->actingAs($user->fresh())->get(central_url('workspaces/create'))->assertOk();
    }

    #[Test]
    public function being_named_an_operator_needs_a_confirmed_address(): void
    {
        config(['buggie.operators' => ['ops@example.com']]);

        $squatter = User::factory()->unverified()->create(['email' => 'ops@example.com']);
        $this->assertFalse($squatter->can('operate'));

        $squatter->markEmailAsVerified();
        $this->assertTrue($squatter->fresh()->can('operate'));
    }

    #[Test]
    public function pages_carry_the_security_headers_and_an_attachment_keeps_its_own(): void
    {
        $page = $this->actingAs($this->owner)->get($this->workspaceUrl($this->acme, '/issues'));

        $this->assertStringContainsString("frame-ancestors 'self'", $page->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $page->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $page->headers->get('Referrer-Policy'));
        $this->assertNull($page->headers->get('Strict-Transport-Security'), 'Only over HTTPS.');

        $secure = $this->actingAs($this->owner)->get(str_replace('http://', 'https://', $this->workspaceUrl($this->acme, '/issues')));
        $this->assertStringContainsString('max-age=31536000', (string) $secure->headers->get('Strict-Transport-Security'));
    }

    /** @return array<int, string> */
    private function titles(User $as, string $q): array
    {
        return collect($this->actingAs($as)->get($this->workspaceUrl($this->acme, '/issues?q='.urlencode($q)))
            ->viewData('page')['props']['issues'])->pluck('title')->sort()->values()->all();
    }

    private function issue(string $title, string $visibility): Issue
    {
        return $this->tenant(fn () => app(CreateIssue::class)->handle($this->web, ['title' => $title, 'visibility' => $visibility], $this->owner));
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->acme, $callback);
    }
}
