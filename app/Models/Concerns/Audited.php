<?php

namespace App\Models\Concerns;

use App\Support\Audit\Audit;

/**
 * Writes created / updated / deleted to the audit trail.
 *
 * A model can list attributes the trail must not copy in `$auditExclude` (a comment's
 * text, say); hidden attributes and anything secret-shaped are always left out.
 */
trait Audited
{
    public static function bootAudited(): void
    {
        $name = \Illuminate\Support\Str::snake(class_basename(static::class));

        static::created(fn ($model) => Audit::log("{$name}.created", $model, Audit::snapshot($model)));

        static::updated(function ($model) use ($name) {
            $changes = Audit::diff($model);

            if ($changes !== []) {
                Audit::log("{$name}.updated", $model, $changes);
            }
        });

        static::deleted(fn ($model) => Audit::log("{$name}.deleted", $model));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($model) => Audit::log("{$name}.restored", $model));
        }
    }
}
