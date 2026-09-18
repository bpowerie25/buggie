<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Human identity. number is gapless per project; key is the rendered form.
            $table->unsignedInteger('number');
            $table->string('key', 16);

            $table->string('title');
            $table->jsonb('description')->nullable();       // tiptap document
            $table->text('description_text')->nullable();   // flattened, for search

            $table->string('type')->default('bug');         // bug|feature|task|question
            $table->foreignId('status_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('priority')->default(0);  // 0 none … 4 urgent
            $table->unsignedTinyInteger('severity')->nullable();

            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();

            // Clients see only visibility = client. Defaults closed, not open.
            $table->string('visibility')->default('internal');

            // Populated from M4 onwards by the reporter widget; unused before then.
            $table->string('fingerprint')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->foreignId('duplicate_of_id')->nullable()
                ->constrained('issues')->nullOnDelete();
            $table->jsonb('environment')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->date('due_on')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'number']);
            $table->index(['workspace_id', 'status_id']);
            $table->index(['workspace_id', 'assignee_id']);
            $table->index(['project_id', 'fingerprint']);
        });

        // Weighted search vector: a title match should outrank a body match.
        DB::statement(<<<'SQL'
            ALTER TABLE issues ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(description_text, '')), 'B')
            ) STORED
        SQL);

        DB::statement('CREATE INDEX issues_search_vector_index ON issues USING GIN (search_vector)');
        DB::statement('CREATE INDEX issues_environment_index ON issues USING GIN (environment)');

        // Almost every screen asks for open issues. Keep that index small.
        DB::statement(<<<'SQL'
            CREATE INDEX issues_open_index ON issues (workspace_id, project_id, status_id)
            WHERE deleted_at IS NULL AND closed_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
