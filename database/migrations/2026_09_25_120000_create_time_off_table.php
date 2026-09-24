<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Days a member of staff is not working: their own leave, or a public holiday
     * that applies to everybody.
     *
     * One table for both, because to the workload screen they are the same thing — a
     * day with no hours in it — and a holiday is simply leave with nobody named.
     * Whole days only: a half-day is a note, not a plan.
     */
    public function up(): void
    {
        Schema::create('time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // Null: a public holiday, for everyone in the workspace.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('note', 80)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_off');
    }
};
