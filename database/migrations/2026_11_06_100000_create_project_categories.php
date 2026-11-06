<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The workspace's own vocabulary for kinds of work — Design, Development,
        // Consultancy — each with a colour, so a dashboard of forty projects can be read
        // at a glance and filtered.
        Schema::create('project_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 9)->default('#64748b');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::table('projects', function (Blueprint $table) {
            // Deleting a category leaves its projects uncategorised rather than gone.
            $table->foreignId('project_category_id')->nullable()->constrained('project_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_category_id');
        });

        Schema::dropIfExists('project_categories');
    }
};
