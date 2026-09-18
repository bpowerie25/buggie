<?php

namespace Tests;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rendering a page should not depend on `npm run build` having been run.
        $this->withoutVite();
    }

    /**
     * A workspace with one member in the given role.
     *
     * @return array{0: Workspace, 1: User}
     */
    protected function workspaceWithMember(
        WorkspaceRole $role = WorkspaceRole::Owner,
        ?string $slug = null,
    ): array {
        $user = User::factory()->create();

        $workspace = Workspace::factory()->create([
            'owner_id' => $user->id,
            ...($slug ? ['slug' => $slug] : []),
        ]);

        $workspace->members()->attach($user->id, [
            'role' => $role->value,
            'joined_at' => now(),
        ]);

        return [$workspace, $user];
    }

    /** An absolute URL on a workspace's subdomain, as the browser would request it. */
    protected function workspaceUrl(Workspace $workspace, string $path = '/'): string
    {
        return 'http://'.$workspace->slug.'.'.config('buggy.host').'/'.ltrim($path, '/');
    }

    protected function centralUrl(string $path = '/'): string
    {
        return 'http://'.config('buggy.host').'/'.ltrim($path, '/');
    }
}
