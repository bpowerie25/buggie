<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Accounts that existed before email addresses were verified count as verified.
     *
     * Verification now gates creating a workspace on the hosted service, and being an
     * operator through BUGGIE_OPERATORS. Leaving existing accounts unverified would
     * lock operators out of their own install on the next deploy, for no gain: the
     * risk it closes — somebody registering an address that is not theirs — is about
     * accounts made from now on.
     */
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Not reversible in any useful sense: which were backfilled is not recorded.
    }
};
