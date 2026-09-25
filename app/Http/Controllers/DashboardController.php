<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Tenancy $tenancy): Response
    {
        return Inertia::render('dashboard', [
            'workspace' => [
                'name' => $tenancy->currentOrFail()->name,
                // The agency's billing, not its clients' business.
                'trial_ends_at' => ($request->user()->membershipIn($tenancy->currentOrFail())?->isStaff() ?? false)
                    ? $tenancy->currentOrFail()->trial_ends_at?->toDateString()
                    : null,
            ],
            'projects' => Project::active()->visibleTo($request->user())
                ->withCount('statuses')
                ->orderBy('name')
                ->get()
                ->map(fn (Project $p) => [
                    'name' => $p->name,
                    'key' => $p->key,
                    'slug' => $p->slug,
                    'description' => $p->description,
                ]),

            // What is waiting on whoever is looking. For staff, what they hold; for a
            // client, what the team has replied to and is waiting on them for — the
            // awaiting-client status, never the assignee, which is always staff. A
            // client who signs in should see what they owe without the query language.
            'waitingOnYou' => $this->waitingOn($request->user()),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function waitingOn(User $user): array
    {
        $staff = $user->membershipIn(app(Tenancy::class)->currentOrFail())?->isStaff() ?? false;

        return Issue::query()
            ->with(['project:id,key,name', 'status:id,name,category,color'])
            ->when(
                $staff,
                fn ($q) => $q->where('assignee_id', $user->id)
                    ->whereHas('status', fn ($s) => $s->whereNotIn('category', ['done', 'canceled'])),
                // The same scope that decides what a client may open: a dashboard is
                // a listing, and a listing is where a leak goes unnoticed.
                fn ($q) => $q->visibleToClient($user)
                    ->whereHas('status', fn ($s) => $s->where('is_awaiting_client', true)),
            )
            ->latest('updated_at')
            ->limit(10)
            ->get()
            ->map(fn (Issue $issue) => [
                'key' => $issue->key,
                'title' => $issue->title,
                'type' => $issue->type->value,
                'project' => $issue->project->name,
                'status' => [
                    'name' => $issue->status->name,
                    'color' => $issue->status->color,
                ],
                'updated_at' => $issue->updated_at->toIso8601String(),
            ])
            ->all();
    }
}
