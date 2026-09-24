<?php

namespace App\Support\Operators;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The people who run this install, as accounts rather than as a gate check.
 *
 * The gate answers "is this person an operator"; this answers "who are they", for
 * the things that have to be sent to somebody.
 */
class Operators
{
    /** @return Collection<int, User> */
    public function all(): Collection
    {
        $named = array_map('strtolower', (array) config('buggie.operators'));

        return User::query()
            ->when(
                $named !== [],
                fn ($q) => $q->whereIn(DB::raw('lower(email)'), $named),
                // Nobody named: the gate's own fallback decides, and it is cheap to
                // ask of the one account it could be.
                fn ($q) => $q->orderBy('id')->limit(1),
            )
            ->get()
            ->filter(fn (User $user) => Gate::forUser($user)->allows('operate'))
            ->values();
    }
}
