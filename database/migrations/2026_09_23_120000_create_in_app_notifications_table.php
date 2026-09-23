<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A separate table from `pending_notifications`, deliberately.
         *
         * Pending rows are a send queue: FlushNotifications deletes each group once
         * it has been turned into an email, which is exactly right for a queue and
         * exactly wrong for a list of what happened to you. Keeping them instead
         * would mean teaching the flush to mark rather than delete, and its grouping
         * query — "send a group once its newest row is older than the cutoff" — would
         * then re-send the same group every minute for ever unless a second
         * sent-or-not column were threaded through the lock, the transaction and the
         * dedup. A well-tested send queue is not the place to bolt on a second
         * meaning.
         *
         * `notifications` is taken by Laravel's database channel, which IssueDigest
         * already writes to, so this one says in its name what it is.
         */
        Schema::create('in_app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reason');      // assigned|mentioned|commented|status|reported|due
            $table->jsonb('data')->default('{}');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('read_at')->nullable();

            // The badge runs on every page load, so it gets its own covering index:
            // workspace (the global scope), then person, then read-or-not.
            $table->index(['workspace_id', 'user_id', 'read_at']);

            // The list itself, newest first.
            $table->index(['workspace_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notifications');
    }
};
