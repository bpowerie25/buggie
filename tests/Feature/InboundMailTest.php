<?php

namespace Tests\Feature;

use App\Enums\IssueVisibility;
use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Mail\ReplyAddress;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Anyone can POST to this endpoint, so the Mailgun signature is the gate and the
 * routing token in the address decides the tenant.
 */
class InboundMailTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNING_KEY = 'test-signing-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['buggie.mailgun_signing_key' => self::SIGNING_KEY]);
    }

    /** @return array<string, mixed> */
    private function signed(array $payload, ?string $key = null): array
    {
        $timestamp = (string) time();
        $token = 'tok'.uniqid();

        return [
            ...$payload,
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => hash_hmac('sha256', $timestamp.$token, $key ?? self::SIGNING_KEY),
        ];
    }

    #[Test]
    public function an_unsigned_or_wrongly_signed_post_is_refused(): void
    {
        $this->postJson('/api/mail/inbound', ['recipient' => 'bugs+abc@in.buggie.test'])
            ->assertForbidden();

        $this->postJson('/api/mail/inbound', $this->signed(
            ['recipient' => 'bugs+abc@in.buggie.test'],
            'wrong-key',
        ))->assertForbidden();
    }

    #[Test]
    public function an_old_signature_cannot_be_replayed(): void
    {
        $timestamp = (string) (time() - 3600);
        $token = 'tok1';

        $this->postJson('/api/mail/inbound', [
            'recipient' => 'bugs+abc@in.buggie.test',
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => hash_hmac('sha256', $timestamp.$token, self::SIGNING_KEY),
        ])->assertForbidden();
    }

    #[Test]
    public function with_no_signing_key_configured_nothing_is_accepted(): void
    {
        // Failing open here would mean anyone could file issues in any workspace.
        config(['buggie.mailgun_signing_key' => null]);

        $this->postJson('/api/mail/inbound', $this->signed(['recipient' => 'bugs+abc@in.buggie.test']))
            ->assertForbidden();
    }

    #[Test]
    public function writing_to_a_projects_address_creates_an_issue(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create(['key' => 'WEB']));

        $this->postJson('/api/mail/inbound', $this->signed([
            'recipient' => "bugs+{$project->inbound_token}@in.buggie.test",
            'from' => 'Ana Silva <ana@shopper.test>',
            'subject' => 'Checkout is broken again',
            'stripped-text' => "Same as last week.\n\nOn Tue someone wrote:\n> old thread",
        ]))->assertOk()->assertJson(['issue' => 'WEB-1']);

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::firstOrFail());

        $this->assertSame('Checkout is broken again', $issue->title);
        $this->assertStringContainsString('Same as last week.', $issue->description_text);
        $this->assertStringNotContainsString('old thread', $issue->description_text);
        // From a stranger, so it stays internal until someone triages it.
        $this->assertSame(IssueVisibility::Internal, $issue->visibility);
    }

    #[Test]
    public function an_unknown_or_malformed_address_is_accepted_and_ignored(): void
    {
        // 200, not an error: Mailgun retries failures, and these never succeed.
        $this->postJson('/api/mail/inbound', $this->signed([
            'recipient' => 'bugs+nosuchproject@in.buggie.test',
            'from' => 'a@b.test',
            'stripped-text' => 'hello',
        ]))->assertOk();

        $this->postJson('/api/mail/inbound', $this->signed([
            'recipient' => 'hello@in.buggie.test',
            'stripped-text' => 'hello',
        ]))->assertOk();

        $this->assertDatabaseCount('issues', 0);
    }

    #[Test]
    public function an_empty_reply_creates_nothing(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');
        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create());

        $this->postJson('/api/mail/inbound', $this->signed([
            'recipient' => "bugs+{$project->inbound_token}@in.buggie.test",
            'from' => 'a@b.test',
            'stripped-text' => "\n> only quoted text\n--\nSignature",
        ]))->assertOk();

        $this->assertDatabaseCount('issues', 0);
    }

    #[Test]
    public function replying_to_a_notification_adds_a_comment_as_the_person_it_was_sent_to(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        [$project, $issue] = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create(['key' => 'WEB']);

            return [$project, Issue::factory()->clientVisible()->create(['project_id' => $project->id])];
        });

        $client = User::factory()->create(['email' => 'ana@shopper.test']);
        $workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $project->clients()->attach($client->id, ['role' => 'client_manager']);

        $this->postJson('/api/mail/inbound', $this->signed([
            'recipient' => ReplyAddress::for($issue, $client),
            // Whatever the header claims, the address decides who wrote it.
            'from' => "Somebody Else <{$owner->email}>",
            'stripped-text' => 'Still broken this morning.',
        ]))->assertOk()->assertJson(['issue' => $issue->key]);

        $comment = app(Tenancy::class)->run($workspace, fn () => Comment::firstOrFail());

        $this->assertSame('Still broken this morning.', trim($comment->body_text));
        $this->assertSame($client->id, $comment->user_id);
        $this->assertSame('email', $comment->source);
        // A client replying writes in public, never an internal note.
        $this->assertFalse($comment->is_internal);
    }

    #[Test]
    public function staff_replying_by_email_write_to_the_team(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $issue = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create(['key' => 'WEB']);

            return Issue::factory()->create(['project_id' => $project->id]);
        });

        $this->postJson('/api/mail/inbound', $this->signed([
            'recipient' => ReplyAddress::for($issue, $staff),
            'from' => "{$staff->name} <{$staff->email}>",
            'stripped-text' => 'Looks like the payment adapter again.',
        ]))->assertOk();

        $comment = app(Tenancy::class)->run($workspace, fn () => Comment::firstOrFail());

        // Matches what the in-app composer defaults to for staff.
        $this->assertTrue($comment->is_internal);
        $this->assertSame($staff->id, $comment->user_id);
    }

    #[Test]
    public function a_reply_address_reaches_only_its_own_issue_and_the_old_shared_ones_nothing(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        [$project, $mine, $other] = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create(['key' => 'WEB']);

            return [
                $project,
                Issue::factory()->create(['project_id' => $project->id]),
                Issue::factory()->create(['project_id' => $project->id]),
            ];
        });

        // A real address for one issue, edited to point at another.
        $forged = str_replace('i'.$mine->id.'.', 'i'.$other->id.'.', ReplyAddress::for($mine, $staff));

        foreach ([$forged, "reply+{$other->key}.{$project->inbound_token}@in.buggie.test"] as $address) {
            $this->postJson('/api/mail/inbound', $this->signed([
                'recipient' => $address,
                'from' => $staff->email,
                'stripped-text' => 'Trying it on.',
            ]))->assertOk()->assertJson(['message' => 'Unrecognised reply address; ignored.']);
        }

        $this->assertDatabaseCount('comments', 0);
    }

    #[Test]
    public function a_reply_is_refused_once_its_recipient_can_no_longer_see_the_issue(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        [$project, $issue] = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create(['key' => 'WEB']);

            return [$project, Issue::factory()->clientVisible()->create(['project_id' => $project->id])];
        });

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $project->clients()->attach($client->id, ['role' => 'client_manager']);
        $address = ReplyAddress::for($issue, $client);

        // Made internal after the digest went out.
        app(Tenancy::class)->run($workspace, fn () => $issue->forceFill(['visibility' => IssueVisibility::Internal])->save());

        $this->postJson('/api/mail/inbound', $this->signed([
            'recipient' => $address, 'from' => $client->email, 'stripped-text' => 'Hello?',
        ]))->assertOk()->assertJson(['message' => 'Unknown issue; ignored.']);

        $this->assertDatabaseCount('comments', 0);
    }

    #[Test]
    public function an_emailed_issue_is_never_attributed_from_the_sender_header(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');
        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create(['key' => 'WEB']));

        $this->postJson('/api/mail/inbound', $this->signed([
            'recipient' => "bugs+{$project->inbound_token}@in.buggie.test",
            'from' => "{$staff->name} <{$staff->email}>",
            'subject' => 'Pretending to be staff',
            'stripped-text' => 'Share this with every client.',
        ]))->assertOk();

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::firstOrFail());

        $this->assertSame(IssueVisibility::Internal, $issue->visibility);
        $this->assertNotSame($staff->id, $issue->reporter_id);
    }

    #[Test]
    public function a_projects_inbound_token_is_not_derived_from_anything_guessable(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        [$a, $b] = app(Tenancy::class)->run($workspace, fn () => [
            Project::factory()->create(['key' => 'WEB', 'slug' => 'web']),
            Project::factory()->create(['key' => 'APP', 'slug' => 'app']),
        ]);

        $this->assertNotNull($a->inbound_token);
        $this->assertNotSame($a->inbound_token, $b->inbound_token);
        $this->assertStringNotContainsString('web', $a->inbound_token);
        $this->assertSame(16, strlen($a->inbound_token));
    }
}
