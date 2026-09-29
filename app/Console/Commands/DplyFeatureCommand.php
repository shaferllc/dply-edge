<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;

/**
 * Turn a resource feature flag on or off for an organization (Pennant).
 *
 *   dply:feature                          list the flags and who has them
 *   dply:feature images acme              turn Images on for org "acme"
 *   dply:feature images acme --off        turn it off
 *   dply:feature images --all             turn it on for every organization
 */
class DplyFeatureCommand extends Command
{
    protected $signature = 'dply:feature {kind? : A resource kind, such as images or key_value} {organization? : Organization slug or id} {--off : Turn it off} {--all : Every organization}';

    protected $description = 'Turn a resource feature flag on or off per organization';

    public function handle(): int
    {
        $kind = $this->argument('kind');
        if ($kind === null) {
            $this->table(['Kind', 'Flag', 'Organizations on'], array_map(fn (string $k): array => [
                $k,
                EdgeContainerConnections::flag($k),
                Organization::query()->get()->filter(fn (Organization $o): bool => Feature::for($o)->active(EdgeContainerConnections::flag($k)))->pluck('slug')->implode(', ') ?: '—',
            ], EdgeContainerConnections::FLAGGED));

            return self::SUCCESS;
        }
        if (! in_array($kind, EdgeContainerConnections::FLAGGED, true)) {
            $this->error('Pick one of: '.implode(', ', EdgeContainerConnections::FLAGGED));

            return self::FAILURE;
        }

        $organizations = $this->option('all')
            ? Organization::query()->get()
            : Organization::query()->where('slug', (string) $this->argument('organization'))->orWhere('id', (string) $this->argument('organization'))->get();
        if ($organizations->isEmpty()) {
            $this->error('No organization matched. Pass a slug or id, or --all.');

            return self::FAILURE;
        }

        $flag = EdgeContainerConnections::flag($kind);
        foreach ($organizations as $organization) {
            $this->option('off') ? Feature::for($organization)->deactivate($flag) : Feature::for($organization)->activate($flag);
            $this->line(($this->option('off') ? 'Off' : 'On').": {$flag} for {$organization->slug}");
        }

        return self::SUCCESS;
    }
}
