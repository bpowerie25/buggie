<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Releases, per project.
     *
     * The one workflow idea the older trackers have that this did not: being able to
     * say "fixed in 2.4.1" and hand a client the list. The widget already captures a
     * release string from the reporter's browser, but that says where a bug was
     * found, not where it was fixed.
     */
    public function up(): void
    {
        Schema::create('versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->text('description')->nullable();

            // Null means unreleased: planned, being worked on, not out yet.
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            // Two "2.4.1"s in one project is a mistake every time.
            $table->unique(['project_id', 'name']);
            $table->index(['workspace_id', 'released_at']);
        });

        Schema::table('issues', function (Blueprint $table) {
            // nullOnDelete, not cascade: deleting a release must not delete the bugs
            // that were in it.
            $table->foreignId('version_id')->nullable()->after('status_id')
                ->constrained('versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('version_id');
        });

        Schema::dropIfExists('versions');
    }
};
