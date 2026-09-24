<?php

namespace App\Support\Operators;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
            ->where('is_operator', true)
            ->when($named !== [], fn ($q) => $q->orWhereIn(DB::raw('lower(email)'), $named))
            ->orderBy('id')
            ->get();
    }
}
