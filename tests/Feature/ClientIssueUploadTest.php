<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientIssueUploadTest extends TestCase
{
    use RefreshDatabase;

    private function client(): array
    {
        [$workspace] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');
        $client = User::factory()->create();
        $workspace->members()->attach($client->id, ['role' => WorkspaceRole::Client->value, 'joined_at' => now()]);
        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create());
        $project->clients()->attach($client->id, ['role' => 'client']);

        return [$workspace, $client, $project];
    }

    private function payload(Project $project): string
    {
        return json_encode([
            'project_id' => $project->id, 'title' => 'Checkout broken', 'type' => 'bug', 'priority' => 0,
            'description' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['x' => 1], 'content' => [['type' => 'text', 'text' => 'It fails']]]]],
            'visibility' => 'client', 'labels' => [], 'custom_fields' => [],
        ]);
    }

    #[Test]
    public function a_client_can_file_an_issue_with_a_screenshot(): void
    {
        Storage::fake('local');
        [$workspace, $client, $project] = $this->client();

        $this->actingAs($client)->post($this->workspaceUrl($workspace, '/issues'), [
            'payload' => $this->payload($project),
            'files' => [UploadedFile::fake()->image('shot.png'), UploadedFile::fake()->create('notes.txt', 1, 'text/plain')],
        ])->assertRedirect();

        app(Tenancy::class)->run($workspace, function () use ($client) {
            $issue = Issue::firstOrFail();
            $this->assertSame('Checkout broken', $issue->title);
            $this->assertSame('It fails', $issue->description['content'][0]['content'][0]['text']);
            $this->assertSame(2, Attachment::where('attachable_id', $issue->id)->count());
            $this->assertSame($client->id, Attachment::first()->uploaded_by_id);
        });
    }

    #[Test]
    public function a_disallowed_file_stops_the_issue_being_created(): void
    {
        Storage::fake('local');
        [$workspace, $client, $project] = $this->client();

        $this->actingAs($client)->post($this->workspaceUrl($workspace, '/issues'), [
            'payload' => $this->payload($project),
            'files' => [UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')],
        ])->assertSessionHasErrors('files.0');

        $this->assertSame(0, app(Tenancy::class)->run($workspace, fn () => Issue::count()));
    }
}
