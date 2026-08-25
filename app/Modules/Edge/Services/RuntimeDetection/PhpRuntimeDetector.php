<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;


/**
 * Detects PHP apps from composer.json and related repo signals.
 *
 * Pre-fills:
 *   - runtime: "php"
 *   - version: from .tool-versions / composer.json#config.platform.php /
 *     composer.json#require.php
 *   - framework: laravel | symfony | wordpress | "php"
 *   - build: composer install --no-dev --optimize-autoloader
 *   - start: null (PHP-FPM is implicit — no long-running process)
 *   - app port: null (NGINX talks to FPM via Unix socket; no internal port)
 *   - processes: laravel/horizon worker hint when horizon is in require
 *     AND config/horizon.php exists
 */
final class PhpRuntimeDetector implements RuntimeDetector
{
    public function runtime(): string
    {
        return 'php';
    }

    public function detect(string $workingDirectory): ?RuntimeDetection
    {
        $root = rtrim($workingDirectory, '/');
        $composerPath = $root.'/composer.json';

        if (! is_file($composerPath)) {
            // WordPress-without-composer is intentionally not auto-detected:
            // those sites don't fit the build/release pipeline shape and need
            // a different (template-driven) onboarding flow.
            return null;
        }

        $composerJson = $this->readJson($composerPath);
        if ($composerJson === null) {
            return null;
        }

        $detectedFiles = ['composer.json'];
        $reasons = ['Found `composer.json` at the repo root.'];

        $version = $this->detectVersion($root, $composerJson, $detectedFiles, $reasons);
        $require = is_array($composerJson['require'] ?? null) ? $composerJson['require'] : [];
        $requireDev = is_array($composerJson['require-dev'] ?? null) ? $composerJson['require-dev'] : [];
        $packages = array_merge(array_keys($require), array_keys($requireDev));

        $framework = $this->detectFramework($packages, $root, $detectedFiles, $reasons);

        $buildCommand = 'composer install --no-dev --optimize-autoloader';
        $reasons[] = "Suggested build: `{$buildCommand}`.";

        $processes = $this->detectProcesses($packages, $root, $framework, $detectedFiles, $reasons);

        $confidence = $framework !== 'php' ? 'high' : 'medium';

        return new RuntimeDetection(
            runtime: 'php',
            version: $version,
            framework: $framework,
            buildCommand: $buildCommand,
            startCommand: null,
            appPort: null,
            detectedFiles: $detectedFiles,
            reasons: $reasons,
            processes: $processes,
            confidence: $confidence,
        );
    }

    /**
     * @param  array<string, mixed> $composerJson
     * @param  list<string>  $detectedFiles
     * @param  list<string>  $reasons
     */
    private function detectVersion(
        string $root,
        array $composerJson,
        array &$detectedFiles,
        array &$reasons,
    ): ?string {
        $toolVersionsPath = $root.'/.tool-versions';
        if (is_file($toolVersionsPath)) {
            $contents = (string) file_get_contents($toolVersionsPath);
            if (preg_match('/^php\s+(\S+)/m', $contents, $matches) === 1) {
                $detectedFiles[] = '.tool-versions';
                $reasons[] = "Pinned PHP {$matches[1]} from `.tool-versions`.";

                return trim($matches[1]);
            }
        }

        $config = is_array($composerJson['config'] ?? null) ? $composerJson['config'] : [];
        $platform = is_array($config['platform'] ?? null) ? $config['platform'] : [];
        $platformPhp = $platform['php'] ?? null;
        if (is_string($platformPhp) && trim($platformPhp) !== '') {
            $reasons[] = "Pinned PHP {$platformPhp} from `composer.json#config.platform.php`.";

            return trim($platformPhp);
        }

        $require = is_array($composerJson['require'] ?? null) ? $composerJson['require'] : [];
        $requirePhp = $require['php'] ?? null;
        if (is_string($requirePhp) && trim($requirePhp) !== '') {
            $reasons[] = "Pinned PHP {$requirePhp} from `composer.json#require.php`.";

            return trim($requirePhp);
        }

        return null;
    }

    /**
     * @param  list<(int|string)> $packages
     * @param  list<string>  $detectedFiles
     * @param  list<string>  $reasons
     */
    private function detectFramework(array $packages, string $root, array &$detectedFiles, array &$reasons): string
    {
        if (in_array('laravel/framework', $packages, true)) {
            $reasons[] = 'Detected laravel from `composer.json` require `laravel/framework`.';

            return 'laravel';
        }

        if (in_array('symfony/framework-bundle', $packages, true) || in_array('symfony/symfony', $packages, true)) {
            $reasons[] = 'Detected symfony from `composer.json` require `symfony/framework-bundle`.';

            return 'symfony';
        }

        if (in_array('roots/wordpress', $packages, true) || in_array('johnpbloch/wordpress', $packages, true)) {
            $reasons[] = 'Detected wordpress from `composer.json` (Bedrock-style WordPress install).';

            return 'wordpress';
        }

        if (is_file($root.'/wp-config.php')) {
            $detectedFiles[] = 'wp-config.php';
            $reasons[] = 'Detected wordpress from `wp-config.php` at the repo root.';

            return 'wordpress';
        }

        return 'php';
    }

    /**
     * @param  list<(int|string)> $packages
     * @param  list<string>  $detectedFiles
     * @param  list<string>  $reasons
     * @return list<DetectedProcess>
     */
    private function detectProcesses(
        array $packages,
        string $root,
        ?string $framework,
        array &$detectedFiles,
        array &$reasons,
    ): array {
        $processes = [];

        // Horizon worker hint: present in composer require AND a horizon
        // config exists (mirrors the Node/Ruby two-signal rule).
        $hasHorizon = in_array('laravel/horizon', $packages, true);
        $horizonConfigExists = is_file($root.'/config/horizon.php');

        if ($hasHorizon && $horizonConfigExists) {
            $detectedFiles[] = 'config/horizon.php';
            $command = 'php artisan horizon';
            $processes[] = new DetectedProcess(
                type: 'worker',
                name: 'horizon',
                command: $command,
                reason: 'Detected `laravel/horizon` in `composer.json` plus `config/horizon.php` — Laravel queue dashboard with worker.',
            );
            $reasons[] = "Suggested worker process: `{$command}` (Laravel Horizon detected).";
        }

        return $processes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }
}
