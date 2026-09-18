<?php

namespace App\Http\Controllers;

use App\Actions\InviteToWorkspace;
use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Invitation::class);

        $workspace = $this->tenancy->currentOrFail();

        return Inertia::render('settings/members', [
            'members' => $workspace->members()->orderBy('name')->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->pivot->role,
                    'is_owner' => $user->id === $workspace->owner_id,
                    'is_you' => $user->id === $request->user()->id,
                    'projects' => $user->pivot->role === WorkspaceRole::Client->value
                        ? $user->projects()->pluck('projects.name')
                        : [],
                ]),
            'invitations' => Invitation::pending()->latest()->get()
                ->map(fn (Invitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                    'url' => $invitation->url(),
                ]),
            'projects' => Project::active()->orderBy('name')->get(['id', 'name', 'key']),
            'roles' => array_map(
                fn (WorkspaceRole $role) => ['value' => $role->value, 'label' => $role->label()],
                WorkspaceRole::cases(),
            ),
        ]);
    }

    public function store(Request $request, InviteToWorkspace $action): RedirectResponse
    {
        $this->authorize('create', Invitation::class);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', new Enum(WorkspaceRole::class)],
            'project_ids' => ['array'],
            'project_ids.*' => [Rule::exists('projects', 'id')
                ->where('workspace_id', $this->tenancy->id())],
        ]);

        $role = WorkspaceRole::from($validated['role']);

        // Only an owner can make another owner.
        if ($role === WorkspaceRole::Owner) {
            abort_unless(
                $request->user()->id === $this->tenancy->currentOrFail()->owner_id,
                403,
                'Only the workspace owner can invite another owner.',
            );
        }

        // A client with no projects can see nothing, which is a confusing way to
        // arrive rather than a security problem — but still worth refusing.
        if ($role === WorkspaceRole::Client && ($validated['project_ids'] ?? []) === []) {
            return back()->withErrors([
                'project_ids' => 'Choose at least one project for a client to see.',
            ]);
        }

        $action->handle(
            $validated['email'],
            $role,
            $validated['project_ids'] ?? [],
            $request->user(),
        );

        return back()->with('success', "Invitation sent to {$validated['email']}.");
    }

    public function destroy(Invitation $invitation): RedirectResponse
    {
        $this->authorize('delete', $invitation);

        $invitation->delete();

        return back()->with('success', 'Invitation revoked.');
    }

    /** Remove someone from the workspace. */
    public function remove(Request $request, User $user): RedirectResponse
    {
        $this->authorize('removeMember', Invitation::class);

        $workspace = $this->tenancy->currentOrFail();

        // The owner is the last line of control over a workspace.
        abort_if($user->id === $workspace->owner_id, 403, 'The workspace owner cannot be removed.');

        $workspace->members()->detach($user->id);
        $user->projects()->detach();

        return back()->with('success', "{$user->name} removed from the workspace.");
    }
}
