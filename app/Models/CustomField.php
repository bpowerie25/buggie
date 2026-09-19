<?php

namespace App\Models;

use App\Enums\CustomFieldType;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['project_id', 'name', 'key', 'type', 'options', 'required', 'visible_to_client', 'position'])]
class CustomField extends Model
{
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
            'options' => 'array',
            'required' => 'boolean',
            'visible_to_client' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    public function scopeInOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /** What a client is allowed to see. */
    public function scopeVisibleToClient(Builder $query): Builder
    {
        return $query->where('visible_to_client', true);
    }

    /**
     * A stable key from a name.
     *
     * Lowercase and underscored so it is typeable in the query language without
     * quoting: `field:client_ref=AC-1120`.
     */
    public static function keyFrom(string $name): string
    {
        $key = Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(60, '')->value();

        // A field named entirely in punctuation or a non-Latin script would otherwise
        // key to the empty string, which collides with the next one.
        return $key === '' ? 'field_'.Str::lower(Str::random(6)) : $key;
    }
}
