<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * How sure we are who reported something, and the secret that makes "sure" mean
     * something.
     *
     * widget_keys.mode already existed and did nothing; it now decides what identity
     * a key accepts (anonymous, identified, or verified-only). Each key gets a secret
     * for signing identities, encrypted with APP_KEY like the mail password, so a
     * database dump is not a way to forge a verified report.
     *
     * The identity is decided at ingest and carried onto the issue when a report is
     * accepted, with the reporter's name and address. None of it grants access: that
     * happens only by setting reporter_id to a client member, and only for a report
     * the workspace trusts.
     */
    public function up(): void
    {
        Schema::table('widget_keys', function (Blueprint $table) {
            $table->text('secret')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
        });

        foreach (DB::table('widget_keys')->whereNull('secret')->pluck('id') as $id) {
            DB::table('widget_keys')->where('id', $id)->update([
                'secret' => Crypt::encryptString('whs_'.Str::random(40)),
                'secret_rotated_at' => now(),
            ]);
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->string('reporter_identity', 20)->nullable();
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->string('reporter_identity', 20)->nullable();
            $table->string('reporter_name')->nullable();
            $table->string('reporter_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn(['reporter_identity', 'reporter_name', 'reporter_email']);
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('reporter_identity');
        });

        Schema::table('widget_keys', function (Blueprint $table) {
            $table->dropColumn(['secret', 'secret_rotated_at']);
        });
    }
};
