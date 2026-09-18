<?php

namespace App\Models;

use App\Enums\RelationType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['issue_id', 'related_issue_id', 'type'])]
class IssueRelation extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return ['type' => RelationType::class];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function relatedIssue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'related_issue_id');
    }
}
