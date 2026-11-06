<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectCategory;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-defined kinds of project, each with a colour. Whoever may create projects
 * may shape the vocabulary they are filed under.
 */
class ProjectCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        ProjectCategory::create([
            ...$this->validated($request),
            'position' => (int) ProjectCategory::max('position') + 1,
        ]);

        return back()->with('success', 'Category added.');
    }

    public function update(Request $request, ProjectCategory $projectCategory): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $projectCategory->update($this->validated($request, $projectCategory));

        return back()->with('success', 'Category updated.');
    }

    public function destroy(ProjectCategory $projectCategory): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $projectCategory->delete();

        return back()->with('success', 'Category deleted. Its projects are now uncategorised.');
    }

    /** @return array{name: string, color: string} */
    private function validated(Request $request, ?ProjectCategory $category = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('project_categories', 'name')
                    ->where('workspace_id', app(Tenancy::class)->id())
                    ->ignore($category?->id),
            ],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
    }
}
