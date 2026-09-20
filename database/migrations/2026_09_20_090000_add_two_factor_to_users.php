<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A second factor, on the user rather than the workspace.
     *
     * An account spans workspaces — your own, plus every client workspace you were
     * invited to — so a factor owned by one of them would be a factor the others
     * neither see nor benefit from. There is deliberately no `workspace_id` here, and
     * nothing about this reads a plan: a paywalled second factor is a paywall on not
     * being broken into.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Encrypted by the model's cast, so this holds ciphertext and is sized
            // for it rather than for the ~32 characters of base32 it protects.
            $table->text('two_factor_secret')->nullable()->after('password');

            // Hashes, not the codes. Nothing here can be turned back into something
            // somebody could sign in with.
            $table->json('two_factor_recovery_codes')->nullable()->after('two_factor_secret');

            /*
             * Null while a secret exists but no code has proved it.
             *
             * Enrolment writes the secret first and this second, so the gap between
             * them is a half-finished setup rather than a locked-out account:
             * everything that gates a sign-in asks for this column, never the secret.
             */
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            /*
             * The last time step accepted for this account.
             *
             * A TOTP code is valid for its whole 30-second step, and with drift
             * allowed it is accepted across ninety. Without this, a code read over a
             * shoulder or lifted from a proxy can be replayed for as long as it is
             * still valid, which is the whole window an attacker needs.
             */
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_step',
            ]);
        });
    }
};
