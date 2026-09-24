<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a member of staff has to give in a week, and what they do.
     *
     * On the membership rather than the user: somebody can be full-time at one
     * agency and a day a week at another, and a designer in one workspace is a
     * developer in the next.
     *
     * Both null until somebody sets them. Null hours is "not set", not zero or a
     * guessed 40: the workload screen shows those hours with no limit rather than
     * flagging somebody as overbooked against a number nobody chose.
     */
    public function up(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->decimal('weekly_hours', 5, 2)->nullable()->after('role');
            $table->string('discipline', 40)->nullable()->after('weekly_hours');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->dropColumn(['weekly_hours', 'discipline']);
        });
    }
};
