<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Support\EdgeBuildDockerBootstrap;
use Illuminate\Console\Command;

/**
 * One-shot bootstrap for the Linux host that drains Edge build jobs.
 *
 * dply's own control-plane workers run Horizon as www-data
 * ({@see deploy/supervisor/dply-worker-primary.conf}). Deploy-time autoinstall
 * from that user fails without passwordless sudo — run this once as root on
 * each worker, then recycle Horizon.
 *
 *   sudo php artisan dply:edge:ensure-build-docker
 *   php artisan horizon:terminate
 */
class EdgeEnsureBuildDockerCommand extends Command
{
    protected $signature = 'dply:edge:ensure-build-docker
                            {--user= : Linux user that runs Horizon (default: edge.build.docker_user, else the sudo-invoking user)}
                            {--check : Only probe the daemon; do not install}';

    protected $description = 'Install/start Docker Engine on this host for Edge builds (run as root on control-plane workers)';

    public function handle(): int
    {
        $user = $this->resolveUser();

        if ($this->option('check')) {
            if (EdgeBuildDockerBootstrap::daemonReachable()) {
                $this->info('Docker daemon reachable ('.EdgeBuildDockerBootstrap::probeDetail().').');

                return self::SUCCESS;
            }

            $this->error('Docker daemon not reachable: '.EdgeBuildDockerBootstrap::probeDetail());
            $this->line('Fix (as root on this worker): sudo php artisan dply:edge:ensure-build-docker --user='.$user);

            return self::FAILURE;
        }

        if (EdgeBuildDockerBootstrap::daemonReachable()) {
            $this->info('Docker already reachable ('.EdgeBuildDockerBootstrap::probeDetail().').');
            $this->line("Ensuring socket access for {$user}…");
        }

        if (EdgeBuildDockerBootstrap::isLocalDesktopEnvironment()) {
            $this->error('This command installs Linux Docker Engine. On macOS start OrbStack/Docker Desktop, or use DPLY_FAKE_EDGE=true.');

            return self::FAILURE;
        }

        $this->info("Installing/starting Docker for Horizon user [{$user}]…");

        $result = EdgeBuildDockerBootstrap::ensure(
            $user,
            fn (string $chunk) => $this->output->write($chunk),
        );

        if (! $result['ok']) {
            $this->newLine();
            $this->error('Docker is still not reachable: '.$result['detail']);
            if ($result['exit_code'] === 42) {
                $this->line('Re-run as root: sudo php artisan dply:edge:ensure-build-docker --user='.$user);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Docker ready ('.$result['detail'].').');
        $this->warn('Restart Horizon so workers pick up the docker group: php artisan horizon:terminate');

        return self::SUCCESS;
    }

    private function resolveUser(): string
    {
        $option = $this->option('user');
        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        return EdgeBuildDockerBootstrap::queueUser();
    }
}
