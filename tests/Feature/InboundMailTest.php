<?php

namespace Tests\Feature;

use App\Enums\IssueVisibility;
use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
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
        config(['buggy.mailgun_signing_key' => self::SIGNING_KEY]);
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
        $this->postJson('/api/mail/inbound', ['recipient' => 'bugs+abc@in.buggy.test'])
            ->assertForbidden();

        $this->postJson('/api/mail/inbound', $this->signed(
            ['recipient' => 'bugs+abc@in.buggy.test'],
            'wrong-key',
        ))->assertForbidden();
    }

    #[Test]
    public function an_old_signature_cannot_be_replayed(): void
    {
        $timestamp = (string) (time() - 3600);
        $token = 'tok1';

        $this->postJson('/api/mail/inbound', [
            'recipient' => 'bugs+abc@in.buggy.test',
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => hash_hmac('sha256', $timestamp.$token, self::SIGNING_KEY),
        ])->assertForbidden();
    }

    #[Test]
    public function with_no_signing_key_configured_nothing_is_accepted(): void
    {
        // Failing open here would mean anyone could file issues in any workspace.
        config(['buggy.mailgun_signing_key' => null]);

        $this->postJson('/api/mail/inbound', $this->signed(['recipient' => 'bugs+abc@in.buggy.test']))
            ->assertForbidden();
    }

    #[Test]
    public function writing_to_a_projects_address_creates_an_issue(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create(['key' => 'WEB']));

        $this->postJson('/api/mail/inbound', $this->signed([
            'recipient' => "bugs+{$project->inbound_token}@in.buggy.test",
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
            'recipient' => 'bugs+nosuchproject@in.buggy.test',
            'from' => 'a@b.test',
            'stripped-text' => 'hello',
        ]))->assertOk();

        $this->postJson('/api/mail/inbound', $this->signed([
            'recipient' => 'hello@in.buggy.test',
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
            'recipient' => "bugs+{$project->inbound_token}@in.buggy.test",
            'from' => 'a@b.test',
            'stripped-text' => "\n> only quoted text\n--\nSignature",
        ]))->assertOk();

        $this->assertDatabaseCount('issues', 0);
    }

    #[Test]
    public function replying_to_a_notification_adds_a_comment(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        [$project, $issue] = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create(['key' => 'WEB']);

            return [$project, Issue::factory()->create(['project_id' => $project->id])];
        });

        $this->postJson('/api/mail/inbound', $this->signed([
            'recipient' => "reply+{$issue->key}.{$project->inbound_token}@in.buggy.test",
            'from' => 'Ana Silva <ana@shopper.test>',
            'stripped-text' => 'Still broken this morning.',
        ]))->assertOk()->assertJson(['issue' => $issue->key]);

        $comment = app(Tenancy::class)->run($workspace, fn () => Comment::firstOrFail());

        $this->assertSame('Still broken this morning.', trim($comment->body_text));
        $this->assertSame('ana@shopper.test', $comment->author_email);
        $this->assertSame('Ana Silva', $comment->author_name);
        $this->assertSame('email', $comment->source);
        // A stranger replying writes in public, never an internal note.
        $this->assertFalse($comment->is_internal);
    }

    #[Test]
    public function staff_replying_by_email_write_to_the_team(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        [$project, $issue] = app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create(['key' => 'WEB']);

            return [$project, Issue::factory()->create(['project_id' => $project->id])];
        });

        $this->postJson('/api/mail/inbound', $this->signed([
            'recipient' => "reply+{$issue->key}.{$project->inbound_token}@in.buggy.test",
            'from' => "{$staff->name} <{$staff->email}>",
            'stripped-text' => 'Looks like the payment adapter again.',
        ]))->assertOk();

        $comment = app(Tenancy::class)->run($workspace, fn () => Comment::firstOrFail());

        // Matches what the in-app composer defaults to for staff.
        $this->assertTrue($comment->is_internal);
        $this->assertSame($staff->id, $comment->user_id);
    }

    #[Test]
    public function a_reply_token_cannot_reach_an_issue_in_another_project(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        [$other, $issue] = app(Tenancy::class)->run($workspace, function () {
            $mine = Project::factory()->create(['key' => 'WEB']);
            $other = Project::factory()->create(['key' => 'APP']);

            return [$other, Issue::factory()->create(['project_id' => $mine->id])];
        });

        // Valid issue key, but paired with a different project's token.
        $this->postJson('/api/mail/inbound', $this->signed([
            'recipient' => "reply+{$issue->key}.{$other->inbound_token}@in.buggy.test",
            'from' => 'ana@shopper.test',
            'stripped-text' => 'Trying it on.',
        ]))->assertOk()->assertJson(['message' => 'Unknown issue; ignored.']);

        $this->assertDatabaseCount('comments', 0);
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
