<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sanctum's table, plus a workspace.
     *
     * A token belongs to a person, but a person may work in several workspaces — an
     * agency's own, and every client workspace they were invited to. A token that
     * worked in all of them would mean one leaked key from a throwaway script opened
     * every client's data at once. Each token names exactly one workspace, and the
     * API refuses it anywhere else.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'tokenable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
