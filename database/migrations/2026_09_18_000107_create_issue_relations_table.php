<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_issue_id')->constrained('issues')->cascadeOnDelete();
            $table->string('type');    // blocks|blocked_by|relates_to|duplicates
            $table->timestamps();

            $table->unique(['issue_id', 'related_issue_id', 'type']);
            $table->index('related_issue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_relations');
    }
};
