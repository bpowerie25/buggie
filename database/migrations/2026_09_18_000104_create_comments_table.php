<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->jsonb('body');            // tiptap document
            $table->text('body_text');        // flattened, for search and notifications

            // The line clients must never cross. Defaults to internal.
            $table->boolean('is_internal')->default(true);
            $table->string('source')->default('web');   // web|email|widget

            $table->timestamp('edited_at')->nullable();
            // Matches issue_events: both feed into the same activity stream.
            $table->timestamps(6);
            $table->softDeletes();

            $table->index(['issue_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
