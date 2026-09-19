<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a client's own customers see.
     *
     * Branding is per project rather than per workspace because an agency serves
     * several clients, and the person following a link about a broken checkout
     * should see the shop they were using — not the agency that built it, and
     * certainly not the tracker the agency happens to use.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Null means "use the project name", which is right far more often than
            // an empty box somebody has to fill in.
            $table->string('brand_name', 60)->nullable()->after('site_url');
            $table->string('brand_logo_path')->nullable()->after('brand_name');
            $table->string('brand_color', 7)->nullable()->after('brand_logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['brand_name', 'brand_logo_path', 'brand_color']);
        });
    }
};
