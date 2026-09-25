<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * Frontend install + build when a PHP/Ruby app also ships a package.json.
 *
 * Reads the repo's own instructions: package.json scripts named build,
 * production, or prod, then composer.json scripts that invoke npm, pnpm,
 * yarn, bun, or vite. The installer follows the lockfile. Default Laravel
 * is artisan + composer.json + Vite and no lockfile in the public skeleton,
 * so that case is `npm install`, not `npm ci`.
 */
final class FrontendAssetBuild
{
    /**
     * @param  array<string, mixed>  $package  Decoded package.json
     * @param  array<string, mixed>|null  $composer  Decoded composer.json, when package.json has no compile script
     */
    public static function commandForPackage(array $package, ?string $root = null, ?array $composer = null): ?string
    {
        $script = self::scriptName($package);
        if ($script !== null) {
            return self::installCommand($root).' && '.self::runScript($root, $script);
        }

        $build = $composer !== null ? self::buildFromScripts($composer) : null;
        if ($build === null) {
            return null;
        }

        return self::installCommand($root).' && '.$build;
    }

    /**
     * Artisan commands in composer scripts that publish compiled assets.
     * Migrate, key generation, and the dev server are not asset builds.
     *
     * @param  array<string, mixed>  $composer
     * @return list<string>
     */
    public static function phpAssetCommands(array $composer): array
    {
        $commands = [];
        foreach (self::scriptLines($composer) as $line) {
            if (preg_match('/^@php\s+artisan\s+(.+)$/', $line, $matches) !== 1) {
                continue;
            }
            $artisan = $matches[1];
            if (preg_match('/[;&|`$<>\\\\]/', $artisan) === 1) {
                continue;
            }
            if (preg_match('/\b(migrate|key:generate|package:discover|config:|test|serve|queue:|pail)\b/', $artisan) === 1) {
                continue;
            }
            if (preg_match('/\b(vendor:publish|assets|vite|mix)\b/', $artisan) !== 1) {
                continue;
            }
            $commands[] = 'php artisan '.$artisan;
        }

        return $commands;
    }

    public static function commandForDirectory(string $root): ?string
    {
        $steps = self::stepsForDirectory($root);

        return $steps === null ? null : $steps['install'].' && '.$steps['build'];
    }

    /**
     * Install and build as separate shell steps, for a Dockerfile that
     * caches node_modules until the lockfile changes.
     *
     * @return array{install: string, build: string}|null
     */
    public static function stepsForDirectory(string $root): ?array
    {
        $root = rtrim($root, '/');
        if (! is_file($root.'/package.json')) {
            return null;
        }

        $package = self::readJson($root.'/package.json');
        if ($package === null) {
            return null;
        }

        $script = self::scriptName($package);
        $workspaces = [];
        if ($script !== null) {
            $build = self::runScript($root, $script);
        } else {
            $workspaces = self::workspacePackages($root, $package);
            $build = $workspaces === []
                ? self::buildFromScripts(self::readJson($root.'/composer.json') ?? [])
                : implode(' && ', array_map(
                    static fn (string $dir): string => self::runScript($root, self::scriptName(self::readJson($root.'/'.$dir.'/package.json') ?? []) ?? 'build', $dir),
                    $workspaces,
                ));
        }

        if ($build === null) {
            return null;
        }

        return [
            'install' => self::installCommand($root),
            'build' => $build,
            'workspaces' => $workspaces,
        ];
    }

    /**
     * Workspace directories whose package.json has a compile script.
     * The root package.json often only lists workspaces and a postinstall hook.
     *
     * @param  array<string, mixed>  $package
     * @return list<string>
     */
    private static function workspacePackages(string $root, array $package): array
    {
        $workspaces = $package['workspaces'] ?? null;
        $patterns = [];
        if (is_array($workspaces)) {
            $patterns = isset($workspaces['packages']) && is_array($workspaces['packages'])
                ? $workspaces['packages']
                : (array_is_list($workspaces) ? $workspaces : []);
        }

        $dirs = [];
        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || str_contains($pattern, '..')) {
                continue;
            }
            $pattern = trim($pattern, '/');
            $matches = glob($root.'/'.$pattern, GLOB_ONLYDIR) ?: [];
            if ($matches === [] && is_dir($root.'/'.$pattern)) {
                $matches = [$root.'/'.$pattern];
            }
            foreach ($matches as $dir) {
                $relative = ltrim(substr($dir, strlen($root)), '/');
                if ($relative === '' || str_starts_with($relative, 'node_modules/') || str_starts_with($relative, 'vendor/')) {
                    continue;
                }
                $nested = self::readJson($dir.'/package.json');
                if ($nested !== null && self::scriptName($nested) !== null) {
                    $dirs[] = $relative;
                }
            }
        }

        return array_values(array_unique($dirs));
    }

    /**
     * @param  array<string, mixed>  $package
     */
    /**
     * `npm run build:ssr` for an Inertia app that has one (Laravel's starter
     * kits do), else null. Detected, like Octane: the app opted in by
     * writing the script and an ssr entry.
     */
    public static function inertiaSsrBuild(string $root): ?string
    {
        $package = self::readJson(rtrim($root, '/').'/package.json') ?? [];
        $script = $package['scripts']['build:ssr'] ?? null;
        $deps = array_keys(array_merge(
            is_array($package['dependencies'] ?? null) ? $package['dependencies'] : [],
            is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : [],
        ));
        $inertia = array_filter($deps, static fn ($name): bool => str_starts_with((string) $name, '@inertiajs/'));

        return is_string($script) && trim($script) !== '' && $inertia !== []
            ? self::runScript($root, 'build:ssr')
            : null;
    }

    private static function scriptName(array $package): ?string
    {
        $scripts = is_array($package['scripts'] ?? null) ? $package['scripts'] : [];
        foreach (['build', 'production', 'prod'] as $name) {
            $value = $scripts[$name] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * A composer script that compiles assets when package.json has no
     * build/production/prod script. Install lines are ignored — the lockfile
     * already chooses the installer. Migrate and key generation are not builds.
     *
     * @param  array<string, mixed>  $composer
     */
    private static function buildFromScripts(array $composer): ?string
    {
        foreach (self::scriptLines($composer) as $line) {
            if (preg_match('/[;&|`$<>\\\\]/', $line) === 1) {
                continue;
            }
            if (preg_match('/^(?:npm|pnpm|yarn|bun)\s+run\s+(?:build|production|prod)\b/', $line) === 1) {
                return $line;
            }
            if (preg_match('/^(?:npx\s+)?vite\s+build\b/', $line) === 1) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $composer
     * @return list<string>
     */
    private static function scriptLines(array $composer): array
    {
        $scripts = is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
        $lines = [];
        array_walk_recursive($scripts, static function (mixed $value) use (&$lines): void {
            if (is_string($value) && trim($value) !== '') {
                $lines[] = trim($value);
            }
        });

        return $lines;
    }

    private static function runScript(?string $root, string $name, ?string $directory = null): string
    {
        if ($directory === null) {
            return match (self::packageManager($root)) {
                'pnpm' => "pnpm run {$name}",
                'yarn' => "yarn run {$name}",
                default => "npm run {$name}",
            };
        }

        return match (self::packageManager($root)) {
            'pnpm' => "pnpm --dir {$directory} run {$name}",
            'yarn' => "yarn --cwd {$directory} run {$name}",
            default => "npm run {$name} --prefix {$directory}",
        };
    }

    private static function installCommand(?string $root): string
    {
        return match (self::packageManager($root)) {
            'pnpm' => 'pnpm install --frozen-lockfile',
            'yarn' => 'yarn install --frozen-lockfile',
            'npm' => 'npm ci',
            default => 'npm install',
        };
    }

    private static function packageManager(?string $root): string
    {
        if ($root === null) {
            return 'none';
        }

        return match (true) {
            is_file($root.'/pnpm-lock.yaml') => 'pnpm',
            is_file($root.'/yarn.lock') => 'yarn',
            is_file($root.'/package-lock.json') => 'npm',
            default => 'none',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
