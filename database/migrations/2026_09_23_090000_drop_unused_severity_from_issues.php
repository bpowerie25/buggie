<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove `issues.severity`, which nothing has ever written.
     *
     * The idea behind it was sound and is preserved in IssuePriority's docblock:
     * severity is how bad a thing is when it happens, priority is when we will fix it,
     * and a cosmetic typo on a pricing page is low severity and urgent priority.
     *
     * It was never built. No form set it, no screen showed it, nothing filtered on it,
     * and the CSV importer maps a "severity" column from Mantis onto *priority*
     * instead — so not one row in any install has a value in it.
     *
     * A column that exists only in the schema is worse than an absent one: the next
     * person to read the model assumes it means something, and the next importer
     * quietly half-fills it. If severity is wanted later it comes back as an enum with
     * a screen attached, which is a different and better change than inheriting this.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn('severity');
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->string('severity')->nullable()->after('priority');
        });
    }
};
