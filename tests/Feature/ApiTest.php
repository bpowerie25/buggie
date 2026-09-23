<?php

namespace Tests\Feature;

use App\Enums\IssueVisibility;
use App\Enums\WorkspaceRole;
use App\Models\ApiToken;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, string> $abilities */
    private function tokenFor(User $user, Workspace $workspace, array $abilities = ['read', 'write']): string
    {
        return $user->createTokenForWorkspace($workspace, 'test', $abilities)->plainTextToken;
    }

    private function apiUrl(Workspace $workspace, string $path): string
    {
        return 'http://'.$workspace->slug.'.'.config('buggie.host').'/api/v1/'.ltrim($path, '/');
    }

    /** @param array<string, mixed> $data */
    private function api(string $token, string $method, string $url, array $data = []): \Illuminate\Testing\TestResponse
    {
        // Laravel keeps the resolved user on the guard for the life of the
        // application, and a test makes several requests against one. Without this,
        // a token revoked mid-test still appears to work.
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->json($method, $url, $data);
    }

    #[Test]
    public function a_token_lists_issues(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        app(Tenancy::class)->run($workspace, fn () => Issue::factory()->create([
            'project_id' => Project::factory()->create(['key' => 'WEB'])->id,
            'title' => 'Pay now does nothing',
        ]));

        $this->api($this->tokenFor($staff, $workspace), 'GET', $this->apiUrl($workspace, 'issues'))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Pay now does nothing')
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function no_token_is_a_401(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $this->withHeaders(['Accept' => 'application/json'])
            ->json('GET', $this->apiUrl($workspace, 'issues'))
            ->assertUnauthorized();
    }

    #[Test]
    public function a_token_does_not_work_in_another_workspace(): void
    {
        // The reason tokens carry a workspace at all. Somebody who works in their own
        // workspace and three client ones should not have a single key to all four.
        [$acme, $user] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');
        [$globex] = $this->workspaceWithMember(WorkspaceRole::Member, 'globex');

        $globex->members()->attach($user->id, [
            'role' => WorkspaceRole::Member->value,
            'joined_at' => now(),
        ]);

        // A perfectly valid token, for the wrong workspace. 404, not 403: it should
        // not learn that globex exists either.
        $this->api($this->tokenFor($user, $acme), 'GET', $this->apiUrl($globex, 'issues'))
            ->assertNotFound();
    }

    #[Test]
    public function a_read_token_cannot_write(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create(['key' => 'WEB']));

        $this->api(
            $this->tokenFor($staff, $workspace, ['read']),
            'POST',
            $this->apiUrl($workspace, 'issues'),
            ['project_id' => $project->id, 'title' => 'Filed by a script'],
        )->assertForbidden();
    }

    #[Test]
    public function a_write_token_files_an_issue(): void
    {
        // The control for the test above: the refusal must be about the ability, not
        // about the endpoint being broken.
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $project = app(Tenancy::class)->run($workspace, fn () => Project::factory()->create(['key' => 'WEB']));

        $this->api(
            $this->tokenFor($staff, $workspace),
            'POST',
            $this->apiUrl($workspace, 'issues'),
            ['project_id' => $project->id, 'title' => 'Filed by a script'],
        )
            ->assertCreated()
            ->assertJsonPath('data.title', 'Filed by a script');
    }

    #[Test]
    public function the_api_obeys_the_query_language(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        app(Tenancy::class)->run($workspace, function () {
            $web = Project::factory()->create(['key' => 'WEB', 'slug' => 'web']);
            $app = Project::factory()->create(['key' => 'APP', 'slug' => 'app']);

            Issue::factory()->create(['project_id' => $web->id, 'title' => 'On the website']);
            Issue::factory()->create(['project_id' => $app->id, 'title' => 'In the app']);
        });

        $this->api($this->tokenFor($staff, $workspace), 'GET', $this->apiUrl($workspace, 'issues?q=project:web'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'On the website');
    }

    #[Test]
    public function a_clients_token_sees_only_what_they_could_see_in_the_app(): void
    {
        // The API reuses the same scopes and policies precisely so this holds without
        // being implemented a second time — but "precisely so" is not evidence.
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);

        $granted = app(Tenancy::class)->run($workspace, function () {
            $granted = Project::factory()->create(['key' => 'MINE', 'name' => 'Their site']);
            $other = Project::factory()->create(['key' => 'OTHER', 'name' => 'Another client']);

            Issue::factory()->clientVisible()->create(['project_id' => $granted->id, 'title' => 'Theirs']);
            Issue::factory()->create([
                'project_id' => $granted->id,
                'title' => 'Internal only',
                'visibility' => IssueVisibility::Internal,
            ]);
            Issue::factory()->clientVisible()->create(['project_id' => $other->id, 'title' => 'Somebody elses']);

            return $granted;
        });

        $granted->clients()->attach($client->id, ['role' => 'client_manager']);

        $token = $this->tokenFor($client, $workspace, ['read']);

        $issues = $this->api($token, 'GET', $this->apiUrl($workspace, 'issues'))->assertOk();

        $issues->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Theirs');

        // And the project list does not name the agency's other clients.
        $projects = $this->api($token, 'GET', $this->apiUrl($workspace, 'projects'))->assertOk();

        $projects->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Their site');
    }

    #[Test]
    public function a_revoked_token_stops_working(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $token = $this->tokenFor($staff, $workspace);

        $this->api($token, 'GET', $this->apiUrl($workspace, 'issues'))->assertOk();

        ApiToken::query()->delete();

        $this->api($token, 'GET', $this->apiUrl($workspace, 'issues'))->assertUnauthorized();
    }

    #[Test]
    public function an_expired_token_stops_working(): void
    {
        [$workspace, $staff] = $this->workspaceWithMember(WorkspaceRole::Member, 'acme');

        $plain = $this->tokenFor($staff, $workspace);
        ApiToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->api($plain, 'GET', $this->apiUrl($workspace, 'issues'))->assertUnauthorized();
    }

    #[Test]
    public function a_token_stops_working_when_its_owner_is_removed(): void
    {
        // Revoking someone's access should not leave a key of theirs in the door.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $member = User::factory()->create();
        $workspace->members()->attach($member->id, [
            'role' => WorkspaceRole::Member->value,
            'joined_at' => now(),
        ]);

        $token = $this->tokenFor($member, $workspace);
        $this->api($token, 'GET', $this->apiUrl($workspace, 'issues'))->assertOk();

        $workspace->members()->detach($member->id);

        $this->api($token, 'GET', $this->apiUrl($workspace, 'issues'))->assertNotFound();
    }

    #[Test]
    public function a_token_can_be_created_and_revoked_from_settings(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->post($this->workspaceUrl($workspace, '/settings/tokens'), [
                'name' => 'Deploy script',
                'abilities' => ['read'],
            ])
            ->assertRedirect()
            ->assertSessionHas('token');

        $token = ApiToken::firstOrFail();

        $this->assertSame($workspace->id, $token->workspace_id);
        $this->assertSame(['read'], $token->abilities);

        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/settings/tokens/{$token->id}"))
            ->assertRedirect();

        $this->assertSame(0, ApiToken::count());
    }

    #[Test]
    public function a_client_cannot_mint_a_token(): void
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $client = User::factory()->create();
        $workspace->members()->attach($client->id, [
            'role' => WorkspaceRole::Client->value,
            'joined_at' => now(),
        ]);

        $this->actingAs($client)
            ->post($this->workspaceUrl($workspace, '/settings/tokens'), [
                'name' => 'Sneaky',
                'abilities' => ['write'],
            ])
            ->assertForbidden();
    }

    #[Test]
    public function nobody_can_revoke_somebody_elses_token(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $colleague = User::factory()->create();
        $workspace->members()->attach($colleague->id, [
            'role' => WorkspaceRole::Member->value,
            'joined_at' => now(),
        ]);

        $theirs = $colleague->createTokenForWorkspace($workspace, 'Theirs')->accessToken;

        // 404, not 403: the owner has no business learning it exists either.
        $this->actingAs($owner)
            ->delete($this->workspaceUrl($workspace, "/settings/tokens/{$theirs->id}"))
            ->assertNotFound();

        $this->assertSame(1, ApiToken::count());
    }

    #[Test]
    public function the_plain_token_is_never_stored(): void
    {
        // It is shown once, on the response that creates it. A token you can read
        // back later is a password written on the wall.
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $plain = $owner->createTokenForWorkspace($workspace, 'Once')->plainTextToken;

        [, $secret] = explode('|', $plain, 2);

        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $secret]);
        $this->assertDatabaseHas('personal_access_tokens', ['token' => hash('sha256', $secret)]);
    }
}
