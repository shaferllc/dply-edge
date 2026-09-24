<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * When an app needs a PHP extension the published base does not have, build
 * that extension onto the current base and push it. The next deploy starts
 * FROM the new tag and skips the compile.
 */
final class EdgePhpBaseImage
{
    /**
     * @param  callable(string): void  $log
     */
    public static function ensure(string $checkout, callable $log): void
    {
        $repo = trim((string) config('edge.build.containers.php_base_repo', ''));
        if ($repo === '' || ! is_file($checkout.'/composer.json')) {
            return;
        }

        $composer = json_decode((string) file_get_contents($checkout.'/composer.json'), true);
        $lock = is_file($checkout.'/composer.lock')
            ? json_decode((string) file_get_contents($checkout.'/composer.lock'), true)
            : [];
        $identity = EdgeContainerDockerfile::phpIdentity($checkout);
        $current = EdgeContainerDockerfile::publishedPhpExtensions();
        $from = $repo.':'.EdgeContainerDockerfile::baseTag($identity['version'], $current, $identity['server']);
        if ($current !== EdgeContainerDockerfile::PHP_EXTENSIONS
            && Process::timeout(30)->run(['docker', 'info'])->successful()
            && ! Process::timeout(30)->run(['docker', 'manifest', 'inspect', $from])->successful()) {
            Cache::forget(EdgeContainerDockerfile::EXTRA_EXTENSIONS_CACHE_KEY);
            $log("Shared PHP image {$from} is not published. This build uses the standard image.\n");
        }

        $delta = EdgeContainerDockerfile::extraPhpExtensions(
            is_array($composer) ? $composer : [],
            is_array($lock) ? $lock : [],
        );
        if ($delta === '') {
            return;
        }

        $current = EdgeContainerDockerfile::publishedPhpExtensions();
        $names = [];
        foreach (preg_split('/\s+/', $current.' '.$delta) ?: [] as $name) {
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        $sorted = array_keys($names);
        sort($sorted);
        $contents = implode(' ', $sorted);
        $tag = $repo.':'.EdgeContainerDockerfile::baseTag($identity['version'], $contents, $identity['server']);
        $from = $repo.':'.EdgeContainerDockerfile::baseTag($identity['version'], $current, $identity['server']);

        if (! Process::timeout(30)->run(['docker', 'info'])->successful()) {
            $log("Docker is not available, so {$delta} will be installed in this image only.\n");

            return;
        }

        $log("Adding {$delta} to the shared PHP image {$tag} so later builds can reuse it.\n");
        $dir = sys_get_temp_dir().'/dply-edge-php-base';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/Dockerfile', implode("\n", [
            'FROM '.$from,
            'COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/',
            'RUN install-php-extensions '.$delta,
            '',
        ]));

        $log("Compiling {$delta}. Docker output follows.\n");
        $build = self::stream($log, 1800, ['docker', 'build', '--platform', 'linux/amd64', '--progress', 'plain', '-t', $tag, $dir]);
        if (! $build->successful()) {
            $log("Could not update the shared PHP image. This build will install {$delta} itself.\n");

            return;
        }

        $log("Pushing {$tag}.\n");
        $push = self::stream($log, 1800, ['docker', 'push', $tag]);
        File::deleteDirectory($dir);
        if (! $push->successful()) {
            $log("Could not push {$tag}. This build will install {$delta} itself.\n");

            return;
        }

        EdgeContainerDockerfile::rememberExtraExtensions($delta);
        $log("Shared PHP image updated with {$delta}.\n");
    }

    /**
     * @param  callable(string): void  $log
     * @param  list<string>  $command
     */
    private static function stream(callable $log, int $timeout, array $command): ProcessResult
    {
        $started = microtime(true);
        $last = $started;
        $process = Process::timeout($timeout)
            ->env(['BUILDKIT_PROGRESS' => 'plain'])
            ->start($command, function (string $type, string $output) use ($log, &$last): void {
                $last = microtime(true);
                $kept = [];
                foreach (preg_split('/\R/', $output) ?: [] as $line) {
                    if ($line === '' || str_contains($line, 'StandWithUkraine')) {
                        continue;
                    }
                    $kept[] = $line;
                }
                if ($kept !== []) {
                    $log(implode("\n", $kept)."\n");
                }
            });

        while ($process->running()) {
            usleep(500_000);
            if (microtime(true) - $last >= 20) {
                $log(sprintf("… still preparing the PHP image — %s elapsed.\n", gmdate('i:s', (int) (microtime(true) - $started))));
                $last = microtime(true);
            }
        }

        return $process->wait();
    }
}
