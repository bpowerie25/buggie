<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only record of who did what, and to what. Names are copied in rather
        // than joined: the person may have left and the thing may be deleted, and the
        // log must still read.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            // e.g. project.updated, label.deleted, auth.login
            $table->string('event', 80);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->jsonb('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'event']);
            $table->index(['workspace_id', 'actor_id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
