<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual card order, and a cap on work in progress.
     *
     * The board could move a card between columns but not within one, so there was no
     * way to say "this one next" — the question the board exists to answer. Cards sat
     * in `priority DESC, updated_at DESC`, which means the order changed whenever
     * anybody touched anything.
     *
     * Rank is a float rather than an integer position. Inserting between two cards is
     * then one UPDATE of one row instead of renumbering everything below it, which
     * matters because dragging is the one thing people do repeatedly on this screen.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            // Double, not decimal: this is a sort key, never money, and midpointing
            // between two neighbours needs the exponent range more than it needs
            // exact decimal representation.
            $table->double('board_rank')->nullable()->after('priority');
        });

        /*
         * Backfill in the order the board already showed, so nothing appears to move
         * on the deploy that introduces this. Spaced a thousand apart to leave room
         * to insert between any two without immediately needing to renormalise.
         *
         * Partitioned by workspace because that is what rank is scoped to: a board
         * can span projects, and ranks that only made sense within a project would
         * interleave arbitrarily on a workspace-wide board.
         */
        DB::statement(<<<'SQL'
            UPDATE issues SET board_rank = ordered.position * 1000.0
            FROM (
                SELECT id, row_number() OVER (
                    PARTITION BY workspace_id
                    ORDER BY priority DESC, updated_at DESC, id
                ) AS position
                FROM issues
            ) AS ordered
            WHERE issues.id = ordered.id
        SQL);

        Schema::table('issues', function (Blueprint $table) {
            // Sorting a board is this column plus the tie-break, so they belong in
            // one index.
            $table->index(['workspace_id', 'board_rank']);
        });

        Schema::table('statuses', function (Blueprint $table) {
            /*
             * How many issues should sit in this column at once. Null means no limit,
             * which is what every existing status gets — a limit is a decision.
             *
             * Nothing enforces it. A WIP limit that refuses the drop turns a prompt
             * to finish something into an obstacle to be worked around, usually by
             * abandoning the board. It is shown, it is counted, and going over is
             * visible to everybody looking at the same screen, which is the entire
             * mechanism.
             */
            $table->unsignedSmallInteger('wip_limit')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->dropColumn('wip_limit');
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'board_rank']);
            $table->dropColumn('board_rank');
        });
    }
};
