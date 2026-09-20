<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Support\Backups\BackupStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Backups fail quietly. Everything here is about them failing loudly instead.
 */
class BackupStatusTest extends TestCase
{
    use RefreshDatabase;

    private function wrote(array $status): void
    {
        Storage::disk('local')->put('backup-status.json', json_encode($status));
    }

    private function goodStatus(array $overrides = []): array
    {
        return [
            'at' => now()->toIso8601ZuluString(),
            'ok' => true,
            'stage' => 'done',
            'detail' => 'copied off the server',
            'offsite' => true,
            ...$overrides,
        ];
    }

    #[Test]
    public function a_healthy_recent_offsite_backup_says_nothing(): void
    {
        // A banner that is always there is a banner nobody reads.
        $this->wrote($this->goodStatus());

        $this->assertNull(app(BackupStatus::class)->warning());
    }

    #[Test]
    public function never_having_run_is_reported(): void
    {
        Storage::disk('local')->delete('backup-status.json');

        $this->assertStringContainsString('has ever been recorded', app(BackupStatus::class)->warning());
        $this->assertTrue(app(BackupStatus::class)->isSevere());
    }

    #[Test]
    public function a_failure_says_which_stage_and_why(): void
    {
        // "The backup failed" sends somebody to a log. "It failed verifying the
        // database dump" sends them to the disk.
        $this->wrote($this->goodStatus([
            'ok' => false,
            'stage' => 'verifying the database dump',
            'detail' => 'the dump is implausibly small',
        ]));

        $warning = app(BackupStatus::class)->warning();

        $this->assertStringContainsString('verifying the database dump', $warning);
        $this->assertStringContainsString('implausibly small', $warning);
        $this->assertTrue(app(BackupStatus::class)->isSevere());
    }

    #[Test]
    public function silence_becomes_a_warning_after_two_missed_nights(): void
    {
        // One late run is not a fault; two missed nights is.
        $this->wrote($this->goodStatus(['at' => now()->subHours(30)->toIso8601ZuluString()]));
        $this->assertNull(app(BackupStatus::class)->warning(), 'A late run raised a false alarm.');

        $this->wrote($this->goodStatus(['at' => now()->subHours(40)->toIso8601ZuluString()]));
        $this->assertStringContainsString('nightly', app(BackupStatus::class)->warning());
    }

    #[Test]
    public function backups_that_never_leave_the_server_are_flagged_but_not_as_a_fault(): void
    {
        $this->wrote($this->goodStatus(['offsite' => false]));

        $status = app(BackupStatus::class);

        $this->assertStringContainsString('BUGGIE_BACKUP_REMOTE', $status->warning());
        $this->assertFalse($status->isSevere(), 'A risk was reported as a fault.');
    }

    #[Test]
    public function a_corrupt_status_file_does_not_take_the_app_down(): void
    {
        Storage::disk('local')->put('backup-status.json', 'not json at all {{{');

        // Unreadable is treated as absent: a broken status file must never be the
        // reason the application stops rendering.
        $this->assertStringContainsString('has ever been recorded', app(BackupStatus::class)->warning());
    }

    #[Test]
    public function only_an_operator_is_told(): void
    {
        // A customer of the hosted service cannot act on this, and being told the
        // backups are late is alarming without being useful.
        config(['buggie.hosted' => true, 'buggie.operators' => ['ops@buggie.eu']]);

        $this->wrote($this->goodStatus(['ok' => false, 'stage' => 'database', 'detail' => 'it died']));

        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('backups', null));

        $operator = User::factory()->create(['email' => 'ops@buggie.eu']);
        $workspace->members()->attach($operator->id, [
            'role' => WorkspaceRole::Admin->value, 'joined_at' => now(),
        ]);

        // The paired control: the person who can act on it is told.
        $this->actingAs($operator)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('backups.severe', true)
                ->where('backups.warning', 'The last backup failed at the database stage: it died.'));
    }

    #[Test]
    public function a_healthy_backup_reaches_nobodys_screen(): void
    {
        config(['buggie.hosted' => true, 'buggie.operators' => ['ops@buggie.eu']]);

        $this->wrote($this->goodStatus());

        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        $operator = User::factory()->create(['email' => 'ops@buggie.eu']);
        $workspace->members()->attach($operator->id, [
            'role' => WorkspaceRole::Admin->value, 'joined_at' => now(),
        ]);

        $this->actingAs($operator)
            ->get($this->workspaceUrl($workspace, '/'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('backups', null));
    }
}
