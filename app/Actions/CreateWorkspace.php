<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

class CreateWorkspace
{
    public function __construct(private Tenancy $tenancy) {}

    public function handle(User $owner, string $name, string $slug): Workspace
    {
        return DB::transaction(function () use ($owner, $name, $slug) {
            $workspace = Workspace::create([
                'name' => $name,
                'slug' => $slug,
                'owner_id' => $owner->id,
                'trial_ends_at' => now()->addDays(14),
            ]);

            $workspace->members()->attach($owner->id, [
                'role' => WorkspaceRole::Owner->value,
                'joined_at' => now(),
            ]);

            $owner->forceFill(['last_workspace_id' => $workspace->id])->save();

            return $workspace;
        });
    }
}
