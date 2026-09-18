<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\Tenancy\Tenancy;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Tenancy $tenancy): Response
    {
        return Inertia::render('dashboard', [
            'workspace' => [
                'name' => $tenancy->currentOrFail()->name,
                'trial_ends_at' => $tenancy->currentOrFail()->trial_ends_at?->toDateString(),
            ],
            'projects' => Project::active()
                ->withCount('statuses')
                ->orderBy('name')
                ->get()
                ->map(fn (Project $p) => [
                    'name' => $p->name,
                    'key' => $p->key,
                    'slug' => $p->slug,
                    'description' => $p->description,
                ]),
        ]);
    }
}
