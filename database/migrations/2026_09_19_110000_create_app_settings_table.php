<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settings for the whole install, editable without touching .env.
     *
     * Deliberately not workspace-scoped: there is one mail server for the install,
     * not one per workspace. A self-hoster changing an SMTP password should not have
     * to edit a file on the server and redeploy, and on the hosted service the
     * operator should not have to either.
     */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
