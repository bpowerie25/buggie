<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notifications are batched per person per issue before sending, so a burst of
        // activity on one issue is one email rather than nine. Rows land here first
        // and a scheduled flush turns each group into a single message.
        Schema::create('pending_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reason');      // assigned|mentioned|commented|status|reported
            $table->jsonb('data')->default('{}');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'issue_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_notifications');
    }
};
