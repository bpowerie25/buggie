<?php

namespace App\Actions;

use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Notifications\WorkspaceInvitation;
use App\Support\Billing\LimitExceeded;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class InviteToWorkspace
{
    public function __construct(private Tenancy $tenancy) {}

    /**
     * @param  array<int, int>  $projectIds
     * @param  array<int|string, string>  $projectRoles  project id => tier; a project left out gets the narrowest
     */
    public function handle(string $email, WorkspaceRole $role, array $projectIds, User $invitedBy, array $projectRoles = []): Invitation
    {
        $email = strtolower(trim($email));
        $workspace = $this->tenancy->currentOrFail();

        // Counted against pending invitations too: a promised seat is a taken seat,
        // and otherwise a workspace could invite its way past the limit and only
        // discover it when people tried to accept.
        $promised = Invitation::pending()->where('email', '!=', $email)->count();

        if (! $workspace->isWithinLimit('members', 1 + $promised)) {
            throw LimitExceeded::members($workspace->plan()->limit('members'));
        }

        // Only for projects actually granted, and only tiers that mean something.
        $projectRoles = collect($projectRoles)
            ->only($projectIds)
            ->filter(fn ($tier) => in_array($tier, array_column(ProjectRole::grantable(), 'value'), true))
            ->all();

        return DB::transaction(function () use ($email, $role, $projectIds, $invitedBy, $projectRoles) {
            // Re-inviting refreshes the existing invitation rather than failing on the
            // unique index or leaving two live tokens for one address.
            $invitation = Invitation::where('email', $email)->first();

            if ($invitation) {
                $invitation->forceFill([
                    'role' => $role->value,
                    'project_ids' => $projectIds,
                    'project_roles' => $projectRoles,
                    'invited_by_id' => $invitedBy->id,
                    'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
                    'accepted_at' => null,
                ])->save();
            } else {
                $invitation = Invitation::create([
                    'email' => $email,
                    'role' => $role->value,
                    'project_ids' => $projectIds,
                    'project_roles' => $projectRoles,
                    'invited_by_id' => $invitedBy->id,
                ]);
            }

            Notification::route('mail', $email)
                ->notify(new WorkspaceInvitation($invitation->fresh()));

            return $invitation;
        });
    }

    /**
     * Turn an invitation into a membership.
     *
     * The email is not re-checked against the user's own: the token is the credential,
     * and someone may well sign in with a different address than they were written to.
     */
    public function accept(Invitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user) {
            $workspace = $invitation->workspace;

            if (! $user->belongsToWorkspace($workspace)) {
                $workspace->members()->attach($user->id, [
                    'role' => $invitation->role->value,
                    'invited_by_id' => $invitation->invited_by_id,
                    'invited_at' => $invitation->created_at,
                    'joined_at' => now(),
                ]);
            }

            // Clients are scoped to named projects; staff reach everything.
            if ($invitation->role === WorkspaceRole::Client) {
                $projects = Project::whereIn('id', $invitation->project_ids ?? [])->get();

                foreach ($projects as $project) {
                    $project->clients()->syncWithoutDetaching([$user->id => [
                        'role' => $invitation->project_roles[$project->id] ?? ProjectRole::Client->value,
                    ]]);
                }
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            // The invitation went to this address and is only accepted by it, so
            // following the link proves the address as well as a verification link.
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            $user->forceFill(['last_workspace_id' => $workspace->id])->save();
        });
    }
}
