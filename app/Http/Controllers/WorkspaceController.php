<?php

namespace App\Http\Controllers;

use App\Actions\CreateWorkspace;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Lives on the central domain: picking a workspace, and creating the first one.
 */
class WorkspaceController extends Controller
{
    public function index(Request $request): Response|SymfonyResponse
    {
        $workspaces = $request->user()->workspaces()->orderBy('name')->get();

        if ($workspaces->count() === 1) {
            return redirect_across_domains(workspace_url($workspaces->first()->slug));
        }

        return Inertia::render('workspaces/index', [
            'workspaces' => $workspaces->map(fn (Workspace $w) => [
                'name' => $w->name,
                'slug' => $w->slug,
                'url' => workspace_url($w->slug),
                'role' => $w->pivot->role,
            ]),
            // Offered only to those who may: on an invite-only install, a button that
            // leads to a refusal is a button that should not be there.
            'canCreate' => $request->user()->can('create', Workspace::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Workspace::class);

        return Inertia::render('workspaces/create', [
            'domain' => config('buggie.domain'),
        ]);
    }

    public function store(StoreWorkspaceRequest $request, CreateWorkspace $action): SymfonyResponse
    {
        $this->authorize('create', Workspace::class);

        $workspace = $action->handle(
            $request->user(),
            $request->string('name')->toString(),
            $request->string('slug')->toString(),
        );

        return redirect_across_domains(workspace_url($workspace->slug));
    }
}
