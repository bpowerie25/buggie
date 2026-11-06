<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Label;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function changes_are_recorded_with_who_made_them_and_what_they_were(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        $this->actingAs($owner)->post($this->workspaceUrl($workspace, '/labels'), ['name' => 'bug', 'color' => '#ef4444']);
        $this->actingAs($owner);
        app(Tenancy::class)->run($workspace, function () {
            $l = Label::firstOrFail();
            $l->name = 'defect';
            $l->save();
        });

        $logs = app(Tenancy::class)->run($workspace, fn () => AuditLog::orderBy('id')->get());

        $this->assertSame(['label.created', 'label.updated'], $logs->pluck('event')->all());
        $this->assertSame($owner->id, $logs[1]->actor_id);
        $this->assertSame('defect', $logs[1]->subject_label);
        $this->assertEquals(['from' => 'bug', 'to' => 'defect'], $logs[1]->changes['name']);
    }

    #[Test]
    public function the_trail_cannot_be_edited_or_deleted(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');
        $this->actingAs($owner)->post($this->workspaceUrl($workspace, '/labels'), ['name' => 'bug', 'color' => '#ef4444']);

        $this->expectException(\LogicException::class);
        app(Tenancy::class)->run($workspace, fn () => AuditLog::firstOrFail()->delete());
    }

    #[Test]
    public function only_admins_read_it_and_only_their_own_workspace(): void
    {
        [$acme, $owner] = $this->workspaceWithMember(slug: 'acme');
        [$globex, $other] = $this->workspaceWithMember(slug: 'globex');
        $member = User::factory()->create();
        $acme->members()->attach($member->id, ['role' => WorkspaceRole::Member->value, 'joined_at' => now()]);

        $this->actingAs($other)->post($this->workspaceUrl($globex, '/labels'), ['name' => 'secret-globex-label', 'color' => '#ef4444']);

        $this->actingAs($member)->get($this->workspaceUrl($acme, '/settings/audit'))->assertForbidden();

        $page = $this->actingAs($owner)->get($this->workspaceUrl($acme, '/settings/audit'))->assertOk();
        $this->assertStringNotContainsString('secret-globex-label', $page->getContent());
    }

    #[Test]
    public function sign_ins_are_recorded(): void
    {
        [$workspace, $owner] = $this->workspaceWithMember(slug: 'acme');

        event(new \Illuminate\Auth\Events\Login('web', $owner, false));

        $this->assertTrue(app(Tenancy::class)->run($workspace, fn () => AuditLog::where('event', 'auth.login')->where('actor_id', $owner->id)->exists()));
    }
}
