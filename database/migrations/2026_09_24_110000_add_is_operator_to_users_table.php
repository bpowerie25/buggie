<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operators, stored, beside the BUGGIE_OPERATORS list rather than instead of it.
     *
     * A self-hosted install with nobody named used to treat "the account with the
     * lowest id" as its operator, decided afresh on every check. That was never
     * written down anywhere, so deleting the first account quietly promoted the
     * second — whoever that happened to be. It also left `buggie:operator` nothing
     * to write to.
     *
     * The backfill keeps exactly what that rule gave on the day of the upgrade: the
     * same person stays operator, and from now on it is a fact rather than a query.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_operator')->default(false);
        });

        if (! config('buggie.hosted') && (array) config('buggie.operators') === []) {
            $first = DB::table('users')->min('id');

            if ($first !== null) {
                DB::table('users')->where('id', $first)->update(['is_operator' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_operator');
        });
    }
};
