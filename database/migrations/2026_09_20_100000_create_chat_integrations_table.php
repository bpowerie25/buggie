<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // Null means every project in the workspace, as with webhooks. One
            // client, one channel is the arrangement that keeps an agency honest.
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('provider', 20);
            $table->string('name', 60);

            // Encrypted, and text rather than string because the ciphertext is
            // several times the length of the address. An incoming-webhook URL is a
            // credential — anyone holding it can post into that channel as us — so a
            // stolen database dump must not be a stolen channel.
            $table->text('url');

            $table->jsonb('events')->default('[]');
            $table->boolean('is_active')->default(true);

            // Off by default. A channel Buggie posts into may well have a client in
            // it, and the difference between an internal note and a public one is
            // exactly the thing a client is not meant to learn.
            $table->boolean('internal_activity')->default(false);

            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'is_active']);
        });

        Schema::create('chat_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_integration_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->unsignedSmallInteger('status')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable();

            // Same reason as webhook_deliveries: a channel that quietly stopped
            // receiving messages is otherwise a shrug. Slack and Teams both answer a
            // wrong or revoked URL with a body worth reading, and this is where it
            // is kept so somebody can read it.
            $table->index(['chat_integration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_deliveries');
        Schema::dropIfExists('chat_integrations');
    }
};
