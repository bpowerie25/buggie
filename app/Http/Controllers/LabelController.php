<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLabelRequest;
use App\Models\Label;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LabelController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Label::class);

        return Inertia::render('labels/index', [
            'labels' => Label::withCount('issues')->orderBy('name')->get()
                ->map(fn (Label $label) => [
                    'id' => $label->id,
                    'name' => $label->name,
                    'color' => $label->color,
                    'description' => $label->description,
                    'issues_count' => $label->issues_count,
                ]),
        ]);
    }

    public function store(StoreLabelRequest $request): RedirectResponse
    {
        $this->authorize('create', Label::class);

        Label::create($request->validated());

        return back()->with('success', 'Label created.');
    }

    public function update(StoreLabelRequest $request, Label $label): RedirectResponse
    {
        $this->authorize('update', $label);

        $label->update($request->validated());

        return back()->with('success', 'Label updated.');
    }

    public function destroy(Label $label): RedirectResponse
    {
        $this->authorize('delete', $label);

        $label->delete();

        return back()->with('success', 'Label deleted.');
    }
}
