<?php

namespace App\Support\Tenancy;

use App\Models\Workspace;

/**
 * Holds the workspace for the current request, job or command.
 *
 * Bound as a singleton. Everything tenant-owned reads from here via WorkspaceScope,
 * so this is the single place that decides which customer's data is visible.
 */
class Tenancy
{
    protected ?Workspace $workspace = null;

    /**
     * In strict mode, querying a tenant model with no workspace resolved throws.
     * Enabled for the whole web request lifecycle; left off for console and seeders,
     * where cross-workspace queries are legitimate.
     */
    protected bool $strict = false;

    public function set(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function current(): ?Workspace
    {
        return $this->workspace;
    }

    public function currentOrFail(): Workspace
    {
        return $this->workspace ?? throw MissingWorkspaceContext::for(Workspace::class);
    }

    public function id(): ?int
    {
        return $this->workspace?->id;
    }

    public function check(): bool
    {
        return $this->workspace !== null;
    }

    public function forget(): void
    {
        $this->workspace = null;
    }

    public function strict(bool $strict = true): void
    {
        $this->strict = $strict;
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }

    /**
     * Run a callback inside a workspace, restoring whatever was bound before.
     * Use this in queued jobs, tests and anywhere crossing a tenant boundary.
     *
     * @template TReturn
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(Workspace $workspace, \Closure $callback): mixed
    {
        $previous = $this->workspace;
        $previousStrict = $this->strict;

        $this->workspace = $workspace;
        $this->strict = true;

        try {
            return $callback();
        } finally {
            $this->workspace = $previous;
            $this->strict = $previousStrict;
        }
    }

    /** Escape hatch for deliberate cross-workspace work (admin tooling, reports). */
    public function withoutTenancy(\Closure $callback): mixed
    {
        $previous = $this->workspace;
        $previousStrict = $this->strict;

        $this->workspace = null;
        $this->strict = false;

        try {
            return $callback();
        } finally {
            $this->workspace = $previous;
            $this->strict = $previousStrict;
        }
    }
}
