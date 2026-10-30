<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\Tenancy\ProjectScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProjectScopeController extends Controller
{
    public function update(Request $request, ProjectScope $scope): RedirectResponse
    {
        $slug = $request->string('project')->toString();

        // An empty value is "all projects". A slug the person cannot see is a 404,
        // the same as asking for the project directly: no hint that it exists.
        $scope->set($slug === ''
            ? null
            : Project::active()->visibleTo($request->user())->where('slug', $slug)->firstOrFail());

        return back();
    }
}
