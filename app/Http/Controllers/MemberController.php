<?php

namespace App\Http\Controllers;

use App\Actions\InviteToWorkspace;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\MemberEvent;
use App\Models\Project;
use App\Models\User;
use App\Support\Mail\Deliverability;
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
            // projects eager-loaded: every row reads its grants, and strict mode
            // turns a lazy load inside that loop into a 500 rather than one query per
            // member.
            'members' => $workspace->members()->with('projects')->orderBy('name')->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->pivot->role,
                    // What each granted project lets them see. Keyed by project id so
                    // the editor can show a tier per row.
                    'tiers' => $user->projects->mapWithKeys(
                        fn ($project) => [$project->id => $project->pivot->role],
                    ),
                    // For the workload screen. Staff only; a client's hours are not planned here.
                    'weekly_hours' => $user->pivot->weekly_hours === null ? null : (float) $user->pivot->weekly_hours,
                    'discipline' => $user->pivot->discipline,
                    'is_owner' => $user->id === $workspace->owner_id,
                    'is_you' => $user->id === $request->user()->id,
                    // Ids as well as names: the screen needs to tick boxes, not just
                    // print a list.
                    'projects' => $user->pivot->role === WorkspaceRole::Client->value
                        ? $user->projects()->pluck('projects.id')
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
            // The list staff are given a discipline from, edited on this screen.
            'disciplines' => $workspace->disciplines(),
            'canManage' => $request->user()->can('create', Invitation::class),
            'roles' => array_map(
                fn (WorkspaceRole $role) => ['value' => $role->value, 'label' => $role->label()],
                WorkspaceRole::cases(),
            ),

            // Who changed what a client sees. Only for those who could change it.
            'memberEvents' => $request->user()->can('manageClientAccess', Invitation::class)
                ? MemberEvent::with(['actor:id,name', 'subject:id,name', 'project:id,name'])
                    ->latest('created_at')->limit(20)->get()
                    ->map(fn (MemberEvent $event) => [
                        'id' => $event->id,
                        'actor' => $event->actor?->name,
                        'subject' => $event->subject?->name,
                        'project' => $event->project?->name,
                        'from' => ProjectRole::tryFrom((string) ($event->data['from'] ?? ''))?->label(),
                        'to' => ProjectRole::tryFrom((string) ($event->data['to'] ?? ''))?->label(),
                        'created_at' => $event->created_at->toIso8601String(),
                    ])
                : [],
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
            // project id => tier, for a client. Left out means their own issues only.
            'tiers' => ['array'],
            'tiers.*' => [Rule::in(array_column(ProjectRole::grantable(), 'value'))],
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
            $role === WorkspaceRole::Client ? ($validated['tiers'] ?? []) : [],
        );

        // "Invitation sent" is a lie on an install that cannot send mail, and it is
        // the lie that costs most: the person who invited waits, the person invited
        // never hears, and the invitation link sitting on this very page goes unused
        // because nobody was told to use it.
        if (! app(Deliverability::class)->isConfigured()) {
            return back()->with(
                'success',
                "Invitation created for {$validated['email']}, but this Buggie cannot send email — copy the link below and send it to them yourself.",
            );
        }

        return back()->with('success', "Invitation sent to {$validated['email']}.");
    }

    public function destroy(Invitation $invitation): RedirectResponse
    {
        $this->authorize('delete', $invitation);

        $invitation->delete();

        return back()->with('success', 'Invitation revoked.');
    }

    /**
     * Change which projects a client can see.
     *
     * Grants could be given at invitation and never changed afterwards: adding one
     * meant re-inviting somebody who was already a member, and removing one meant
     * editing the database. A client staying on a project long after the work
     * finished is the common case, and it was the hard one.
     */
    public function grants(Request $request, User $user): RedirectResponse
    {
        $this->authorize('removeMember', Invitation::class);

        $workspace = $this->tenancy->currentOrFail();

        // Scoped to this workspace, so a user id from elsewhere is simply not a
        // member here and cannot be granted anything.
        abort_unless($user->belongsToWorkspace($workspace), 404);

        // Staff already see every project; granting them one would mean nothing, and
        // silently doing nothing is how a screen starts lying.
        abort_unless(
            $user->membershipIn($workspace) === WorkspaceRole::Client,
            422,
            'Only clients are given access to particular projects.',
        );

        $validated = $request->validate([
            'project_ids' => ['present', 'array'],
            // project id => tier. Validated against the tiers that mean something, so
            // "maintainer" cannot be smuggled in from the older vocabulary.
            'tiers' => ['array'],
            'tiers.*' => [Rule::in(array_column(ProjectRole::grantable(), 'value'))],
            'project_ids.*' => [Rule::exists('projects', 'id')
                ->where('workspace_id', $workspace->id)],
        ]);

        // A client with nothing can see nothing, which is a confusing way to leave
        // somebody rather than a security problem — but still worth refusing, exactly
        // as it is refused at invitation.
        if ($validated['project_ids'] === []) {
            return back()->withErrors([
                'project_ids' => 'A client needs at least one project. Remove them instead.',
            ]);
        }

        /*
         * Each grant carries a tier, and an unspecified one keeps what it had.
         *
         * Writing 'client' for every project here would silently demote a client
         * manager every time somebody edited the list of projects they can see —
         * a permission quietly narrowing itself because an unrelated checkbox moved.
         * Anything unrecognised falls to the narrowest tier rather than the last one.
         */
        $existing = $user->projects()->pluck('project_user.role', 'projects.id');

        $sync = [];

        foreach ($validated['project_ids'] as $id) {
            $wanted = $validated['tiers'][$id] ?? $existing[$id] ?? null;

            $sync[$id] = [
                'role' => (ProjectRole::tryFrom((string) $wanted) ?? ProjectRole::Client)->value,
            ];
        }

        // sync, not syncWithoutDetaching: unticking a box has to take access away, or
        // the screen offers a choice it does not honour.
        $user->projects()->sync($sync);

        foreach ($sync as $id => $pivot) {
            if (isset($existing[$id]) && $existing[$id] !== $pivot['role']) {
                MemberEvent::tierChanged($request->user(), $user, Project::findOrFail($id), $existing[$id], $pivot['role']);
            }
        }

        return back()->with('success', "Updated what {$user->name} can see.");
    }

    /**
     * How much of one project a client sees, changed on its own and saved at once.
     *
     * Only for a project they already hold: granting a project is a separate
     * decision, made where the projects are ticked.
     */
    /**
     * A member of staff's weekly hours and discipline, for the workload screen. Set
     * by whoever manages members; a client has neither.
     */
    public function capacity(Request $request, User $user): RedirectResponse
    {
        $this->authorize('create', Invitation::class);

        $workspace = $this->tenancy->currentOrFail();

        abort_unless($user->membershipIn($workspace)?->isStaff(), 404);

        $validated = $request->validate([
            'weekly_hours' => ['nullable', 'numeric', 'min:0', 'max:168'],
            // One of the workspace's list, which is managed on the same screen.
            'discipline' => ['nullable', 'string', Rule::in($workspace->disciplines())],
        ]);

        $workspace->members()->updateExistingPivot($user->id, [
            'weekly_hours' => $validated['weekly_hours'] ?? null,
            'discipline' => trim($validated['discipline'] ?? '') ?: null,
        ]);

        return back()->with('success', "Saved {$user->name}'s hours.");
    }

    public function tier(Request $request, User $user, Project $project): RedirectResponse
    {
        $this->authorize('manageClientAccess', Invitation::class);

        $workspace = $this->tenancy->currentOrFail();

        abort_unless($user->membershipIn($workspace) === WorkspaceRole::Client, 404);

        $validated = $request->validate([
            'tier' => ['required', Rule::in(array_column(ProjectRole::grantable(), 'value'))],
        ]);

        $current = $user->projects()->whereKey($project->id)->value('project_user.role');

        abort_if($current === null, 404);

        if ($current !== $validated['tier']) {
            $user->projects()->updateExistingPivot($project->id, ['role' => $validated['tier']]);

            MemberEvent::tierChanged($request->user(), $user, $project, $current, $validated['tier']);
        }

        $label = ProjectRole::from($validated['tier']) === ProjectRole::ClientManager
            ? 'all client-visible issues'
            : 'only their own issues';

        return back()->with('success', "{$user->name} now sees {$label} on {$project->name}.");
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
