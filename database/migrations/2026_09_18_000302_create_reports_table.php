<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw widget intake. Reports are NOT issues: they land here and are promoted,
        // merged or discarded from the triage inbox. A tracker dies when the backlog
        // fills with junk, and this table is the airlock.
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('widget_key_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->text('body')->nullable();

            $table->string('reporter_name')->nullable();
            $table->string('reporter_email')->nullable();
            // Whatever the host app passed to buggie.identify(), as an opaque reference.
            $table->string('reporter_ref')->nullable();

            $table->jsonb('environment')->default('{}');
            $table->jsonb('console')->default('[]');
            $table->jsonb('network')->default('[]');
            $table->jsonb('error')->nullable();

            $table->string('screenshot_path')->nullable();
            $table->string('fingerprint')->nullable();

            $table->string('state')->default('new');   // new|promoted|merged|spam|discarded
            $table->foreignId('issue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('triaged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('triaged_at')->nullable();

            // HMAC of the IP, never the IP itself — enough to rate-limit a repeat
            // offender, not enough to identify a person.
            $table->string('ip_hash', 64)->nullable();

            $table->timestamps();

            $table->index(['project_id', 'state', 'created_at']);
            $table->index(['project_id', 'fingerprint']);
            $table->index('ip_hash');
        });

        DB::statement('CREATE INDEX reports_environment_index ON reports USING GIN (environment)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
