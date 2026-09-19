<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * How a project presents itself to somebody outside the team.
 *
 * The logo is served through here rather than from public storage: it is shown on a
 * portal page reachable by anybody holding a token, and that is a small, deliberate
 * audience rather than the whole internet with a directory listing.
 */
class BrandingController extends Controller
{
    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:60'],
            // A colour, not arbitrary CSS: this value ends up inside a style
            // attribute, and "anything goes" there is how a stylesheet becomes a
            // script tag.
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,webp,svg', 'max:512'],
            'remove_logo' => ['boolean'],
        ]);

        if ($request->boolean('remove_logo') && $project->brand_logo_path) {
            Storage::disk('local')->delete($project->brand_logo_path);

            $project->forceFill(['brand_logo_path' => null])->save();
        }

        if ($request->hasFile('logo')) {
            // The old one goes: nobody needs the logo a client used two rebrands ago.
            if ($project->brand_logo_path) {
                Storage::disk('local')->delete($project->brand_logo_path);
            }

            $project->forceFill([
                'brand_logo_path' => $request->file('logo')->store(
                    "workspaces/{$project->workspace_id}/branding",
                    'local',
                ),
            ])->save();
        }

        $project->update([
            'brand_name' => $validated['brand_name'] ?: null,
            'brand_color' => $validated['brand_color'] ?: null,
        ]);

        return back()->with('success', 'Branding saved.');
    }

    /**
     * Public, because the portal is reached by people with no account.
     *
     * Taken by id and resolved without the workspace scope: this is served from the
     * central domain, where no tenant is bound, and slugs are unique per workspace
     * rather than globally — two agencies may each have a project called "website".
     *
     * An id is guessable, which reveals only that a project exists and has a logo.
     * The logo is already shown to anybody holding a portal link, so that is not a
     * secret worth building machinery around.
     */
    public function logo(int $project): Response
    {
        $model = Project::withoutGlobalScopes()->find($project);

        abort_unless($model?->brand_logo_path, 404);

        abort_unless(Storage::disk('local')->exists($model->brand_logo_path), 404);

        return response(Storage::disk('local')->get($model->brand_logo_path), 200, [
            'Content-Type' => Storage::disk('local')->mimeType($model->brand_logo_path),
            // An uploaded SVG is a document that can carry script, so it is never
            // rendered as one by this response.
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
