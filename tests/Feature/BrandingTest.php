<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\PortalToken;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\Workspace, 1: User, 2: Project} */
    private function project(): array
    {
        [$workspace, $staff] = $this->workspaceWithMember(slug: 'acme');

        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create([
            'key' => 'WEB',
            'name' => 'Northwind Site',
        ]));

        return [$workspace, $staff, $project];
    }

    #[Test]
    public function a_project_falls_back_to_its_own_name(): void
    {
        // An unbranded project should look deliberate rather than half-configured.
        [, , $project] = $this->project();

        $this->assertSame('Northwind Site', $project->branding()['name']);
        $this->assertNull($project->branding()['logo']);
    }

    #[Test]
    public function branding_is_saved_and_shown_to_a_reporter(): void
    {
        Storage::fake('local');

        [$workspace, $staff, $project] = $this->project();

        $this->actingAs($staff)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/branding"), [
                'brand_name' => 'Northwind',
                'brand_color' => '#0b7285',
                'logo' => UploadedFile::fake()->image('logo.png', 200, 60),
            ])
            ->assertRedirect();

        $issue = app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => $project->id,
        ]));

        $token = app(Tenancy::class)->run($workspace, fn () => PortalToken::create([
            'issue_id' => $issue->id,
            'email' => 'ana@shopper.test',
            'expires_at' => now()->addDays(30),
        ]));

        // The reporter was using a shop, not a bug tracker.
        $this->get($this->centralUrl("/portal/{$token->token}"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('brand.name', 'Northwind')
                ->where('brand.color', '#0b7285')
                ->whereNot('brand.logo', null));
    }

    #[Test]
    public function the_colour_must_be_a_colour(): void
    {
        // The value ends up inside a style attribute, and "anything goes" there is
        // how a stylesheet becomes a script tag.
        [$workspace, $staff, $project] = $this->project();

        foreach (['red', 'javascript:alert(1)', '#fff', '</style><script>'] as $attempt) {
            $this->actingAs($staff)
                ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/branding"), [
                    'brand_color' => $attempt,
                ])
                ->assertSessionHasErrors('brand_color');
        }

        $this->assertNull($project->fresh()->brand_color);
    }

    #[Test]
    public function the_logo_is_served_without_being_treated_as_a_document(): void
    {
        // An uploaded SVG is a document that can carry script. It is shown on a page
        // reachable by anybody holding a token, so it is never rendered as one.
        Storage::fake('local');

        [$workspace, $staff, $project] = $this->project();

        $this->actingAs($staff)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/branding"), [
                'logo' => UploadedFile::fake()->image('logo.png'),
            ]);

        $response = $this->get($this->centralUrl("/brand/{$project->id}/logo"))->assertOk();

        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('content-security-policy'));
    }

    #[Test]
    public function a_project_with_no_logo_is_a_404(): void
    {
        [, , $project] = $this->project();

        $this->get($this->centralUrl("/brand/{$project->id}/logo"))->assertNotFound();
    }

    #[Test]
    public function replacing_a_logo_deletes_the_old_one(): void
    {
        // Nobody needs the logo a client used two rebrands ago.
        Storage::fake('local');

        [$workspace, $staff, $project] = $this->project();

        $this->actingAs($staff)->post(
            $this->workspaceUrl($workspace, "/projects/{$project->slug}/branding"),
            ['logo' => UploadedFile::fake()->image('first.png')],
        );

        $first = $project->fresh()->brand_logo_path;

        $this->actingAs($staff)->post(
            $this->workspaceUrl($workspace, "/projects/{$project->slug}/branding"),
            ['logo' => UploadedFile::fake()->image('second.png')],
        );

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($project->fresh()->brand_logo_path);
    }

    #[Test]
    public function the_widget_is_told_how_to_look(): void
    {
        // The reporter is looking at their own application, so the panel should look
        // like it belongs to it.
        [$workspace, $staff, $project] = $this->project();

        $key = app(Tenancy::class)->run($workspace, fn () => \App\Models\WidgetKey::create([
            'project_id' => $project->id,
            'mode' => 'identified',
        ]));

        $project->forceFill(['brand_name' => 'Northwind', 'brand_color' => '#0b7285'])->save();

        $this->getJson($this->centralUrl("/api/ingest/{$key->public_key}/config"))
            ->assertOk()
            ->assertJsonPath('brand.name', 'Northwind')
            ->assertJsonPath('brand.color', '#0b7285');
    }

    #[Test]
    public function a_client_cannot_rebrand_a_project(): void
    {
        [$workspace, , $project] = $this->project();

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => \App\Enums\WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);
        $project->clients()->attach($client->id, ['role' => 'client']);

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, "/projects/{$project->slug}/branding"), [
                'brand_name' => 'Mine now',
            ])
            ->assertForbidden();
    }
}
