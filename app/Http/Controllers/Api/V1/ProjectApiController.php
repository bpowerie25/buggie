<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        // visibleTo, not all(): a client's token sees their own projects, and the
        // names of an agency's other clients are not theirs to read.
        $projects = Project::active()
            ->visibleTo($request->user())
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $projects->map(fn (Project $project) => [
                'key' => $project->key,
                'slug' => $project->slug,
                'name' => $project->name,
                'description' => $project->description,
            ])->values(),
        ]);
    }
}
