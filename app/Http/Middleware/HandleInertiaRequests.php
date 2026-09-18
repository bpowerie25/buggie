<?php

namespace App\Http\Middleware;

use App\Models\SavedView;
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
            ],

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
