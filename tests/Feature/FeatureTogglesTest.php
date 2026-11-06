<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FeatureTogglesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_admin_can_switch_areas_off_and_their_pages_disappear(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        foreach (['/timeline', '/workload', '/time'] as $path) {
            $this->actingAs($owner)->get($this->workspaceUrl($workspace, $path))->assertOk();
        }

        $this->actingAs($owner)->patch($this->workspaceUrl($workspace, '/settings/workspace'), [
            'name' => $workspace->name,
            'features' => ['timeline' => false, 'time' => false, 'workload' => false, 'invented' => true],
        ])->assertRedirect();

        foreach (['/timeline', '/workload', '/time'] as $path) {
            $this->actingAs($owner)->get($this->workspaceUrl($workspace, $path))->assertNotFound();
        }

        $this->assertArrayNotHasKey('invented', $workspace->fresh()->settings['features']);
    }

    #[Test]
    public function labels_are_for_admins_unless_an_admin_delegates_them(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => WorkspaceRole::Member->value, 'joined_at' => now()]);

        $url = $this->workspaceUrl($workspace, '/labels');

        $this->actingAs($member)->post($url, ['name' => 'nope'])->assertForbidden();
        $this->actingAs($owner)->post($url, ['name' => 'fine'])->assertRedirect();

        $this->actingAs($owner)->patch($this->workspaceUrl($workspace, '/settings/workspace'), [
            'name' => $workspace->name,
            'members_manage_labels' => true,
        ]);

        $this->actingAs($member)->post($url, ['name' => 'now ok'])->assertRedirect();
    }
}
