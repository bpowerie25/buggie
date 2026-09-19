<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('filename');
            $table->string('path');
            $table->string('format', 20);
            $table->string('state', 20)->default('previewing');

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('skipped')->default(0);

            // What went wrong, per row. An import that says "47 failed" and nothing
            // else is an import nobody can fix.
            $table->jsonb('problems')->default('[]');

            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'project_id']);
        });

        Schema::table('issues', function (Blueprint $table) {
            // Where this came from, if it was imported: "MANTIS-4821", "WEB-12".
            // Unique per project, so running the same import twice updates nothing
            // and creates nothing rather than doubling the backlog.
            $table->string('source_key', 60)->nullable()->after('key');

            $table->unique(['project_id', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'source_key']);
            $table->dropColumn('source_key');
        });

        Schema::dropIfExists('imports');
    }
};
