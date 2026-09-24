<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whose turn it is on an issue, carried by its status.
     *
     * A project flags one status as "awaiting client". Replying to a client and
     * waiting moves the issue there, remembering where it was; the client's reply
     * moves it back. The assignee never changes on its own — that is how a client
     * ended up as somebody's assignee, and the status is the honest place for
     * "we are waiting on them".
     *
     * The timestamps are what the scheduler reads: when the wait began, whether the
     * reminder has gone (so it goes once), and whether the issue was closed for want
     * of a reply (so a late reply can still reopen it). client_replied_at is the
     * badge that tells the team a client has answered.
     */
    public function up(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->boolean('is_awaiting_client')->default(false);
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->foreignId('status_before_waiting_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->timestamp('awaiting_client_since')->nullable();
            $table->timestamp('client_reminded_at')->nullable();
            $table->timestamp('auto_closed_at')->nullable();
            $table->timestamp('client_replied_at')->nullable();

            // The scheduler's question: what has been waiting, and since when.
            $table->index(['workspace_id', 'awaiting_client_since']);
        });

        /*
         * Once, on upgrade: projects made from the client website and support
         * templates already have "Awaiting client" or "Waiting on client", and should
         * not lose the button for want of a tick. Exactly those names, in an open
         * category, one per project.
         * From here on the flag is what counts, never the name.
         */
        $candidates = DB::table('statuses')
            ->whereIn(DB::raw('lower(name)'), ['awaiting client', 'waiting on client'])
            ->whereIn('category', ['backlog', 'unstarted', 'started'])
            ->orderBy('position')
            ->get(['id', 'project_id']);

        foreach ($candidates->unique('project_id') as $status) {
            DB::table('statuses')->where('id', $status->id)->update(['is_awaiting_client' => true]);
        }

        if ($candidates->isNotEmpty() && app()->runningInConsole()) {
            echo '  Marked '.$candidates->unique('project_id')->count()." \"Awaiting client\" status(es) as the awaiting-client status.\n";
        }
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'awaiting_client_since']);
            $table->dropConstrainedForeignId('status_before_waiting_id');
            $table->dropColumn(['awaiting_client_since', 'client_reminded_at', 'auto_closed_at', 'client_replied_at']);
        });

        Schema::table('statuses', function (Blueprint $table) {
            $table->dropColumn('is_awaiting_client');
        });
    }
};
