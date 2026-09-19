<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiTokenController extends Controller
{
    /** The whole vocabulary. Two abilities are enough and easy to reason about. */
    public const ABILITIES = ['read', 'write'];

    public function store(Request $request, Tenancy $tenancy): RedirectResponse
    {
        $workspace = $tenancy->currentOrFail();

        // Staff only. A client's token would be scoped to what they can see, which is
        // coherent, but it is not a thing anybody has asked for and every token is
        // another key to look after.
        abort_unless($request->user()->membershipIn($workspace)?->isStaff() ?? false, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(self::ABILITIES)],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $token = $request->user()->createTokenForWorkspace(
            $workspace,
            $validated['name'],
            $validated['abilities'],
            isset($validated['expires_in_days'])
                ? now()->addDays((int) $validated['expires_in_days'])
                : null,
        );

        // Flashed, and shown once. Storing it in a readable form would make the
        // hashing pointless.
        return back()->with('token', $token->plainTextToken);
    }

    public function destroy(Request $request, ApiToken $apiToken, Tenancy $tenancy): RedirectResponse
    {
        $workspace = $tenancy->currentOrFail();

        // The token must belong to this workspace AND to whoever is asking. An admin
        // revoking a colleague's token is a reasonable feature and is not this one.
        abort_unless(
            $apiToken->workspace_id === $workspace->id
                && $apiToken->tokenable_id === $request->user()->id,
            404,
        );

        $apiToken->delete();

        return back()->with('success', 'Token revoked.');
    }
}
