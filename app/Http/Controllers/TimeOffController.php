<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\TimeOff;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Leave and public holidays, for the workload screen.
 *
 * Anybody on the staff books their own leave. Whoever manages members can book
 * anybody's, and only they set public holidays, which take a day away from everyone.
 * Clients have no hours here, so none of this is theirs.
 */
class TimeOffController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->tenancy->currentOrFail();
        abort_unless($request->user()->membershipIn($workspace)?->isStaff() ?? false, 404);

        $validated = $request->validate([
            // Absent or null: a public holiday.
            'user_id' => ['nullable', 'integer'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:80'],
        ]);

        $for = isset($validated['user_id']) ? User::find($validated['user_id']) : null;

        if (isset($validated['user_id']) && ! ($for?->membershipIn($workspace)?->isStaff() ?? false)) {
            throw ValidationException::withMessages(['user_id' => 'Leave is for members of staff.']);
        }

        // Your own leave is yours to book; anything else is managing people.
        if ($for?->id !== $request->user()->id) {
            $this->authorize('create', Invitation::class);
        }

        // A year at most in one go: longer is a typo far more often than a sabbatical.
        if (CarbonImmutable::parse($validated['starts_on'])->diffInDays($validated['ends_on']) > 366) {
            throw ValidationException::withMessages(['ends_on' => 'Time off is booked a year at most at a time.']);
        }

        TimeOff::create([
            'user_id' => $for?->id,
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'],
            'note' => trim($validated['note'] ?? '') ?: null,
            'created_by_id' => $request->user()->id,
        ]);

        return back()->with('success', $for ? "Leave booked for {$for->name}." : 'Public holiday added.');
    }

    public function destroy(Request $request, TimeOff $timeOff): RedirectResponse
    {
        $workspace = $this->tenancy->currentOrFail();
        abort_unless($request->user()->membershipIn($workspace)?->isStaff() ?? false, 404);

        if ($timeOff->user_id !== $request->user()->id) {
            $this->authorize('create', Invitation::class);
        }

        $timeOff->delete();

        return back()->with('success', 'Time off removed.');
    }
}
