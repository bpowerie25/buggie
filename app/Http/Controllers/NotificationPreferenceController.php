<?php

namespace App\Http\Controllers;

use App\Enums\NotificationReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What you are emailed about.
 *
 * The preference was honoured by the notifier from the start and had nowhere to be
 * set, so "notifications are opt-out" was true of the code and false in practice:
 * nobody could opt out of anything.
 *
 * These belong to the person, not the workspace. Somebody invited to four client
 * workspaces should not have to switch the same thing off four times.
 */
class NotificationPreferenceController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/notifications', [
            'reasons' => array_map(fn (NotificationReason $reason) => [
                'value' => $reason->value,
                'label' => $reason->label(),
                'description' => $reason->description(),
                'enabled' => $user->wantsNotification($reason),
            ], NotificationReason::cases()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $values = array_map(fn (NotificationReason $reason) => $reason->value, NotificationReason::cases());

        $validated = $request->validate([
            'reasons' => ['present', 'array'],
            'reasons.*' => ['boolean'],
        ]);

        // Rebuilt from the enum rather than stored as sent, so a stale key from an
        // old form — or an invented one — cannot end up in the column.
        $settings = [];

        foreach ($values as $value) {
            $settings[$value] = (bool) ($validated['reasons'][$value] ?? false);
        }

        $request->user()->forceFill(['notification_settings' => $settings])->save();

        return back()->with('success', 'Notification preferences saved.');
    }
}
