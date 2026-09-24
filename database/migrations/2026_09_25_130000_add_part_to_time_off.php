<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Half days: `am` or `pm` for a morning or an afternoon off, null for whole days.
     * Only ever on a single day; the workload screen counts it as half of one.
     */
    public function up(): void
    {
        Schema::table('time_off', function (Blueprint $table) {
            $table->string('part', 2)->nullable()->after('ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('time_off', function (Blueprint $table) {
            $table->dropColumn('part');
        });
    }
};
