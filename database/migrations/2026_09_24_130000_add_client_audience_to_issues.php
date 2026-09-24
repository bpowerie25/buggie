<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who among the clients sees a client-visible issue, stored rather than inferred.
     *
     * `visibility` still decides internal against client, so nothing that already
     * tests it changes meaning. `client_audience` widens the tier default for one
     * issue, and the shares name particular people. Deliberately not the watchers
     * table: watching is about notifications, and un-watching must never take away
     * somebody's access.
     *
     * Existing issues get `default`, which is exactly today's rule.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->string('client_audience', 16)->default('default');
        });

        // No workspace_id: reached only through its issue, which is scoped, like the
        // other issue pivots.
        Schema::create('issue_client_shares', function (Blueprint $table) {
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['issue_id', 'user_id']);
            // The scope asks "is this user shared on this issue" from the user's side.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_client_shares');

        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn('client_audience');
        });
    }
};
