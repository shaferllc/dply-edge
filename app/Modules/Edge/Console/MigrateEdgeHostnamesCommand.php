<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Support\EdgeTestingDomains;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Move Edge delivery hostnames off retired apexes onto the current default apex.
 */
class MigrateEdgeHostnamesCommand extends Command
{
    protected $signature = 'dply:edge:migrate-hostnames
                            {--dry-run : Show planned changes without writing}
                            {--site= : Migrate a single site id}';

    protected $description = 'Migrate Edge sites from retired apexes (dply.host, on-dply.site) to the default apex.';

    private const LEGACY_APEXES = ['dply.host', 'on-dply.site'];

    public function handle(EdgeHostMapPublisher $hostMapPublisher): int
    {
        $targetApex = EdgeTestingDomains::defaultApex();
        $dryRun = (bool) $this->option('dry-run');
        $siteId = $this->option('site');

        $query = Site::query()
            ->where('status', Site::STATUS_EDGE_ACTIVE)
            ->where('edge_backend', 'dply_edge');

        if (is_string($siteId) && $siteId !== '') {
            $query->where('id', $siteId);
        }

        $legacyApexFor = static function (Site $site) use ($targetApex): ?string {
            $host = strtolower($site->edgeHostname());
            foreach (self::LEGACY_APEXES as $apex) {
                if ($apex !== $targetApex && str_ends_with($host, '.'.$apex)) {
                    return $apex;
                }
            }

            return null;
        };

        $sites = $query->get()->filter(fn (Site $site): bool => $legacyApexFor($site) !== null);

        if ($sites->isEmpty()) {
            $this->info('No Edge hostnames on retired apexes found.');

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            $oldHost = strtolower($site->edgeHostname());
            $prefix = (string) Str::beforeLast($oldHost, '.'.$legacyApexFor($site));
            $newHost = strtolower($prefix.'.'.$targetApex);

            $this->line("Site {$site->id} ({$site->name}): {$oldHost} → {$newHost}");

            if ($dryRun) {
                continue;
            }

            $meta = $site->edgeMeta();
            $routing = is_array($meta['routing'] ?? null) ? $meta['routing'] : [];
            $routing['hostname'] = $newHost;
            unset($routing['testing_dns']);
            $meta['routing'] = $routing;
            $meta['live_url'] = 'https://'.$newHost;

            $site->update([
                'meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta]),
            ]);

            $activeId = $meta['active_deployment_id'] ?? null;
            if (is_string($activeId) && $activeId !== '') {
                $deployment = EdgeDeployment::query()->find($activeId);
                if ($deployment instanceof EdgeDeployment) {
                    try {
                        $hostMapPublisher->unpublishHostname($site->fresh(), $oldHost);
                        $hostMapPublisher->publishHostname($site->fresh(), $deployment, $newHost);
                    } catch (\Throwable $e) {
                        $this->warn("  KV republish failed: {$e->getMessage()}");
                    }
                }
            }
        }

        $this->info($dryRun ? 'Dry run complete.' : 'Hostname migration complete. Redeploy affected sites if delivery looks stale.');

        return self::SUCCESS;
    }
}
