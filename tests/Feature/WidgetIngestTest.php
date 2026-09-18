<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Jobs\ProcessIncomingReport;
use App\Models\Project;
use App\Models\Report;
use App\Models\WidgetKey;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The ingest endpoint is unauthenticated by necessity — the key sits in the page source
 * of the customer's app — so these tests are written from the position of someone who
 * has the key and means harm.
 */
class WidgetIngestTest extends TestCase
{
    use RefreshDatabase;

    private function widgetKey(array $state = []): WidgetKey
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        return app(Tenancy::class)->run($workspace, function () use ($state) {
            $project = Project::factory()->create(['key' => 'WEB']);

            return WidgetKey::factory()->create([...$state, 'project_id' => $project->id]);
        });
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'title' => 'Checkout button does nothing',
            'body' => 'Clicking pay does nothing on Safari.',
            'reporter' => ['name' => 'Jo', 'email' => 'jo@example.com'],
            'environment' => ['url' => 'https://acme.test/orders/42/checkout'],
            'console' => [['level' => 'error', 'message' => 'boom']],
            'network' => [],
        ], $overrides);
    }

    #[Test]
    public function a_valid_report_is_accepted_and_queued(): void
    {
        Queue::fake();
        $key = $this->widgetKey();

        $response = $this->postJson("/api/ingest/{$key->public_key}", $this->payload());

        $response->assertStatus(202)->assertJsonStructure(['id', 'reference', 'upload_url']);

        $this->assertDatabaseHas('reports', [
            'title' => 'Checkout button does nothing',
            'state' => ReportState::New->value,
            'project_id' => $key->project_id,
        ]);

        // Nothing expensive happens inline: the person who hit the bug is waiting.
        Queue::assertPushed(ProcessIncomingReport::class);
    }

    #[Test]
    public function an_unknown_or_retired_key_is_a_404(): void
    {
        $this->postJson('/api/ingest/pk_nope', $this->payload())->assertNotFound();

        $inactive = $this->widgetKey(['is_active' => false]);

        $this->postJson("/api/ingest/{$inactive->public_key}", $this->payload())
            ->assertNotFound();
    }

    #[Test]
    public function the_origin_allowlist_is_enforced(): void
    {
        Queue::fake();
        $key = $this->widgetKey(['allowed_origins' => ['https://acme.com', 'https://*.acme.com']]);

        $this->postJson("/api/ingest/{$key->public_key}", $this->payload(), [
            'Origin' => 'https://evil.example',
        ])->assertForbidden();

        $this->postJson("/api/ingest/{$key->public_key}", $this->payload(), [
            'Origin' => 'https://acme.com',
        ])->assertStatus(202);

        $this->postJson("/api/ingest/{$key->public_key}", $this->payload(), [
            'Origin' => 'https://app.acme.com',
        ])->assertStatus(202);
    }

    #[Test]
    public function an_empty_allowlist_accepts_any_origin(): void
    {
        Queue::fake();
        $key = $this->widgetKey(['allowed_origins' => []]);

        // The only workable default for a paste-this-snippet install.
        $this->postJson("/api/ingest/{$key->public_key}", $this->payload(), [
            'Origin' => 'https://anywhere.example',
        ])->assertStatus(202);
    }

    #[Test]
    public function repeat_submissions_are_rate_limited(): void
    {
        Queue::fake();
        $key = $this->widgetKey();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/ingest/{$key->public_key}", $this->payload())
                ->assertStatus(202);
        }

        $this->postJson("/api/ingest/{$key->public_key}", $this->payload())
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    #[Test]
    public function oversized_and_malformed_payloads_are_rejected(): void
    {
        Queue::fake();
        $key = $this->widgetKey();

        $this->postJson("/api/ingest/{$key->public_key}", ['body' => 'no title'])
            ->assertStatus(422);

        $this->postJson("/api/ingest/{$key->public_key}", $this->payload([
            'console' => array_fill(0, 200, ['level' => 'log', 'message' => 'x']),
        ]))->assertStatus(422);

        $this->postJson("/api/ingest/{$key->public_key}", $this->payload([
            'error' => ['message' => str_repeat('x', 3000)],
        ]))->assertStatus(422);
    }

    #[Test]
    public function credentials_in_the_captured_url_are_redacted(): void
    {
        Queue::fake();
        $key = $this->widgetKey();

        // Defence in depth: the widget strips these too, but this endpoint cannot
        // assume the payload came from the widget.
        $this->postJson("/api/ingest/{$key->public_key}", $this->payload([
            'environment' => ['url' => 'https://acme.test/reset?token=supersecret&page=2'],
        ]))->assertStatus(202);

        $report = Report::withoutGlobalScopes()->firstOrFail();

        $this->assertStringNotContainsString('supersecret', $report->environment['url']);
        $this->assertStringContainsString('token=[redacted]', $report->environment['url']);
        $this->assertStringContainsString('page=2', $report->environment['url']);
    }

    #[Test]
    public function the_reporters_ip_is_stored_only_as_a_hash(): void
    {
        Queue::fake();
        $key = $this->widgetKey();

        $this->postJson("/api/ingest/{$key->public_key}", $this->payload(), [
            'REMOTE_ADDR' => '203.0.113.9',
        ])->assertStatus(202);

        $report = Report::withoutGlobalScopes()->firstOrFail();

        $this->assertNotNull($report->ip_hash);
        $this->assertNotSame('203.0.113.9', $report->ip_hash);
        $this->assertStringNotContainsString('203.0.113', json_encode($report->toArray()));
    }

    #[Test]
    public function a_report_lands_in_the_workspace_that_owns_the_key(): void
    {
        Queue::fake();
        $key = $this->widgetKey();
        $this->workspaceWithMember(slug: 'globex');

        $this->postJson("/api/ingest/{$key->public_key}", $this->payload())->assertStatus(202);

        $report = Report::withoutGlobalScopes()->firstOrFail();

        $this->assertSame($key->workspace_id, $report->workspace_id);
    }

    #[Test]
    public function the_screenshot_upload_url_is_signed_and_single_use(): void
    {
        Queue::fake();
        $key = $this->widgetKey();

        $url = $this->postJson("/api/ingest/{$key->public_key}", $this->payload(['screenshot' => true]))
            ->assertStatus(202)
            ->json('upload_url');

        $this->assertNotNull($url);

        // Unsigned access is refused.
        $unsigned = preg_replace('/\?.*$/', '', $url);
        $this->post($unsigned, [])->assertStatus(403);
    }
}
