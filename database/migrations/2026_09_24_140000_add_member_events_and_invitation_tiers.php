<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A record of who changed what a client can see, and the tier an invitation grants.
     *
     * member_events exists because a client's tier decides how much of a project's
     * work they read, and "who gave them that" had no answer. It is workspace-scoped
     * like everything else, and written only by the members screen.
     *
     * invitations.project_roles is project id => tier. Empty means the narrowest tier
     * on every project, which is what every invitation granted before this.
     */
    public function up(): void
    {
        Schema::create('member_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->jsonb('data')->default('{}');
            $table->timestamp('created_at', 6)->nullable();

            $table->index(['workspace_id', 'created_at']);
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->jsonb('project_roles')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn('project_roles');
        });

        Schema::dropIfExists('member_events');
    }
};
