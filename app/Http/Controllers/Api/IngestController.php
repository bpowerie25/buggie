<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IngestReportRequest;
use App\Jobs\ProcessIncomingReport;
use App\Models\Report;
use App\Models\WidgetKey;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Unauthenticated by necessity, and therefore treated as hostile.
 *
 * Runs on the central domain, outside workspace resolution: the key identifies the
 * tenant, and everything is written inside Tenancy::run() so the usual scope applies.
 */
class IngestController extends Controller
{
    /** Per key, per minute, and per key per hour. */
    private const PER_IP_PER_MINUTE = 5;

    private const PER_KEY_PER_HOUR = 200;

    public function store(IngestReportRequest $request, string $publicKey): JsonResponse
    {
        $key = WidgetKey::withoutGlobalScopes()
            ->with('project')
            ->where('public_key', $publicKey)
            ->where('is_active', true)
            ->first();

        // A wrong or retired key is indistinguishable from a missing one.
        if ($key === null) {
            return response()->json(['message' => 'Unknown widget key.'], 404);
        }

        if (! $key->allowsOrigin($request->headers->get('Origin'))) {
            return response()->json(['message' => 'Origin not allowed.'], 403);
        }

        if ($limited = $this->rateLimit($request, $key)) {
            return $limited;
        }

        $workspace = $key->project->workspace;

        if (! $workspace->isWithinLimit('reports_per_month')) {
            // 402 rather than 429: this is not "slow down", it is "this account has
            // run out". The widget shows the message, so the person who hit the bug
            // is told something true rather than "could not send".
            return response()->json([
                'message' => 'This site has reached its monthly report limit. '
                    .'Please let the team know directly.',
            ], 402);
        }

        $ipHash = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

        $report = app(Tenancy::class)->run($key->project->workspace, fn () => Report::create([
            'project_id' => $key->project_id,
            'widget_key_id' => $key->id,
            'title' => Str::limit($request->string('title')->trim()->toString(), 250, ''),
            'body' => $request->string('body')->trim()->toString() ?: null,
            'reporter_name' => $request->input('reporter.name'),
            'reporter_email' => $request->input('reporter.email'),
            'reporter_ref' => $request->input('reporter.ref'),
            'environment' => $this->environment($request),
            'console' => array_slice((array) $request->input('console', []), -50),
            'network' => array_slice((array) $request->input('network', []), -30),
            'error' => $request->input('error') ?: null,
            'ip_hash' => $ipHash,
        ]));

        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        // Everything expensive happens on the queue. The person who just hit a bug is
        // waiting on this response.
        ProcessIncomingReport::dispatch($report->id, $key->project->workspace_id);

        return response()->json([
            'id' => $report->id,
            'reference' => 'R-'.$report->id,
            // A short-lived, single-purpose URL for the screenshot, so large binaries
            // never pass through this endpoint.
            'upload_url' => $request->boolean('screenshot') && $key->capture_screenshot
                ? URL::temporarySignedRoute('ingest.screenshot', now()->addMinutes(5), [
                    'report' => $report->id,
                ])
                : null,
        ], 202);
    }

    /** Accepts exactly one image for a report, once, within five minutes. */
    public function screenshot(Request $request, int $report): JsonResponse
    {
        $request->validate([
            'screenshot' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        $model = Report::withoutGlobalScopes()->findOrFail($report);

        if ($model->screenshot_path !== null) {
            return response()->json(['message' => 'Already uploaded.'], 409);
        }

        $path = $request->file('screenshot')->store(
            "workspaces/{$model->workspace_id}/reports",
            'local',
        );

        $model->forceFill(['screenshot_path' => $path])->saveQuietly();

        return response()->json(['ok' => true]);
    }

    private function rateLimit(Request $request, WidgetKey $key): ?JsonResponse
    {
        $perIp = "ingest:{$key->id}:".sha1((string) $request->ip());
        $perKey = "ingest:{$key->id}";

        foreach ([[$perIp, self::PER_IP_PER_MINUTE, 60], [$perKey, self::PER_KEY_PER_HOUR, 3600]] as [$bucket, $max, $decay]) {
            if (RateLimiter::tooManyAttempts($bucket, $max)) {
                return response()->json(
                    ['message' => 'Too many reports. Try again shortly.'],
                    429,
                    ['Retry-After' => RateLimiter::availableIn($bucket)],
                );
            }

            RateLimiter::hit($bucket, $decay);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function environment(Request $request): array
    {
        $environment = (array) $request->input('environment', []);

        // Defence in depth: the widget already strips these, but the endpoint cannot
        // assume the payload came from the widget.
        if (isset($environment['url'])) {
            $environment['url'] = self::stripSecrets((string) $environment['url']);
        }

        if (isset($environment['referrer'])) {
            $environment['referrer'] = self::stripSecrets((string) $environment['referrer']);
        }

        return $environment;
    }

    /** Remove query parameters that look like credentials. */
    public static function stripSecrets(string $url): string
    {
        return (string) preg_replace(
            '/([?&])(token|key|secret|password|passwd|auth|session|sig|signature|access_token|api_key)=[^&#]*/i',
            '$1$2=[redacted]',
            $url,
        );
    }
}
