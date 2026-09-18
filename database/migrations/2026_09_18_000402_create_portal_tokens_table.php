<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lets someone who reported a bug follow it without being made to create an
        // account. The token is the credential, so it is long, scoped to one issue,
        // and expires.
        Schema::create('portal_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['issue_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_tokens');
    }
};
