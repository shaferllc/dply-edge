<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\Containers\EdgeContainerDockerfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Pre-pull Node build images on Edge workers so the first deploy of the
 * day doesn't pay a cold `docker pull` (30–90s).
 */
class WarmEdgeBuildImagesCommand extends Command
{
    protected $signature = 'dply:edge:warm-build-images
                            {--image=* : Specific image(s); defaults to config edge.build.warm_images}
                            {--no-layers : Skip pre-building the PHP extension layer}';

    protected $description = 'Pre-pull Edge Docker build images and warm the PHP extension layer on this worker';

    public function handle(): int
    {
        $images = $this->option('image');
        if ($images === []) {
            $images = config('edge.build.warm_images', ['node:20-bookworm', 'node:22-bookworm']);
        }
        $images = array_values(array_unique(array_filter(array_map(
            static fn ($image) => is_string($image) ? trim($image) : '',
            is_array($images) ? $images : [],
        ))));

        if ($images === []) {
            $this->warn('No images configured.');

            return self::SUCCESS;
        }

        $docker = Process::timeout(30)->run(['docker', 'info']);
        if (! $docker->successful()) {
            $this->error('Docker is not available on this host.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($images as $image) {
            $this->info("Pulling {$image}…");
            $pull = Process::timeout(900)->run(['docker', 'pull', $image]);
            if ($pull->successful()) {
                $this->line("  ok — {$image}");
            } else {
                $failed++;
                $this->error('  failed: '.trim($pull->errorOutput() ?: $pull->output()));
            }
        }

        if (! $this->option('no-layers')) {
            $failed += $this->warmPhpExtensionLayer();
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Pre-build the FROM + install-php-extensions layer for each supported PHP.
     * Compiling intl/zip/redis is ~5 min of a cold container build; BuildKit
     * reuses the layer as long as the two lines match byte for byte, which is
     * why both sides read them from EdgeContainerDockerfile.
     */
    private function warmPhpExtensionLayer(): int
    {
        $dir = sys_get_temp_dir().'/dply-edge-warm-php';
        File::ensureDirectoryExists($dir);
        $failed = 0;

        foreach (EdgeContainerDockerfile::PHP_VERSIONS as $version) {
            File::put($dir.'/Dockerfile', implode("\n", EdgeContainerDockerfile::phpBaseSourceLines($version))."\n");

            $this->info("Warming PHP {$version} extension layer…");
            $build = Process::timeout(1800)
                ->env(['BUILDKIT_PROGRESS' => 'plain'])
                ->run(['docker', 'build', '-t', "dply-edge-warm-php{$version}", $dir]);

            if ($build->successful()) {
                $this->line("  ok — php {$version}");
            } else {
                $failed++;
                $this->error('  failed: '.trim(substr($build->errorOutput() ?: $build->output(), -300)));
            }
        }

        File::deleteDirectory($dir);

        return $failed;
    }
}
