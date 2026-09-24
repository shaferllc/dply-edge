<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\Containers\EdgeContainerDockerfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Bake the PHP extension layer once and push it, so container builds pull it
 * instead of compiling intl/zip/redis per build host (~5 min each).
 *
 * Warming only helps the daemon that ran it; this helps every worker. Run it
 * when PHP_EXTENSIONS or PHP_VERSIONS change, then point
 * DPLY_EDGE_CONTAINER_PHP_BASE_REPO at the repo.
 */
class PublishEdgeBaseImagesCommand extends Command
{
    protected $signature = 'dply:edge:publish-base-images
                            {--stack=* : php and/or ruby; default both}
                            {--server=* : PHP servers to build (frankenphp, swoole, roadrunner, fpm); default all}
                            {--php-repo= : Overrides config edge.build.containers.php_base_repo}
                            {--ruby-repo= : Overrides config edge.build.containers.ruby_base_repo}
                            {--only=* : Only these minors (e.g. --only=8.4)}
                            {--platform=linux/amd64 : Cloudflare Containers are amd64; multi-arch doubles registry size}
                            {--no-push : Build locally without pushing}';

    protected $description = 'Build and push the prebuilt PHP/Ruby base images container builds start from';

    public function handle(): int
    {
        $stacks = $this->option('stack') ?: ['php', 'ruby'];
        if (array_diff($stacks, ['php', 'ruby']) !== []) {
            $this->error('Unknown stack. Use --stack=php and/or --stack=ruby.');

            return self::FAILURE;
        }

        if (! Process::timeout(30)->run(['docker', 'info'])->successful()) {
            $this->error('Docker is not available on this host.');

            return self::FAILURE;
        }

        $dir = sys_get_temp_dir().'/dply-edge-base-images';
        File::ensureDirectoryExists($dir);
        $failed = 0;
        $published = [];

        foreach ($stacks as $stack) {
            $repo = $this->repoFor($stack);
            if ($repo === '') {
                $this->warn("Skipping {$stack}: no repo configured.");

                continue;
            }

            foreach ($this->versionsFor($stack) as $version) {
                foreach ($this->serversFor($stack) as $server) {
                    $tag = $repo.':'.EdgeContainerDockerfile::baseTag($version, $this->contentsFor($stack), $server);
                    File::put($dir.'/Dockerfile', implode("\n", $this->sourceLinesFor($stack, $version, $server))."\n");

                    $this->info("Building {$tag}…");
                    $build = Process::timeout(1800)
                        ->env(['BUILDKIT_PROGRESS' => 'plain'])
                        ->run(['docker', 'build', '--platform', (string) $this->option('platform'), '-t', $tag, $dir]);

                    if (! $build->successful()) {
                        $failed++;
                        $this->error('  build failed: '.trim(substr($build->errorOutput() ?: $build->output(), -300)));

                        continue;
                    }

                    if ($this->option('no-push')) {
                        $this->line("  built (not pushed) — {$tag}");

                        continue;
                    }

                    $push = Process::timeout(1800)->run(['docker', 'push', $tag]);
                    if ($push->successful()) {
                        $this->line("  pushed — {$tag}");
                        $published[$stack] = $repo;
                    } else {
                        $failed++;
                        $this->error('  push failed: '.trim(substr($push->errorOutput() ?: $push->output(), -300)));
                    }
                }
            }
        }

        File::deleteDirectory($dir);

        foreach ($published as $stack => $repo) {
            $this->line('Set DPLY_EDGE_CONTAINER_'.strtoupper($stack)."_BASE_REPO={$repo} so builds use them.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function repoFor(string $stack): string
    {
        return trim((string) ($stack === 'php'
            ? ($this->option('php-repo') ?: config('edge.build.containers.php_base_repo', ''))
            : ($this->option('ruby-repo') ?: config('edge.build.containers.ruby_base_repo', ''))));
    }

    /** @return list<string> */
    private function versionsFor(string $stack): array
    {
        $all = $stack === 'php'
            ? EdgeContainerDockerfile::PHP_VERSIONS
            : EdgeContainerDockerfile::RUBY_VERSIONS;

        $only = $this->option('only');

        return $only ? array_values(array_intersect($all, $only)) : $all;
    }

    /** What the tag hashes, so a changed list publishes under a new tag. */
    private function contentsFor(string $stack): string
    {
        return $stack === 'php'
            ? EdgeContainerDockerfile::PHP_EXTENSIONS
            : EdgeContainerDockerfile::RUBY_PACKAGES;
    }

    /** @return list<string> */
    private function sourceLinesFor(string $stack, string $version, string $server = 'frankenphp'): array
    {
        return $stack === 'php'
            ? EdgeContainerDockerfile::phpBaseSourceLines($version, $server)
            : EdgeContainerDockerfile::rubyBaseSourceLines($version);
    }

    /**
     * Ruby has a single server; PHP builds one base per application server.
     *
     * @return list<string>
     */
    private function serversFor(string $stack): array
    {
        if ($stack !== 'php') {
            return ['frankenphp'];   // unused for ruby, keeps the loop uniform
        }

        $only = $this->option('server');

        return $only
            ? array_values(array_intersect(EdgeContainerDockerfile::PHP_SERVERS, $only))
            : EdgeContainerDockerfile::PHP_SERVERS;
    }
}
