<?php

namespace App\Http\Middleware;

use App\Models\Report;
use App\Models\SavedView;
use App\Support\Mail\Deliverability;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();
        $workspace = app(Tenancy::class)->current();

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'initials' => $user->initials(),
                    'avatar_url' => $user->avatar_path
                        ? asset('storage/'.$user->avatar_path)
                        : null,
                ] : null,
                'role' => $user && $workspace
                    ? $user->membershipIn($workspace)?->value
                    : null,

                // Operates the install, as opposed to owning a workspace in it. Used
                // only to decide whether to show the link; the gate does the real work.
                'operator' => $user ? \Illuminate\Support\Facades\Gate::forUser($user)->allows('operate') : false,
            ],

            // The help guide lives on the central domain, so the app needs the whole
            // URL rather than a path: a link to /docs from acme.buggie.eu would look
            // for a workspace route that does not exist.
            'docsUrl' => central_url('docs'),

            'workspace' => $workspace ? [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ] : null,

            // Powers the workspace switcher. Lazy: only resolved when a page asks.
            'workspaces' => fn () => $user
                ? $user->workspaces()->orderBy('name')->get()->map(fn ($w) => [
                    'name' => $w->name,
                    'slug' => $w->slug,
                    'url' => workspace_url($w->slug),
                    'role' => $w->pivot->role,
                ])
                : [],

            // Saved views drive the sidebar and the command palette everywhere.
            'views' => fn () => $user && $workspace
                ? SavedView::visibleTo($user)->orderBy('position')->get()->map(fn (SavedView $view) => [
                    'id' => $view->id,
                    'name' => $view->name,
                    'query' => $view->query,
                    'layout' => $view->layout,
                    'group_by' => $view->group_by,
                    'shared' => $view->isShared(),
                    'can_edit' => $user->can('update', $view),
                ])
                : [],

            // Staff only: the badge should not tell a client an inbox exists.
            'inboxCount' => fn () => $user && $workspace && $user->can('viewAny', Report::class)
                ? Report::awaitingTriage()->count()
                : 0,

            // Drives the usage banner. Cheap: three counts, and only for staff who
            // could act on it.
            'billing' => fn () => config('buggie.hosted')
                && $user && $workspace && $user->membershipIn($workspace)?->isStaff()
                ? [
                    'plan' => $workspace->plan()->name(),
                    'usage' => $workspace->usage(),
                    'on_trial' => (bool) $workspace->trial_ends_at?->isFuture(),
                    'trial_days_left' => $workspace->trial_ends_at?->isFuture()
                        ? (int) ceil(now()->diffInDays($workspace->trial_ends_at, false))
                        : 0,
                    'can_manage' => $user->can('manageBilling', $workspace),
                ]
                : null,

            /*
             * Warns that mail is going nowhere.
             *
             * Shown to anyone who can invite, not only to operators: on the hosted
             * service a workspace owner cannot fix this, but they are the one whose
             * invitation just silently failed, and they can still send the link by
             * hand. Withholding it would leave them waiting on a reply that cannot
             * come.
             *
             * It carries no host, no credentials and no driver name — only that mail
             * does not work, and whether this person is the one who can fix it.
             */
            'mail' => fn () => $user && ! app(Deliverability::class)->isConfigured()
                ? [
                    'deliverable' => false,
                    'can_fix' => \Illuminate\Support\Facades\Gate::forUser($user)->allows('operate'),
                ]
                : null,

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],

            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }
}
