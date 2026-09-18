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

        $this->assertRunningAgainstTheTestDatabase();

        // Rendering a page should not depend on `npm run build` having been run.
        $this->withoutVite();
    }

    /**
     * RefreshDatabase truncates whatever it is pointed at, so being pointed at the
     * wrong database is silently destructive rather than merely wrong.
     *
     * This has happened once already: container environment variables land in
     * $_SERVER, which Laravel reads before $_ENV, so they quietly beat phpunit.xml's
     * <env force="true"> and the suite ran against development data. Assert rather
     * than trust the configuration.
     */
    private function assertRunningAgainstTheTestDatabase(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_testing') && $database !== ':memory:') {
            $this->fail(
                "Refusing to run: the test suite is pointed at [{$database}], which is not a "
                .'test database. Check for DB_DATABASE in the environment overriding phpunit.xml.'
            );
        }
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
