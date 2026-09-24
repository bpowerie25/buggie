<?php

namespace Tests\Feature;

use App\Actions\CreateIssue;
use App\Enums\ProjectRole;
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
 * The issue picker's suggestions: found by a few words of the title rather than a
 * key typed from memory, and never anybody else's.
 */
class IssueLookupTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Project $web;

    private Project $shop;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->workspace, $this->owner] = $this->workspaceWithMember(slug: 'matrix');
        [$this->web, $this->shop] = $this->tenant(fn () => [
            Project::factory()->create(['name' => 'Website', 'key' => 'WEB', 'slug' => 'web']),
            Project::factory()->create(['name' => 'Shop', 'key' => 'SHOP', 'slug' => 'shop']),
        ]);
    }

    #[Test]
    public function before_typing_it_suggests_open_work_from_the_same_project_first(): void
    {
        $this->issue($this->shop, 'Basket total wrong');
        $closed = $this->issue($this->web, 'Old footer bug', done: true);
        $open = $this->issue($this->web, 'Menu overlaps logo');
        $self = $this->issue($this->web, 'The issue doing the linking');

        $keys = $this->lookup(['project' => 'web', 'exclude' => strtolower($self->key)]);

        $this->assertSame([$open->key, 'SHOP-1', $closed->key], $keys);
    }

    #[Test]
    public function it_finds_issues_by_partial_words_in_any_order_or_by_the_start_of_a_key(): void
    {
        $checkout = $this->issue($this->shop, 'Checkout button does nothing on Safari');
        $this->issue($this->shop, 'Safari shows the wrong font');
        $tenth = collect(range(1, 10))->map(fn ($i) => $this->issue($this->web, "Page {$i}"))->last();

        $this->assertSame([$checkout->key], $this->lookup(['q' => 'safari chec']));
        $this->assertSame([$tenth->key], $this->lookup(['q' => 'web-10']));
        $this->assertCount(2, $this->lookup(['q' => 'WEB-1']), 'WEB-1 and WEB-10 both start with it.');
        $this->assertSame([], $this->lookup(['q' => '100%']), 'A % is a character to find, not a wildcard.');
    }

    #[Test]
    public function a_client_gets_nothing_and_another_workspace_is_never_searched(): void
    {
        $this->issue($this->web, 'Homepage hero image', visibility: 'client');

        $client = User::factory()->create();
        $this->workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $this->web->clients()->attach($client->id, ['role' => ProjectRole::ClientManager->value]);

        $this->actingAs($client)->getJson($this->workspaceUrl($this->workspace, '/issues-lookup?q=home'))->assertNotFound();

        [$globex] = $this->workspaceWithMember(slug: 'globex');
        app(Tenancy::class)->run($globex, fn () => app(CreateIssue::class)->handle(
            Project::factory()->create(['key' => 'GLX']), ['title' => 'Homepage hero at Globex'], $this->owner,
        ));

        $this->assertSame(['WEB-1'], $this->lookup(['q' => 'homepage']));
    }

    /** @return array<int, string> */
    private function lookup(array $params): array
    {
        return collect($this->actingAs($this->owner)
            ->getJson($this->workspaceUrl($this->workspace, '/issues-lookup?'.http_build_query($params)))
            ->assertOk()
            ->json('issues'))
            ->pluck('key')
            ->all();
    }

    private function issue(Project $project, string $title, bool $done = false, string $visibility = 'internal'): Issue
    {
        $issue = $this->tenant(fn () => app(CreateIssue::class)->handle($project, ['title' => $title, 'visibility' => $visibility], $this->owner));

        if ($done) {
            $issue->forceFill([
                'status_id' => $this->tenant(fn () => $project->statuses()->where('category', StatusCategory::Done->value)->firstOrFail()->id),
            ])->save();
        }

        // Created within the same instant; spaced so "most recent" means something.
        $issue->forceFill(['updated_at' => now()->addSeconds($issue->id)])->saveQuietly();

        return $issue;
    }

    private function tenant(\Closure $callback): mixed
    {
        return app(Tenancy::class)->run($this->workspace, $callback);
    }
}
