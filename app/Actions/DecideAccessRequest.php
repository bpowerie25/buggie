<?php

namespace App\Actions;

use App\Enums\AccessRequestStatus;
use App\Enums\WorkspaceRole;
use App\Models\AccessRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Approve or decline a request to be let in.
 *
 * Approving is an ordinary invitation, sent by InviteToWorkspace exactly as the
 * members screen sends one — there is no second way into a workspace. Declining is
 * recorded and nothing is sent: a form anybody can fill in must not become a way to
 * make this server write to an address of their choosing.
 *
 * Both lock the row, so two admins deciding at once cannot both win.
 */
class DecideAccessRequest
{
    public function __construct(private Tenancy $tenancy, private InviteToWorkspace $invite) {}

    /** @param array<int, int> $projectIds */
    public function approve(
        AccessRequest $request,
        User $decider,
        Workspace $workspace,
        WorkspaceRole $role,
        array $projectIds = [],
    ): Invitation {
        return DB::transaction(function () use ($request, $decider, $workspace, $role, $projectIds) {
            $locked = $this->lock($request);

            $invitation = $this->tenancy->run(
                $workspace,
                fn () => $this->invite->handle($locked->email, $role, $projectIds, $decider),
            );

            $locked->forceFill([
                'status' => AccessRequestStatus::Approved->value,
                'decided_by_id' => $decider->id,
                'decided_at' => now(),
                'invitation_id' => $invitation->id,
            ])->save();

            return $invitation;
        });
    }

    public function decline(AccessRequest $request, User $decider, ?string $reason = null): void
    {
        DB::transaction(function () use ($request, $decider, $reason) {
            $this->lock($request)->forceFill([
                'status' => AccessRequestStatus::Declined->value,
                'decided_by_id' => $decider->id,
                'decided_at' => now(),
                'decline_reason' => ($reason = trim((string) $reason)) === '' ? null : $reason,
            ])->save();
        });
    }

    private function lock(AccessRequest $request): AccessRequest
    {
        $locked = AccessRequest::query()->acrossAllWorkspaces()->lockForUpdate()->findOrFail($request->id);

        if (! $locked->isPending()) {
            throw new HttpException(409, 'Somebody has already decided this request.');
        }

        return $locked;
    }
}
