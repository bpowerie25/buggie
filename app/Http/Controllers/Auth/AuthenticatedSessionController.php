<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/login', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): SymfonyResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        // intended() may hold a URL on any workspace subdomain.
        return redirect_across_domains(
            $request->session()->pull('url.intended', $this->destinationFor($request)),
        );
    }

    public function destroy(Request $request): SymfonyResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect_across_domains(central_url('/'));
    }

    /** Drop the user back into their last workspace, or the picker if they have none. */
    protected function destinationFor(Request $request): string
    {
        $user = $request->user();

        $workspace = $user->last_workspace_id
            ? Workspace::find($user->last_workspace_id)
            : $user->workspaces()->orderBy('name')->first();

        if ($workspace && $user->belongsToWorkspace($workspace)) {
            return workspace_url($workspace->slug);
        }

        return route('workspaces.index');
    }
}
