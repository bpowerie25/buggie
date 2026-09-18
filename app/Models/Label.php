<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'color', 'description'])]
class Label extends Model
{
    use BelongsToWorkspace, HasFactory;

    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class);
    }
}
