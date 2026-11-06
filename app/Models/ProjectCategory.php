<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of project (Design, Development…) with a colour, defined by the workspace's admins. */
#[Fillable(['name', 'color', 'position'])]
class ProjectCategory extends Model
{
    use BelongsToWorkspace, \App\Models\Concerns\Audited;

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return array{id: int, name: string, color: string} */
    public function summary(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'color' => $this->color];
    }
}
