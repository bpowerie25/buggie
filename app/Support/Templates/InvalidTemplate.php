<?php

namespace App\Support\Templates;

use RuntimeException;

/**
 * A template that cannot safely be applied.
 *
 * Thrown rather than worked around. A project created from a broken template is a
 * project whose board has no column to start in, or two defaults, or a status in a
 * category that does not exist — and every one of those is discovered later, by
 * somebody trying to file a bug, with no clue pointing back at the config file that
 * caused it. Failing here rolls the whole creation back instead.
 */
class InvalidTemplate extends RuntimeException
{
    public static function for(string $key, string $problem): self
    {
        return new self("Project template [{$key}] cannot be applied: {$problem}");
    }

    /**
     * The same check, applied to what a project actually ended up with.
     *
     * Templates are validated on the way in, but copying another project's setup is
     * not a template and still has to leave a workflow somebody can work in.
     */
    public static function workflow(string $problem): self
    {
        return new self("Refusing to create a project without a usable workflow: {$problem}");
    }
}
