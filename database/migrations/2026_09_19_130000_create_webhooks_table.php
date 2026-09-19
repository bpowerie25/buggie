<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // Null means every project in the workspace. A per-project webhook is the
            // common case — one client, one Slack channel — but an agency watching
            // everything is reasonable too.
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name', 60);
            $table->text('url');

            // Signs every delivery, so the receiver can tell our POST from anybody
            // else's. Encrypted at rest, like the SMTP password.
            $table->text('secret');

            $table->jsonb('events')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->unsignedSmallInteger('status')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            // A webhook nobody can see the deliveries of is a webhook nobody can
            // debug: "it isn't working" with nothing to look at.
            $table->index(['webhook_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
};
