<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Support\Reports\ReporterLink;
use Illuminate\Console\Command;

/**
 * Link widget issues accepted before now to the clients who reported them.
 *
 * The same rule as accepting a report today: a verified identity always counts, a
 * typed or identified email only where the workspace trusts unverified addresses,
 * and only a client who holds the issue's project is ever linked. Turning that
 * setting on in the app does this for its workspace; this is for doing it from the
 * command line, or seeing first what it would do.
 */
class LinkWidgetReporters extends Command
{
    protected $signature = 'buggie:link-widget-reporters
        {--workspace= : Only this workspace, by its address}
        {--dry-run : Say what would be linked without linking it}';

    protected $description = 'Link existing widget issues to the client members who reported them';

    public function handle(): int
    {
        $workspaces = Workspace::query()
            ->when($this->option('workspace'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('slug')
            ->get();

        if ($workspaces->isEmpty()) {
            $this->error('No such workspace.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($workspaces as $workspace) {
            $result = ReporterLink::backfill($workspace, $dry);
            $total += $result['linked'];

            $trust = ReporterLink::trustsUnverified($workspace) ? 'trusts typed emails' : 'verified identities only';

            $this->line(sprintf(
                '%-24s %s: %d of %d widget issues %s%s',
                $workspace->slug,
                "({$trust})",
                $result['linked'],
                $result['examined'],
                $dry ? 'would be linked' : 'linked',
                $result['keys'] === [] ? '' : ' — '.implode(', ', array_slice($result['keys'], 0, 10)).(count($result['keys']) > 10 ? ', …' : ''),
            ));
        }

        $dry
            ? $this->comment("Dry run: nothing was changed. {$total} would be linked.")
            : $this->info("{$total} linked.");

        return self::SUCCESS;
    }
}
