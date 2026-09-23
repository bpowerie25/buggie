<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When work on an issue is meant to begin.
 *
 * A real column rather than something derived. The obvious shortcut — start = due
 * minus the estimate — draws a two-hour issue that is due in three weeks as a
 * two-hour sliver, which tells the reader nothing true about when anybody intends to
 * pick it up. An estimate is how long it takes; a start date is when it begins.
 *
 * A date, not a timestamp, for the same reason due_on is one: nobody plans a quarter
 * to the minute, and a timestamp invites a timezone argument about what "starts on
 * Monday" means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->date('start_on')->nullable()->after('due_on');
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn('start_on');
        });
    }
};
