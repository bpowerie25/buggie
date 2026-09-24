<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Models\AccessRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AccessRequested;
use App\Support\Operators\Operators;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Collection;

/**
 * Record somebody asking to be let in, and tell whoever can let them.
 *
 * Several requests are dropped without a word: from somebody who is already a member,
 * who already holds an invitation, who already has one pending for the same place,
 * or who has reached the cap on pending requests. The form answers all of them
 * exactly as it answers a request that was kept, because a different answer would
 * make it a free way to learn who belongs where.
 */
class RequestAccess
{
    /** Across the whole install, so one address cannot fill every admin's inbox. */
    public const MAX_PENDING_PER_EMAIL = 3;

    public function __construct(private Tenancy $tenancy, private Operators $operators) {}

    /** @param array{name: string, email: string, organisation?: ?string, message?: ?string} $data */
    public function handle(?Workspace $workspace, array $data): ?AccessRequest
    {
        $email = strtolower(trim($data['email']));

        if ($this->shouldIgnore($workspace, $email)) {
            return null;
        }

        $attributes = [
            'name' => trim($data['name']),
            'email' => $email,
            'organisation' => $workspace === null ? ($data['organisation'] ?? null) : null,
            'message' => ($data['message'] ?? null) ?: null,
        ];

        $request = $workspace !== null
            ? $this->tenancy->run($workspace, fn () => AccessRequest::create($attributes))
            : AccessRequest::forOperators($attributes);

        $this->notify($request, $workspace);

        return $request;
    }

    private function shouldIgnore(?Workspace $workspace, string $email): bool
    {
        $pending = fn () => AccessRequest::query()->acrossAllWorkspaces()->pending()->where('email', $email);

        if ($pending()->count() >= self::MAX_PENDING_PER_EMAIL) {
            return true;
        }

        if ($pending()->where('workspace_id', $workspace?->id)->exists()) {
            return true;
        }

        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($workspace === null) {
            // Asking for a new workspace while already in one is a conversation with
            // the operator, not a form.
            return $user !== null && $user->workspaces()->exists();
        }

        if ($user !== null && $user->belongsToWorkspace($workspace)) {
            return true;
        }

        return Invitation::query()->acrossAllWorkspaces()
            ->where('workspace_id', $workspace->id)
            ->where('email', $email)
            ->pending()
            ->exists();
    }

    /**
     * The workspace's owners and admins first — the people who can already invite.
     * Operators only when the request is for no workspace, or the workspace somehow
     * has nobody who could act on it.
     */
    private function notify(AccessRequest $request, ?Workspace $workspace): void
    {
        $admins = $workspace === null ? collect() : $workspace->members()
            ->wherePivotIn('role', [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value])
            ->get();

        if ($admins->isNotEmpty()) {
            $this->send($admins, $request, $workspace, workspace_url($workspace->slug, 'settings/access-requests'));

            return;
        }

        foreach ($this->operators->all() as $operator) {
            $this->send(collect([$operator]), $request, $workspace, $this->instanceUrlFor($operator));
        }
    }

    /** @param Collection<int, User> $recipients */
    private function send(Collection $recipients, AccessRequest $request, ?Workspace $workspace, string $url): void
    {
        foreach ($recipients as $recipient) {
            $recipient->notify(new AccessRequested($request->name, $request->email, $workspace?->name, $url));
        }
    }

    /**
     * The Instance screen lives on a workspace domain, so an operator is sent to it on
     * one of their own.
     */
    private function instanceUrlFor(User $operator): string
    {
        $workspace = $operator->workspaces()->orderBy('name')->first();

        return $workspace
            ? workspace_url($workspace->slug, 'settings/instance#access-requests')
            : central_url('/');
    }
}
