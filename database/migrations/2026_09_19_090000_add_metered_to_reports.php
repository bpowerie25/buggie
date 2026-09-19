<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether this report counts against the workspace's monthly allowance.
     *
     * The product's headline claim is that forty people hitting one broken checkout
     * is one issue, not forty tickets. Billing said otherwise: it counted all forty.
     * Past a handful of reports sharing a fingerprint, the payload is no longer
     * stored — the sixth screenshot of the same broken button tells nobody anything —
     * so it costs nothing to keep and should cost nothing to send.
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->boolean('metered')->default(true);
        });

        // Usage is read on every ingest, so the count has an index of its own rather
        // than filtering a project index after the fact.
        Schema::table('reports', function (Blueprint $table) {
            $table->index(['workspace_id', 'metered', 'created_at'], 'reports_metering_index');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex('reports_metering_index');
            $table->dropColumn('metered');
        });
    }
};
