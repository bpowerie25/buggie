<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The workspace's list of disciplines — Developer, Designer and so on — that staff
 * are given on the Members screen and grouped by on Workload.
 *
 * Renaming one renames it on everybody who has it, and removing one leaves them with
 * none, so the list and the people on it never disagree.
 */
class DisciplineController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $name = $this->name($request, 'name');

        $this->assertFree($workspace, $name);
        $this->save($workspace, [...$workspace->disciplines(), $name]);

        return back();
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $from = (string) $request->input('from');
        $to = $this->name($request, 'to');
        $list = $workspace->disciplines();

        abort_unless(in_array($from, $list, true), 404);

        if ($from === $to) {
            return back();
        }

        // Case changes are renames of the same thing, not a clash with itself.
        if (mb_strtolower($from) !== mb_strtolower($to)) {
            $this->assertFree($workspace, $to);
        }

        $this->save($workspace, array_map(fn (string $d) => $d === $from ? $to : $d, $list));
        $this->members($workspace, $from, $to);

        return back();
    }

    /** The whole order at once, and exactly the names already there. */
    public function reorder(Request $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $names = array_values((array) $request->input('names', []));
        $current = $workspace->disciplines();

        $sorted = fn (array $a) => collect($a)->sort()->values()->all();

        if ($sorted($names) !== $sorted($current)) {
            throw ValidationException::withMessages(['names' => 'The list changed while you were ordering it. Reload and try again.']);
        }

        $this->save($workspace, $names);

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $name = (string) $request->input('name');

        abort_unless(in_array($name, $workspace->disciplines(), true), 404);

        $this->save($workspace, array_values(array_filter($workspace->disciplines(), fn (string $d) => $d !== $name)));
        $this->members($workspace, $name, null);

        return back()->with('success', "{$name} removed. Anybody who had it now has no discipline.");
    }

    // ------------------------------------------------------------------ internals

    private function workspace(): Workspace
    {
        $this->authorize('create', Invitation::class);

        return $this->tenancy->currentOrFail();
    }

    private function name(Request $request, string $field): string
    {
        return trim($request->validate([$field => ['required', 'string', 'max:40']])[$field]);
    }

    private function assertFree(Workspace $workspace, string $name): void
    {
        $taken = collect($workspace->disciplines())->contains(fn (string $d) => mb_strtolower($d) === mb_strtolower($name));

        if ($taken) {
            throw ValidationException::withMessages(['name' => "There is already a discipline called {$name}."]);
        }
    }

    /** @param array<int, string> $list */
    private function save(Workspace $workspace, array $list): void
    {
        $workspace->forceFill(['settings' => [...($workspace->settings ?? []), 'disciplines' => array_values($list)]])->save();
    }

    private function members(Workspace $workspace, string $from, ?string $to): void
    {
        DB::table('workspace_user')
            ->where('workspace_id', $workspace->id)
            ->where('discipline', $from)
            ->update(['discipline' => $to]);
    }
}
