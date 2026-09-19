<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IngestReportRequest;
use App\Jobs\ProcessIncomingReport;
use App\Models\Report;
use App\Support\Reports\Fingerprint;
use App\Models\Workspace;
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

        // Fingerprinted here rather than on the queue, because whether this report
        // is a duplicate decides whether it is metered, and that has to be settled
        // before the allowance is checked. It is string normalisation and a sha1;
        // the person who just hit a bug is not waiting on anything expensive.
        $fingerprint = Fingerprint::for(
            $request->input('error') ?: null,
            $request->input('environment.url'),
        );

        $metered = $this->isMetered($key->project_id, $fingerprint, $workspace);

        // Only metered reports are refused when the allowance is gone. A duplicate
        // that costs nothing to store still gets through, so one bug going round a
        // client's testers cannot switch reporting off for everybody.
        if ($metered && ! $workspace->isWithinLimit('reports_per_month')) {
            // 402 rather than 429: this is not "slow down", it is "this account has
            // run out". The widget shows the message, so the person who hit the bug
            // is told something true rather than "could not send".
            return response()->json([
                'message' => 'This site has reached its monthly report limit. '
                    .'Please let the team know directly.',
            ], 402);
        }

        // Enforced here as well as asked for in the form. A rule the browser keeps
        // is a rule anybody can decline to keep.
        if ($key->require_email && ! filter_var((string) $request->input('reporter.email'), FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'message' => 'This site asks for an email address with every report.',
                'errors' => ['reporter.email' => ['An email address is required.']],
            ], 422);
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

            // An unmetered duplicate keeps what makes it an occurrence — the error,
            // the page, who hit it — and drops the bulk. The console and network of
            // the sixth identical report tell nobody anything the first five did not.
            'console' => $metered ? array_slice((array) $request->input('console', []), -50) : [],
            'network' => $metered ? array_slice((array) $request->input('network', []), -30) : [],
            'error' => $request->input('error') ?: null,
            'ip_hash' => $ipHash,
        ]));

        $report->forceFill(['fingerprint' => $fingerprint, 'metered' => $metered])->saveQuietly();

        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        // Everything expensive happens on the queue. The person who just hit a bug is
        // waiting on this response.
        ProcessIncomingReport::dispatch($report->id, $key->project->workspace_id);

        app(Tenancy::class)->run(
            $key->project->workspace,
            fn () => \App\Support\Webhooks\Webhooks::report($report->load('project')),
        );

        return response()->json([
            'id' => $report->id,
            'reference' => 'R-'.$report->id,
            // A short-lived, single-purpose URL for the screenshot, so large binaries
            // never pass through this endpoint.
            // No upload URL for an unmetered duplicate: refusing the image is what
            // makes it free to store, and therefore fair to give away.
            'upload_url' => $metered && $request->boolean('screenshot') && $key->capture_screenshot
                ? URL::temporarySignedRoute('ingest.screenshot', now()->addMinutes(5), [
                    'report' => $report->id,
                ])
                : null,
        ], 202);
    }

    /**
     * What the widget should do, according to the project rather than the script tag.
     *
     * Fetched when somebody opens the reporter, not on page load: most visitors never
     * report anything, and the widget's whole argument is that it costs them nothing.
     * By the time the panel is open, one small request is free.
     *
     * The settings were previously read only from `data-` attributes, so the
     * checkboxes in project settings were stored and never consulted — the screen
     * said one thing and the widget did another.
     */
    public function config(string $publicKey): JsonResponse
    {
        $key = WidgetKey::withoutGlobalScopes()
            ->with('project')
            ->where('public_key', $publicKey)
            ->where('is_active', true)
            ->first();

        // The same answer for an unknown key as for a retired one, and no hint that
        // either kind exists.
        if ($key === null) {
            return response()->json(['message' => 'Unknown key.'], 404);
        }

        return response()->json([
            'require_email' => (bool) $key->require_email,
            'capture_screenshot' => (bool) $key->capture_screenshot,
            'mode' => $key->mode,
        ]);
    }

    /**
     * Whether this report counts against the monthly allowance.
     *
     * Anything without a fingerprint always counts: no error means no grouping, so
     * it goes to a human individually and is a real unit of work. Otherwise the
     * first few of a given bug each month are metered and the rest are not.
     */
    private function isMetered(int $projectId, ?string $fingerprint, Workspace $workspace): bool
    {
        if ($fingerprint === null) {
            return true;
        }

        $already = Report::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->where('fingerprint', $fingerprint)
            ->where('metered', true)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        return $already < (int) config('plans.collapse_after');
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
