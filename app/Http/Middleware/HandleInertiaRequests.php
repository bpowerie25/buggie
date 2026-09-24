<?php

namespace App\Http\Middleware;

use App\Models\InAppNotification;
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
            // Reports, plus issues clients filed that nobody has looked at yet: both
            // are listed on the Triage screen, so both are what the badge counts.
            'inboxCount' => fn () => $user && $workspace && $user->can('viewAny', Report::class)
                ? Report::awaitingTriage()->count() + \App\Models\Issue::forTriage()->count()
                : 0,

            // Only for the people who can decide them, and only this workspace's.
            'accessRequests' => fn () => $user && $workspace
                && ($user->membershipIn($workspace)?->canManageWorkspace() ?? false)
                ? [
                    'enabled' => app(\App\Support\Registration\Registration::class)->mode()->acceptsAccessRequests(),
                    'pending' => \App\Models\AccessRequest::pending()->count(),
                ]
                : null,

            /*
             * Unread notifications, for the sidebar badge.
             *
             * One indexed count, on an index built for exactly this
             * (workspace, user, read_at). For staff that is the whole query. For a
             * client it gains the visibility subquery, because a badge that counts
             * things the list refuses to show is a badge that never reaches zero —
             * and counting them is also how you tell somebody an issue exists.
             */
            'notificationCount' => fn () => $user && $workspace
                ? InAppNotification::query()->visibleTo($user)->whereNull('read_at')->count()
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

            /*
             * Backups, for operators only.
             *
             * Unlike the mail warning this is not shown to a workspace owner: a
             * customer of the hosted service cannot act on it, and telling them the
             * backups are late is alarming without being useful. On a self-hosted
             * install the operator is the person whose server it is, which is exactly
             * who needs to know.
             */
            'backups' => fn () => $user
                && \Illuminate\Support\Facades\Gate::forUser($user)->allows('operate')
                && ($warning = app(\App\Support\Backups\BackupStatus::class)->warning()) !== null
                    ? [
                        'warning' => $warning,
                        'severe' => app(\App\Support\Backups\BackupStatus::class)->isSevere(),
                    ]
                    : null,

            /*
             * A running timer, if there is one.
             *
             * Shared on every page rather than shown only on the issue being timed.
             * The whole failure mode of a timer is forgetting it, and a clock you can
             * only see by navigating back to what you were doing is one you will not
             * see. It follows you across workspaces for the same reason.
             */
            'timer' => fn () => $user && $workspace
                && ($user->membershipIn($workspace)?->isStaff() ?? false)
                ? $this->runningTimer($user)
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

    /**
     * @return array<string, mixed>|null
     */
    private function runningTimer(\App\Models\User $user): ?array
    {
        $timer = \App\Models\RunningTimer::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->with('issue:id,key,title')
            ->first();

        if ($timer === null || $timer->issue === null) {
            return null;
        }

        return [
            'started_at' => $timer->started_at->toIso8601String(),
            'billable' => $timer->billable,
            'issue' => ['key' => $timer->issue->key, 'title' => $timer->issue->title],
            // Said by the server rather than worked out in the browser, so a clock
            // left running over a weekend is flagged even if the tab was never open.
            'forgotten' => $timer->wasForgotten(),
        ];
    }
}
