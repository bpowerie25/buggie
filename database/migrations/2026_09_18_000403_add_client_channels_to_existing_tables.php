<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            // Comments from the portal or from email have no user account behind them.
            $table->string('author_name')->nullable()->after('user_id');
            $table->string('author_email')->nullable()->after('author_name');
        });

        Schema::table('projects', function (Blueprint $table) {
            // The local-part suffix for bugs+{token}@in.buggy.app
            $table->string('inbound_token', 32)->nullable()->unique()->after('slug');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('notification_settings')->default('{}')->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn(['author_name', 'author_email']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('inbound_token');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_settings');
        });
    }
};
