<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Imports that update as well as create.
     *
     * A row whose key matches an issue already in the project used to be skipped, so
     * the same file could be imported twice safely. That stays the default. With
     * update_existing on, the matching issue is updated from the row instead — the
     * way a team edits a spreadsheet of work and brings the changes back in.
     */
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->boolean('update_existing')->default(false)->after('state');
            $table->unsignedInteger('updated')->default(0)->after('imported');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn(['update_existing', 'updated']);
        });
    }
};
