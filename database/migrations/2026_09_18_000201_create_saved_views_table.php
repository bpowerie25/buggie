<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A saved view is a stored query string plus how to display it. Because the
        // query language is the whole filter state, nothing else needs storing.
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // null = shared with the whole workspace; set = private to that user.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('query')->default('');
            $table->string('layout')->default('list');     // list|board
            $table->string('group_by')->default('status'); // status|assignee|priority|project
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
