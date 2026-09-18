<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the project's application actually runs.
     *
     * Its only job is to seed a widget key's origin allowlist. Without it every new
     * key accepts reports from anywhere, because an empty allowlist has to mean "any
     * origin" for a paste-this-snippet install to work at all — which leaves every
     * project open until somebody remembers to close it.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('site_url')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('site_url');
        });
    }
};
