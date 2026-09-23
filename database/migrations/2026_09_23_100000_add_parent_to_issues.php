<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One level of hierarchy: an issue can have a parent.
     *
     * Relations already cover "blocks", "relates to" and "duplicates", and none of
     * them say what "build the importer" means when it is five pieces of work.
     * `blocks` is the closest and it is the wrong claim — a subtask does not block its
     * parent, it constitutes it.
     *
     * **One level, deliberately.** Sub-sub-tasks are how a tracker becomes a project
     * plan, and the recursion turns every count, filter and board query into a tree
     * walk. A parent that is itself a child is refused in the action rather than left
     * to whoever writes the next report.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            // nullOnDelete, not cascade: deleting a parent must never silently take
            // the work underneath it. The children are orphaned and stay findable,
            // which is recoverable; a cascade is not.
            $table->foreignId('parent_id')->nullable()->after('project_id')
                ->constrained('issues')->nullOnDelete();

            $table->index(['workspace_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'parent_id']);
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
