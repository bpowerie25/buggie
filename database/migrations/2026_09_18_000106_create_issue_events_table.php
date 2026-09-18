<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A typed feed, not a generic audit log: the activity stream is a primary UI
        // surface here, so events carry a known `data` shape per type and render
        // without diffing model attributes.
        Schema::create('issue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type');
            $table->jsonb('data')->default('{}');

            // Events on an issue a client can see are still not all for their eyes.
            $table->boolean('is_internal')->default(false);

            // Microsecond precision: events interleave with comments in one feed,
            // and Laravel's default timestamp(0) makes that order arbitrary.
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['issue_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_events');
    }
};
