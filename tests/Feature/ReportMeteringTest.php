<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Report;
use App\Models\WidgetKey;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a customer is actually billed for.
 *
 * Duplicates collapse into one issue, which is the loudest promise the product
 * makes. Billing used to count all forty, which contradicted it.
 */
class ReportMeteringTest extends TestCase
{
    use RefreshDatabase;

    private function key(): WidgetKey
    {
        [$workspace] = $this->workspaceWithMember(slug: 'acme');

        return app(Tenancy::class)->run($workspace, function () {
            $project = Project::factory()->create(['key' => 'WEB']);

            return WidgetKey::create(['project_id' => $project->id, 'mode' => 'identified']);
        });
    }

    private int $sender = 0;

    /**
     * One report, from a different address each time.
     *
     * Forty people hitting one broken checkout are forty people, not one pressing
     * the button forty times — and the per-IP rate limit exists precisely to stop
     * the latter, so sharing an address here would test the wrong thing.
     *
     * @param array<string, mixed> $overrides
     */
    private function send(WidgetKey $key, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $this->sender++;

        return $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$this->sender}"])
            ->postJson("http://".config('buggie.host')."/api/ingest/{$key->public_key}", [
            'title' => 'Pay now does nothing',
            'environment' => ['url' => 'https://acme.test/checkout'],
            'error' => ['message' => "Cannot read properties of null (reading 'total')"],
            ...$overrides,
        ]);
    }

    #[Test]
    public function the_first_few_of_a_bug_are_metered_and_the_rest_are_not(): void
    {
        config(['plans.collapse_after' => 3]);

        $key = $this->key();
        $workspace = $key->project->workspace;

        for ($i = 0; $i < 7; $i++) {
            $this->send($key)->assertStatus(202);
        }

        $this->assertSame(7, Report::withoutGlobalScopes()->count());
        $this->assertSame(3, $workspace->fresh()->reportsThisMonth());
    }

    #[Test]
    public function an_unmetered_duplicate_is_not_offered_an_upload_url(): void
    {
        // Refusing the image is what makes it free to store, and therefore fair to
        // give away. Without this the saving is imaginary.
        config(['plans.collapse_after' => 1]);

        $key = $this->key();

        $first = $this->send($key, ['screenshot' => true])->assertStatus(202);
        $second = $this->send($key, ['screenshot' => true])->assertStatus(202);

        $this->assertNotNull($first->json('upload_url'));
        $this->assertNull($second->json('upload_url'));
    }

    #[Test]
    public function an_unmetered_duplicate_keeps_its_occurrence_but_drops_the_bulk(): void
    {
        config(['plans.collapse_after' => 1]);

        $key = $this->key();
        $payload = [
            'console' => [['level' => 'error', 'message' => 'boom']],
            'network' => [['method' => 'POST', 'url' => 'https://acme.test/pay', 'status' => 500]],
        ];

        $this->send($key, $payload)->assertStatus(202);
        $this->send($key, $payload)->assertStatus(202);

        $reports = Report::withoutGlobalScopes()->orderBy('id')->get();

        $this->assertCount(1, $reports[0]->console);
        $this->assertSame([], $reports[1]->console);
        $this->assertSame([], $reports[1]->network);

        // Still an occurrence: the error and the page are what make it one, and they
        // are small.
        $this->assertNotNull($reports[1]->error);
        $this->assertSame('https://acme.test/checkout', $reports[1]->environment['url']);
        $this->assertSame($reports[0]->fingerprint, $reports[1]->fingerprint);
    }

    #[Test]
    public function a_different_bug_is_metered_on_its_own_count(): void
    {
        // The control. Without it, all of this passes for an implementation that
        // simply stops metering after the first report of anything.
        config(['plans.collapse_after' => 2]);

        $key = $this->key();
        $workspace = $key->project->workspace;

        foreach (range(1, 3) as $i) {
            $this->send($key)->assertStatus(202);
        }

        foreach (range(1, 3) as $i) {
            $this->send($key, [
                'error' => ['message' => 'A completely different explosion'],
            ])->assertStatus(202);
        }

        // Both bugs fingerprint separately, so each gets its own allowance of two.

        // Two of each, not two in total.
        $this->assertSame(4, $workspace->fresh()->reportsThisMonth());
    }

    #[Test]
    public function a_report_with_no_error_always_counts(): void
    {
        // Nothing to group on means a human reads it individually, which is a real
        // unit of work however many arrive.
        config(['plans.collapse_after' => 1]);

        $key = $this->key();
        $workspace = $key->project->workspace;

        foreach (range(1, 4) as $i) {
            $this->send($key, ['error' => null, 'title' => "The layout looks wrong {$i}"])
                ->assertStatus(202);
        }

        $this->assertSame(4, $workspace->fresh()->reportsThisMonth());
    }

    #[Test]
    public function one_bug_going_round_cannot_switch_reporting_off(): void
    {
        // The reason this exists. A viral duplicate used to eat the allowance and
        // then show a payment message to the client's own testers.
        // A new workspace is on trial, so pin the trial plan and give it a small
        // allowance rather than sending two thousand reports to prove a point.
        config([
            // Self-hosted installs are not metered at all, so there is no allowance
            // to run out of unless this is the hosted service.
            'buggie.hosted' => true,
            // One metered report per bug, so the second is demonstrably free.
            'plans.collapse_after' => 1,
            'plans.trial' => 'free',
            'plans.plans.free.limits.reports_per_month' => 3,
        ]);

        $key = $this->key();

        // Genuinely different bugs. Not "failure 1/2/3": the fingerprint normaliser
        // replaces digits, quite rightly, so those are one bug reported three times.
        $bugs = [
            'Cannot read properties of null',
            'Network request failed',
            'undefined is not a function',
        ];

        foreach ($bugs as $message) {
            $this->send($key, ['error' => ['message' => $message]])->assertStatus(202);
        }

        // A brand new bug is refused, correctly: it is real work and they are out.
        $this->send($key, ['error' => ['message' => 'Maximum call stack size exceeded']])
            ->assertStatus(402);

        // But another report of a bug already seen still gets through.
        $this->send($key, ['error' => ['message' => $bugs[0]]])->assertStatus(202);
    }

    #[Test]
    public function collapsing_does_not_remove_the_rate_limit(): void
    {
        // Unmetered reports are free, so the allowance no longer bounds them. The
        // rate limit is now the only thing that does, which makes it load-bearing in
        // a way it was not before. Pinned so it is never quietly removed.
        config(['plans.collapse_after' => 1]);

        $key = $this->key();
        $accepted = 0;

        // One person, one address, pressing the button over and over.
        for ($i = 0; $i < 20; $i++) {
            $status = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.250'])
                ->postJson("http://".config('buggie.host')."/api/ingest/{$key->public_key}", [
                    'title' => 'Pay now does nothing',
                    'environment' => ['url' => 'https://acme.test/checkout'],
                    'error' => ['message' => 'Cannot read properties of null'],
                ])->status();

            if ($status === 202) {
                $accepted++;
            }
        }

        $this->assertLessThan(20, $accepted, 'The per-IP rate limit should bound this.');
    }
}
