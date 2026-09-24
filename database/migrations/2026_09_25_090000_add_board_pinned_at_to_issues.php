<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A card that stays on the board whatever the filter says — a standing release
     * checklist, the one thing everybody must see at stand-up. A timestamp rather
     * than a flag, so the board can show the most recently pinned first if it ever
     * needs to, and so "who pinned this and when" has half an answer.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->timestamp('board_pinned_at')->nullable();
            $table->index(['workspace_id', 'board_pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'board_pinned_at']);
            $table->dropColumn('board_pinned_at');
        });
    }
};
