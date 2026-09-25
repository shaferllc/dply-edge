<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Support\EdgeContainerSettings;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

/**
 * Ask each container site that keeps instances awake to start them.
 *
 * The Worker decides what "awake" means right now (min instances, the
 * current scaling window, an always-on jobs instance), so this only has to
 * knock. It is how a window that raises the minimum at 09:00 gets its
 * instances started, and how an always-on instance Cloudflare restarted
 * comes back.
 *
 *   php artisan dply:edge:warm-containers
 */
class WarmEdgeContainersCommand extends Command
{
    protected $signature = 'dply:edge:warm-containers';

    protected $description = 'Start always-on container instances (min instances, scaling windows, jobs).';

    public function handle(): int
    {
        $sites = Site::query()
            ->where('meta->edge->runtime_mode', 'container')
            ->get()
            ->filter(fn (Site $site): bool => ! $site->isEdgePreview()
                && is_string($site->edgeLiveUrl()) && $site->edgeLiveUrl() !== ''
                && EdgeContainerDeployer::keepsInstancesAwake(EdgeContainerSettings::for($site)))
            ->values();

        // The Worker answers 202 and starts them in the background.
        Http::pool(fn (Pool $pool) => $sites->map(fn (Site $site) => $pool
            ->timeout(15)
            ->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($site)])
            ->post(rtrim((string) $site->edgeLiveUrl(), '/').'/_dply/warm'))->all());

        $this->info(sprintf('Warmed %d container site(s).', $sites->count()));

        return self::SUCCESS;
    }
}
