<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The high-water mark for due-date chasing.
     *
     * A due date nobody chases is decoration, and chasing with no record of what has
     * already gone out is how an issue three weeks late produces twenty-one identical
     * emails. One date per issue is enough to make the command idempotent: which days
     * are reminder days is a pure function of today and the due date, so the only
     * thing that has to be remembered is whether today has already been done.
     *
     * A date rather than a timestamp, for the same reason due_on is one: "has this
     * been chased today" is a question about days, and a timestamp invites comparing
     * it to now() and sending a second reminder twenty-five hours later.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->date('due_reminded_on')->nullable()->after('due_on');
        });

        // The chaser sweeps every workspace in one pass and is the only thing that
        // reads due_on across tenants, so the index is on the date alone. Partial,
        // because most issues never get a due date and indexing those nulls buys
        // nothing.
        DB::statement(<<<'SQL'
            CREATE INDEX issues_due_on_index ON issues (due_on)
            WHERE due_on IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS issues_due_on_index');

        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn('due_reminded_on');
        });
    }
};
