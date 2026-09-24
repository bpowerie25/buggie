<?php

namespace App\Support\Registration;

use App\Enums\WorkspaceRole;
use App\Models\AccessRequest;
use Illuminate\Support\Collection;

/**
 * How a request is shown, shared by the workspace screen and the Instance screen so
 * the two cannot drift into describing the same row differently.
 */
final class AccessRequestList
{
    /**
     * @param  Collection<int, AccessRequest>  $requests
     * @return array<int, array<string, mixed>>
     */
    public static function present(Collection $requests, bool $withWorkspace = false): array
    {
        return $requests->map(fn (AccessRequest $request) => [
            'id' => $request->id,
            'name' => $request->name,
            'email' => $request->email,
            'organisation' => $request->organisation,
            'message' => $request->message,
            'status' => $request->status->value,
            'created_at' => $request->created_at?->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'decided_by' => $request->decidedBy?->name,
            'decline_reason' => $request->decline_reason,
            ...($withWorkspace ? [
                'workspace' => $request->workspace ? [
                    'name' => $request->workspace->name,
                    'slug' => $request->workspace->slug,
                ] : null,
            ] : []),
        ])->values()->all();
    }

    /**
     * @param  array<int, WorkspaceRole>  $roles
     * @return array<int, array{value: string, label: string}>
     */
    public static function roles(array $roles): array
    {
        return array_map(fn (WorkspaceRole $role) => ['value' => $role->value, 'label' => $role->label()], $roles);
    }
}
