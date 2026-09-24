<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an issue raised by a client starts: "New", flagged rather than named.
     *
     * Client-raised issues used to start in the project's default status, which is
     * the team's "ready to work on" — "Todo", or "Scoped" in the client website
     * template — so a client could file straight into the team's queue with nobody
     * having looked at it. A flag, because its category (backlog) is shared with
     * "Backlog" and a status must never be recognised by its name.
     *
     * Every existing project gets one, at the top of its workflow, so nothing about
     * upgrading depends on somebody remembering to add it.
     */
    public function up(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->boolean('is_triage')->default(false);
        });

        $projects = DB::table('projects')->select('id', 'workspace_id')->get();

        foreach ($projects as $project) {
            // The workflow editor refuses two statuses with one name, and a team may
            // already have made its own "New" meaning something else.
            $taken = DB::table('statuses')
                ->where('project_id', $project->id)
                ->whereRaw('lower(name) = ?', ['new'])
                ->exists();

            DB::table('statuses')->where('project_id', $project->id)->increment('position');

            DB::table('statuses')->insert([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'name' => $taken ? 'Untriaged' : 'New',
                'category' => 'backlog',
                'color' => '#38bdf8',
                'position' => 0,
                'is_default' => false,
                'is_triage' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Issues still in "New" go to the project's default, rather than being left
        // pointing at a status that no longer exists.
        $triage = DB::table('statuses')->where('is_triage', true)->get(['id', 'project_id']);

        foreach ($triage as $status) {
            $default = DB::table('statuses')
                ->where('project_id', $status->project_id)
                ->where('id', '!=', $status->id)
                ->orderByDesc('is_default')->orderBy('position')
                ->value('id');

            if ($default !== null) {
                DB::table('issues')->where('status_id', $status->id)->update(['status_id' => $default]);
            }

            DB::table('statuses')->where('id', $status->id)->delete();
        }

        Schema::table('statuses', function (Blueprint $table) {
            $table->dropColumn('is_triage');
        });
    }
};
