<?php

namespace App\Http\Controllers;

use App\Support\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Settings for the whole install, not for a workspace.
 *
 * Reachable only by an operator: on the hosted service that is whoever runs it, and
 * on a self-hosted install it is the person whose server it is. A workspace owner is
 * not an operator — one customer must not be able to redirect everybody's mail.
 */
class InstanceSettingsController extends Controller
{
    public function __construct(private Settings $settings) {}

    public function edit(Request $request): Response
    {
        $this->authorize('operate');

        return Inertia::render('settings/instance', [
            'mail' => [
                'mailer' => $this->settings->get('mail.mailer', config('mail.default')),
                'host' => $this->settings->get('mail.host', config('mail.mailers.smtp.host')),
                'port' => (int) $this->settings->get('mail.port', config('mail.mailers.smtp.port')),
                'username' => $this->settings->get('mail.username', config('mail.mailers.smtp.username')),
                'encryption' => $this->settings->get('mail.encryption', config('mail.mailers.smtp.encryption')) ?? 'tls',
                'from_address' => $this->settings->get('mail.from_address', config('mail.from.address')),
                'from_name' => $this->settings->get('mail.from_name', config('mail.from.name')),

                // Never the password itself. Whether one is set is all the form needs
                // to know, and all it should be told.
                'has_password' => $this->settings->get('mail.password') !== null,
            ],
            'configured_by_env' => $this->settings->get('mail.mailer') === null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('operate');

        $validated = $request->validate([
            'mailer' => ['required', Rule::in(['smtp', 'log'])],
            'host' => ['required_if:mailer,smtp', 'nullable', 'string', 'max:255'],
            'port' => ['required_if:mailer,smtp', 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:120'],
        ]);

        $this->settings->put([
            'mail.mailer' => $validated['mailer'],
            'mail.host' => $validated['host'] ?? null,
            'mail.port' => $validated['port'] ?? null,
            'mail.username' => $validated['username'] ?? null,
            // Blank leaves the stored one alone; see Settings::put().
            'mail.password' => $validated['password'] ?? null,
            'mail.encryption' => ($validated['encryption'] ?? 'tls') === 'none'
                ? null
                : ($validated['encryption'] ?? 'tls'),
            'mail.from_address' => $validated['from_address'],
            'mail.from_name' => $validated['from_name'],
        ]);

        return back()->with('success', 'Mail settings saved. Send yourself a test to be sure.');
    }

    /**
     * Send one message, to whoever is asking, and report what happened.
     *
     * The most valuable part of this screen. Mail failure is otherwise completely
     * silent: invitations, password resets and every notification vanish with nothing
     * on screen and nothing a user could report.
     */
    public function test(Request $request): RedirectResponse
    {
        $this->authorize('operate');

        try {
            Mail::raw(
                "This is a test from Buggie.\n\nIf you are reading it, mail works.",
                fn ($message) => $message
                    ->to($request->user()->email)
                    ->subject('Buggie test message'),
            );
        } catch (Throwable $e) {
            // The provider's own words. "Failed to send" tells nobody which of the
            // host, port, credentials or firewall is wrong.
            return back()->withErrors([
                'mail' => 'Could not send: '.$e->getMessage(),
            ]);
        }

        return back()->with(
            'success',
            "Sent to {$request->user()->email}. If it does not arrive, check the spam folder and the sending domain's SPF record.",
        );
    }
}
