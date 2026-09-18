<?php

namespace Tests\Feature;

use App\Enums\IssueEventType;
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

/**
 * Attachments hold screenshots and logs from customers' production systems, so the
 * interesting tests are about who can read them and what can be uploaded at all.
 */
class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @return array{0: \App\Models\Workspace, 1: User, 2: Issue} */
    private function scenario(string $slug = 'acme'): array
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, $slug);

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => Project::factory()->create(['key' => 'WEB'])->id,
        ]));

        return [$workspace, $staff, $issue];
    }

    #[Test]
    public function an_image_can_be_attached_to_an_issue(): void
    {
        [$workspace, $staff, $issue] = $this->scenario();

        $this->actingAs($staff)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/attachments"), [
                'file' => UploadedFile::fake()->image('console.png', 800, 600),
            ])
            ->assertRedirect();

        $attachment = Attachment::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('console.png', $attachment->filename);
        $this->assertSame(800, $attachment->width);
        $this->assertSame($workspace->id, $attachment->workspace_id);
        Storage::disk('local')->assertExists($attachment->path);

        // It shows up in the activity feed, like every other change to an issue.
        $this->assertTrue(
            app(Tenancy::class)->run($workspace, fn () => $issue->events()
                ->where('type', IssueEventType::AttachmentAdded->value)->exists()),
        );
    }

    #[Test]
    public function executables_and_svgs_are_refused(): void
    {
        [$workspace, $staff, $issue] = $this->scenario();

        $url = $this->workspaceUrl($workspace, "/issues/{$issue->key}/attachments");

        // SVG is an XML document that can carry script, and we serve attachments from
        // our own origin.
        $this->actingAs($staff)->post($url, [
            'file' => UploadedFile::fake()->createWithContent(
                'evil.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            ),
        ])->assertSessionHasErrors('file');

        $this->actingAs($staff)->post($url, [
            'file' => UploadedFile::fake()->createWithContent('run.sh', '#!/bin/sh\nrm -rf /'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Attachment::withoutGlobalScopes()->count());
    }

    #[Test]
    public function oversized_files_are_refused(): void
    {
        [$workspace, $staff, $issue] = $this->scenario();

        $this->actingAs($staff)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/attachments"), [
                'file' => UploadedFile::fake()->create('huge.pdf', 20_000, 'application/pdf'),
            ])
            ->assertSessionHasErrors('file');
    }

    #[Test]
    public function a_crafted_filename_cannot_escape_the_directory(): void
    {
        [$workspace, $staff, $issue] = $this->scenario();

        $this->actingAs($staff)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/attachments"), [
                'file' => UploadedFile::fake()->image('../../../../etc/passwd.png'),
            ])
            ->assertRedirect();

        $attachment = Attachment::withoutGlobalScopes()->firstOrFail();

        // The original name is kept only as a label, and stripped of anything that
        // reads as a path; the stored path is generated.
        $this->assertStringNotContainsString('..', $attachment->filename);
        $this->assertStringNotContainsString('/', $attachment->filename);
        $this->assertStringStartsWith("workspaces/{$workspace->id}/attachments/", $attachment->path);
    }

    #[Test]
    public function downloading_goes_through_the_issues_own_visibility_check(): void
    {
        [$workspace, $staff, $issue] = $this->scenario();

        $this->actingAs($staff)->post(
            $this->workspaceUrl($workspace, "/issues/{$issue->key}/attachments"),
            ['file' => UploadedFile::fake()->image('secret.png')],
        );

        $attachment = Attachment::withoutGlobalScopes()->firstOrFail();
        $url = $this->workspaceUrl($workspace, "/attachments/{$attachment->id}");

        $this->actingAs($staff)->get($url)->assertOk();

        // The issue is internal, so a client on the same workspace cannot read it.
        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        $this->actingAs($client)->get($url)->assertForbidden();

        // And a guest gets nothing at all. actingAs persists for the rest of the
        // test, so the guard has to be cleared to make a genuinely anonymous request.
        $this->app['auth']->forgetGuards();

        $this->get($url)->assertRedirect();
    }

    #[Test]
    public function an_attachment_from_another_workspace_is_unreachable(): void
    {
        [$acme, $acmeUser, $acmeIssue] = $this->scenario('acme');
        [$globex, $globexUser, $globexIssue] = $this->scenario('globex');

        $this->actingAs($globexUser)->post(
            $this->workspaceUrl($globex, "/issues/{$globexIssue->key}/attachments"),
            ['file' => UploadedFile::fake()->image('theirs.png')],
        );

        $theirs = Attachment::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($acmeUser)
            ->get($this->workspaceUrl($acme, "/attachments/{$theirs->id}"))
            ->assertNotFound();

        unset($acmeIssue);
    }

    #[Test]
    public function responses_forbid_content_sniffing(): void
    {
        [$workspace, $staff, $issue] = $this->scenario();

        $this->actingAs($staff)->post(
            $this->workspaceUrl($workspace, "/issues/{$issue->key}/attachments"),
            ['file' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain')],
        );

        $attachment = Attachment::withoutGlobalScopes()->firstOrFail();

        $response = $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, "/attachments/{$attachment->id}"))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // Anything that is not an image downloads rather than rendering, and carries
        // the strict policy for anyone navigating straight to it.
        $this->assertStringStartsWith(
            'attachment;',
            $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString(
            'sandbox',
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    #[Test]
    public function images_render_inline_and_are_not_sandboxed(): void
    {
        [$workspace, $staff, $issue] = $this->scenario();

        $this->actingAs($staff)->post(
            $this->workspaceUrl($workspace, "/issues/{$issue->key}/attachments"),
            ['file' => UploadedFile::fake()->image('screenshot.png')],
        );

        $attachment = Attachment::withoutGlobalScopes()->firstOrFail();

        $response = $this->actingAs($staff)
            ->get($this->workspaceUrl($workspace, "/attachments/{$attachment->id}"))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringStartsWith(
            'inline;',
            $response->headers->get('Content-Disposition'),
        );

        // A CSP with `sandbox` puts the response in an opaque origin and the thumbnail
        // silently fails to render. Images are a raster allowlist, so they do not need
        // it; the strict policy stays on everything that downloads.
        $this->assertStringNotContainsString(
            'sandbox',
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    #[Test]
    public function the_editor_gets_the_url_back_when_uploading_in_the_background(): void
    {
        [$workspace, $staff, $issue] = $this->scenario();

        $this->actingAs($staff)
            ->postJson($this->workspaceUrl($workspace, "/issues/{$issue->key}/attachments"), [
                'file' => UploadedFile::fake()->image('pasted.png'),
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'filename', 'url', 'is_image'])
            ->assertJson(['is_image' => true]);
    }

    #[Test]
    public function deleting_removes_the_stored_file_too(): void
    {
        [$workspace, $staff, $issue] = $this->scenario();

        $this->actingAs($staff)->post(
            $this->workspaceUrl($workspace, "/issues/{$issue->key}/attachments"),
            ['file' => UploadedFile::fake()->image('gone.png')],
        );

        $attachment = Attachment::withoutGlobalScopes()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);

        $this->actingAs($staff)
            ->delete($this->workspaceUrl($workspace, "/attachments/{$attachment->id}"))
            ->assertRedirect();

        Storage::disk('local')->assertMissing($attachment->path);
        $this->assertSame(0, Attachment::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_client_can_attach_to_an_issue_they_can_see(): void
    {
        [$workspace, , $issue] = $this->scenario();

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value, 'joined_at' => now(),
        ]);

        app(Tenancy::class)->run($workspace, function () use ($issue, $client) {
            $issue->forceFill(['visibility' => 'client'])->save();
            $issue->project->clients()->attach($client->id, ['role' => 'client']);
        });

        // Sending a screenshot is half of what a client wants to do.
        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, "/issues/{$issue->key}/attachments"), [
                'file' => UploadedFile::fake()->image('what-i-see.png'),
            ])
            ->assertRedirect();

        $this->assertSame(1, Attachment::withoutGlobalScopes()->count());
    }
}
