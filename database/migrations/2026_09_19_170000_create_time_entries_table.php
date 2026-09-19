<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Time logged against an issue.
     *
     * Agencies bill. A tracker an agency uses to run client work and cannot answer
     * "how long did that take" sends them to a spreadsheet, and once the spreadsheet
     * exists it becomes the record and the tracker becomes a formality.
     *
     * Minutes, not decimal hours: 20 minutes is 0.333… hours, so a column of decimals
     * does not add up to what a calculator gives and an invoice built on it is wrong
     * by pence a row. Hours are produced on the way out and never stored.
     */
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();

            /*
             * Who did the work.
             *
             * Kept when the account goes, rather than cascading: the hours were still
             * worked and may already be on an invoice. A deleted person's entries
             * read as "Somebody who has left" instead of vanishing from the totals
             * and silently changing last quarter's numbers.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('minutes');

            // The day the work happened, which is not always the day it was logged —
            // Friday afternoon's work is often entered on Monday.
            $table->date('spent_on');

            $table->string('note', 255)->nullable();

            // Defaults to billable: the common case for an agency, and the entry that
            // should not be billed is the one somebody will remember to untick.
            $table->boolean('billable')->default(true);

            $table->timestamps();

            $table->index(['workspace_id', 'spent_on']);
            $table->index(['issue_id']);
            $table->index(['user_id', 'spent_on']);
        });

        Schema::table('issues', function (Blueprint $table) {
            // What it was expected to take, against what it did. Null means nobody
            // estimated it, which is different from estimating zero.
            $table->unsignedInteger('estimate_minutes')->nullable()->after('due_on');
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn('estimate_minutes');
        });

        Schema::dropIfExists('time_entries');
    }
};
