<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phases, per project: Discovery, Design, Build, Launch.
     *
     * How an agency quotes a website and how its client reads the plan. Not a
     * release — a version says where work shipped, a phase says which stage of the
     * job it belongs to — and not a parent issue, because a phase is not work
     * somebody does and closes.
     *
     * No dates of their own. A phase runs from its first issue to its last; a second
     * set of dates typed onto the phase is a second plan that disagrees with the
     * first by the end of the week.
     */
    public function up(): void
    {
        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'position']);
        });

        Schema::table('issues', function (Blueprint $table) {
            // nullOnDelete: deleting a phase must not delete the work that was in it.
            $table->foreignId('phase_id')->nullable()->after('version_id')
                ->constrained('phases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('phase_id');
        });

        Schema::dropIfExists('phases');
    }
};
