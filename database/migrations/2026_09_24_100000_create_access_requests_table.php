<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Somebody asking to be let in, on an install where sign-up is by invitation.
     *
     * workspace_id is nullable on purpose: a request made on the central domain is a
     * request for a workspace that does not exist yet, and only operators can act on
     * it. Approval is an ordinary invitation, linked here, not a second way in.
     */
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('email');
            // Only asked for on the central domain, where there is no workspace to
            // say who they are.
            $table->string('organisation', 120)->nullable();
            $table->text('message')->nullable();

            $table->string('status', 16)->default('pending');   // pending|approved|declined
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decline_reason')->nullable();
            $table->foreignId('invitation_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            // The per-address cap on pending requests reads this.
            $table->index(['email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
