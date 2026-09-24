<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The issue picker's suggestions: linking, marking a duplicate, merging a report.
 *
 * Nobody remembers issue keys. They remember "the checkout one", so this matches as
 * they type: a key or the start of one, or every word somewhere in the title. Before
 * they type anything it suggests recent work from the same project, which is nearly
 * always where the issue they mean is.
 *
 * Staff only, like every action that uses it. Answering a client would be a search
 * box over every issue in the workspace.
 */
class IssueLookupController extends Controller
{
    private const LIMIT = 20;

    public function __invoke(Request $request, Tenancy $tenancy): JsonResponse
    {
        abort_unless($request->user()->membershipIn($tenancy->currentOrFail())?->isStaff(), 404);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            // Suggested first, not the only place looked.
            'project' => ['nullable', 'string', 'max:100'],
            // The issue doing the linking: never a sensible answer.
            'exclude' => ['nullable', 'string', 'max:40'],
        ]);

        $q = trim($validated['q'] ?? '');
        $projectId = isset($validated['project'])
            ? Project::where('slug', $validated['project'])->value('id')
            : null;

        $issues = Issue::query()
            ->with(['project:id,key,name', 'status:id,name,category'])
            ->when($validated['exclude'] ?? null, fn (Builder $query, string $key) => $query->where('key', '!=', strtoupper($key)))
            ->when($q !== '', fn (Builder $query) => $this->matching($query, $q))
            // Open work first: linking to or merging into something closed is the
            // exception. Then the project being worked in, then recent.
            ->orderByRaw('(SELECT category IN (?, ?) FROM statuses WHERE statuses.id = issues.status_id)', ['done', 'canceled'])
            ->when($projectId, fn (Builder $query) => $query->orderByRaw('issues.project_id = ? DESC', [$projectId]))
            ->orderByDesc('issues.updated_at')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'issues' => $issues->map(fn (Issue $issue) => [
                'key' => $issue->key,
                'title' => $issue->title,
                'project' => $issue->project->name,
                'status' => $issue->status->name,
                'open' => $issue->status->category->isOpen(),
            ]),
        ]);
    }

    /**
     * A key or its start ("KD-1" finds KD-1 and KD-12), or every word in the title.
     * Partial words match, since the list updates as they type: "chec" is on its way
     * to "checkout". The full-text index matches whole words only.
     */
    private function matching(Builder $query, string $q): void
    {
        $like = fn (string $term) => '%'.addcslashes($term, '%_\\').'%';
        $words = preg_split('/\s+/', $q) ?: [];

        $query->where(function (Builder $query) use ($q, $words, $like) {
            if (preg_match('/^[A-Z][A-Z0-9]*-\d*$/i', $q)) {
                $query->orWhere('key', 'like', addcslashes(strtoupper($q), '%_\\').'%');
            }

            $query->orWhere(function (Builder $query) use ($words, $like) {
                foreach ($words as $word) {
                    $query->where('title', 'ilike', $like($word));
                }
            });
        });
    }
}
