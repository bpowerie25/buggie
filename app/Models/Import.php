<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'user_id', 'filename', 'path', 'format', 'state', 'total_rows', 'update_existing'])]
class Import extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return ['problems' => 'array', 'finished_at' => 'datetime', 'update_existing' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
