<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Actions\MarkDuplicate;
use App\Enums\IssueEventType;
use App\Enums\ProjectRole;
use App\Enums\RelationType;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Closing an issue as a duplicate of another: it closes as not done, says why, and
 * everybody following it follows the original instead.
 */
class MarkDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $project;

    private User $ann;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        $this->project = $this->tenant(fn () => Project::factory()->create(['key' => 'KD']));

        $this->ann = User::factory()->create(['name' => 'Ann']);
        $this->workspace->members()->attach($this->ann->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->project->clients()->attach($this->ann->id, ['role' => ProjectRole::Client->value]);
    }

    #[Test]
    public function the_duplicate_closes_says_why_and_its_reporter_follows_the_original(): void
    {
        $original = $this->issue('Checkout fails on Safari', $this->owner, 'client');
        $duplicate = $this->issue('Cannot pay on my iPhone', $this->ann);

        // Ann reported the duplicate; the original is not hers yet.
        $this->actingAs($this->ann)->get($this->url($original))->assertNotFound();

        $this->actingAs($this->owner)
            ->post($this->url($duplicate, '/duplicate'), ['key' => strtolower($original->key)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $duplicate = $this->reload($duplicate);
        $this->assertSame($original->id, $duplicate->duplicate_of_id);
        $this->assertSame(StatusCategory::Canceled, $duplicate->status->category, 'Closed, not resolved.');
        $this->assertTrue($duplicate->relations()->where('related_issue_id', $original->id)->where('type', RelationType::Duplicates->value)->exists());

        $comment = $duplicate->comments()->latest('id')->first();
        $this->assertStringContainsString("duplicate of {$original->key}", $comment->body_text);
        $this->assertFalse($comment->is_internal, 'Ann should be told why her issue closed.');

        $this->assertTrue($this->reload($original)->watchers()->whereKey($this->ann->id)->exists());

        // And the "own issues" rule now lets her follow the original.
        $this->actingAs($this->ann)->get($this->url($original))->assertOk();

        $this->actingAs($this->ann)->get($this->url($duplicate))
            ->assertInertia(fn ($page) => $page
                ->where('issue.duplicate_of.key', $original->key)
                ->where('events', fn ($events) => collect($events)->contains('type', IssueEventType::MarkedDuplicate->value)));
    }

    #[Test]
    public function a_duplicate_of_a_duplicate_points_at_the_original(): void
    {
        $first = $this->issue('First', $this->owner);
        $second = $this->issue('Second', $this->owner);
        $third = $this->issue('Third', $this->owner);

        $this->mark($second, $first);
        $this->mark($third, $second);

        $this->assertSame($first->id, $this->reload($third)->duplicate_of_id);
    }

    #[Test]
    public function an_issue_cannot_be_its_own_duplicate_even_the_long_way_round(): void
    {
        $first = $this->issue('First', $this->owner);
        $second = $this->issue('Second', $this->owner);
        $this->mark($second, $first);

        $this->actingAs($this->owner)->post($this->url($first, '/duplicate'), ['key' => $second->key])
            ->assertSessionHasErrors('key');
        $this->actingAs($this->owner)->post($this->url($first, '/duplicate'), ['key' => $first->key])
            ->assertSessionHasErrors('key');

        $this->assertNull($this->reload($first)->duplicate_of_id);
    }

    #[Test]
    public function a_client_cannot_and_another_workspaces_key_is_not_found(): void
    {
        $mine = $this->issue('Mine', $this->ann);
        $other = $this->issue('Other', $this->owner, 'client');

        $this->actingAs($this->ann)->post($this->url($mine, '/duplicate'), ['key' => $other->key])->assertForbidden();

        [$globex, $globexOwner] = $this->workspaceWithMember(slug: 'globex');
        $foreign = app(Tenancy::class)->run($globex, fn () => app(CreateIssue::class)->handle(
            Project::factory()->create(['key' => 'GLX']), ['title' => 'Foreign'], $globexOwner,
        ));

        $this->actingAs($this->owner)->post($this->url($mine, '/duplicate'), ['key' => $foreign->key])
            ->assertSessionHasErrors('key');
        $this->assertNull($this->reload($mine)->duplicate_of_id);
    }

    #[Test]
    public function a_client_is_not_told_the_key_of_an_internal_original(): void
    {
        $internal = $this->issue('Internal root cause', $this->owner, 'internal');
        $duplicate = $this->issue('Page is blank', $this->ann);

        $this->mark($duplicate, $internal);

        $props = $this->actingAs($this->ann)->get($this->url($duplicate))->viewData('page')['props'];
        $raw = json_encode(collect($props)->except('ziggy')->all());

        $this->assertNull($props['issue']['duplicate_of']);
        $this->assertStringNotContainsString($internal->key, $raw, 'The internal original was named to a client.');
        $this->assertStringNotContainsString('Internal root cause', $raw);
        $this->assertStringContainsString('an issue the team is already working on', $raw);
    }

    private function issue(string $title, User $reporter, ?string $visibility = null): Issue
    {
        return $this->tenant(fn () => app(CreateIssue::class)->handle(
            $this->project,
            ['title' => $title, ...($visibility ? ['visibility' => $visibility] : [])],
            $reporter,
        ));
    }

    private function mark(Issue $duplicate, Issue $original): void
    {
        $this->tenant(fn () => app(MarkDuplicate::class)->handle($this->reload($duplicate), $this->reload($original), $this->owner));
    }

    private function reload(Issue $issue): Issue
    {
        return $this->tenant(fn () => Issue::with('status')->findOrFail($issue->id));
    }

    private function url(Issue $issue, string $suffix = ''): string
    {
        return $this->workspaceUrl($this->workspace, "/issues/{$issue->key}{$suffix}");
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
