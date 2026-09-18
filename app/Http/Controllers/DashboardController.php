<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(\Illuminate\Http\Request $request, Tenancy $tenancy): Response
    {
        return Inertia::render('dashboard', [
            'workspace' => [
                'name' => $tenancy->currentOrFail()->name,
                'trial_ends_at' => $tenancy->currentOrFail()->trial_ends_at?->toDateString(),
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

            // Anything assigned to whoever is looking, open only.
            //
            // It matters most for clients: the team asks them a question by assigning
            // the issue, and without this the only prompt is an email. A client who
            // signs in should be able to see what is waiting on them without knowing
            // the query language.
            'waitingOnYou' => $this->waitingOn($request->user()),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function waitingOn(User $user): array
    {
        return Issue::query()
            ->with(['project:id,key,name', 'status:id,name,category,color'])
            ->where('assignee_id', $user->id)
            ->whereHas('status', fn ($q) => $q->whereNotIn('category', ['done', 'canceled']))
            // Belt and braces: the policy already decides what a client may open, but
            // a dashboard is a listing, and a listing is where a leak goes unnoticed.
            ->unless(
                $user->membershipIn(app(Tenancy::class)->currentOrFail())?->isStaff() ?? false,
                fn ($q) => $q->visibleToClient($user),
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
