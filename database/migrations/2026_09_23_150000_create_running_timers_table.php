<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A clock somebody started, for the people who like clocks.
     *
     * Time tracking works without this — you type "1h 30m" and it is logged — and
     * plenty of people prefer that, because a timer earns its keep when you sit on
     * one task for an hour and becomes a liability when you are switching between
     * six client emergencies. This is an option, not a replacement.
     *
     * One row per person, not per workspace. You can only be doing one thing at a
     * time, and a timer running in another workspace that you cannot see from here
     * is exactly the timer that gets left running over a weekend.
     */
    public function up(): void
    {
        Schema::create('running_timers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // One at a time, enforced by the database rather than by whoever writes
            // the next controller.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');

            // Typed while it runs, so the note is written when the work is fresh
            // rather than reconstructed when it stops.
            $table->string('note', 255)->nullable();
            $table->boolean('billable')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('running_timers');
    }
};
