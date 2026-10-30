<?php

namespace App\Support\Tenancy;

use App\Models\Project;
use App\Support\Issues\IssueQuery;
use Illuminate\Http\Request;

/**
 * "Which site am I working on", chosen once from the sidebar.
 *
 * A view filter and nothing more: it narrows what the dashboard, issue list, board,
 * triage inbox, timeline and insights show, and grants nothing. Access is still
 * `Project::visibleTo()` and the issue policies. It is re-checked against that on every
 * read, so a project the person lost access to since choosing it simply stops applying.
 *
 * It is kept in the session, per workspace, rather than in the query string, because
 * the point is that it follows you from page to page. An explicit `project:` in the
 * query wins over it: somebody who typed a project meant it, and a scope that quietly
 * emptied the list would read as a bug.
 */
class ProjectScope
{
    public function __construct(private Request $request, private Tenancy $tenancy) {}

    public function current(): ?Project
    {
        // Memoised on the request, not the instance: this is built fresh wherever it is
        // injected, and the shared props and the controller would each pay for the lookup.
        if ($this->request->attributes->has('project_scope')) {
            return $this->request->attributes->get('project_scope');
        }

        $project = $this->resolve();
        $this->request->attributes->set('project_scope', $project);

        return $project;
    }

    private function resolve(): ?Project
    {
        $user = $this->request->user();
        $workspace = $this->tenancy->current();

        if (! $user || ! $workspace || ! $this->request->hasSession()) {
            return null;
        }

        $slug = $this->request->session()->get($this->key($workspace->getKey()));

        return is_string($slug)
            ? Project::active()->visibleTo($user)->where('slug', $slug)->first()
            : null;
    }

    public function set(?Project $project): void
    {
        $key = $this->key($this->tenancy->currentOrFail()->getKey());

        $project
            ? $this->request->session()->put($key, $project->slug)
            : $this->request->session()->forget($key);

        $this->request->attributes->remove('project_scope');
    }

    /** Whether the scope narrows this query, i.e. one is set and the query did not choose a project itself. */
    public function appliesTo(IssueQuery $query): bool
    {
        return $this->current() !== null && ! $query->has('project');
    }

    public function applyTo(IssueQuery $query): IssueQuery
    {
        return $this->appliesTo($query) ? $query->with('project', $this->current()->slug) : $query;
    }

    private function key(int|string $workspaceId): string
    {
        return "project_scope_{$workspaceId}";
    }
}
