<?php

namespace App\Http\Controllers;

use App\Actions\DecideAccessRequest;
use App\Enums\WorkspaceRole;
use App\Models\AccessRequest;
use App\Models\Project;
use App\Support\Registration\AccessRequestList;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A workspace's own requests, for the people who can already invite to it.
 *
 * Reached through the tenant scope, so another workspace's request is not found
 * rather than refused, and a request for no workspace is never here at all.
 */
class WorkspaceAccessRequestController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function index(): Response
    {
        $this->authorize('viewAny', AccessRequest::class);

        return Inertia::render('settings/access-requests', [
            'requests' => AccessRequestList::present(
                AccessRequest::query()->with('decidedBy:id,name')->latest()->limit(100)->get(),
            ),
            'projects' => Project::active()->orderBy('name')->get(['id', 'name', 'key']),
            'roles' => AccessRequestList::roles([WorkspaceRole::Admin, WorkspaceRole::Member, WorkspaceRole::Client]),
        ]);
    }

    public function approve(Request $request, AccessRequest $accessRequest, DecideAccessRequest $action): RedirectResponse
    {
        $this->authorize('decide', $accessRequest);

        $validated = $request->validate([
            'role' => ['required', Rule::in([WorkspaceRole::Admin->value, WorkspaceRole::Member->value, WorkspaceRole::Client->value])],
            'project_ids' => ['array'],
            'project_ids.*' => [Rule::exists('projects', 'id')->where('workspace_id', $this->tenancy->id())],
        ]);

        $role = WorkspaceRole::from($validated['role']);

        // As on the members screen: a client who can see no project arrives to nothing.
        if ($role === WorkspaceRole::Client && ($validated['project_ids'] ?? []) === []) {
            return back()->withErrors(['project_ids' => 'Choose at least one project for a client to see.']);
        }

        $action->approve(
            $accessRequest,
            $request->user(),
            $this->tenancy->currentOrFail(),
            $role,
            $role === WorkspaceRole::Client ? $validated['project_ids'] : [],
        );

        return back()->with('success', "Invitation sent to {$accessRequest->email}.");
    }

    public function decline(Request $request, AccessRequest $accessRequest, DecideAccessRequest $action): RedirectResponse
    {
        $this->authorize('decide', $accessRequest);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $action->decline($accessRequest, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', 'Request declined. Nothing was sent to them.');
    }
}
