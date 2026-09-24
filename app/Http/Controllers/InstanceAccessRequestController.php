<?php

namespace App\Http\Controllers;

use App\Actions\DecideAccessRequest;
use App\Enums\WorkspaceRole;
use App\Models\AccessRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Operators deciding requests from the Instance screen: the ones for no workspace,
 * which only they can decide, and any other as the fallback.
 *
 * Found across every workspace deliberately, and only after the gate has said this is
 * an operator. Operators invite as admin or member; granting a client particular
 * projects is the workspace's own call, made on its own screen.
 */
class InstanceAccessRequestController extends Controller
{
    public function approve(Request $request, int $id, DecideAccessRequest $action): RedirectResponse
    {
        $accessRequest = $this->find($id);

        $validated = $request->validate([
            'role' => ['required', Rule::in([WorkspaceRole::Admin->value, WorkspaceRole::Member->value])],
            // A request for a new workspace has to be pointed at one.
            'workspace' => [
                $accessRequest->workspace_id === null ? 'required' : 'prohibited',
                'string',
                Rule::exists('workspaces', 'slug')->whereNull('deleted_at'),
            ],
        ]);

        $workspace = $accessRequest->workspace_id === null
            ? Workspace::where('slug', $validated['workspace'])->firstOrFail()
            : $accessRequest->workspace;

        $action->approve($accessRequest, $request->user(), $workspace, WorkspaceRole::from($validated['role']));

        return back()->with('success', "Invitation to {$workspace->name} sent to {$accessRequest->email}.");
    }

    public function decline(Request $request, int $id, DecideAccessRequest $action): RedirectResponse
    {
        $accessRequest = $this->find($id);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $action->decline($accessRequest, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', 'Request declined. Nothing was sent to them.');
    }

    private function find(int $id): AccessRequest
    {
        $this->authorize('viewAll', AccessRequest::class);

        $accessRequest = AccessRequest::query()->acrossAllWorkspaces()->with('workspace')->findOrFail($id);

        $this->authorize('decide', $accessRequest);

        return $accessRequest;
    }
}
