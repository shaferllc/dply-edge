<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * The one set of rules for "which JS framework is this and how does it
 * build". Shared by the create page's GitHub API fast path and the clone
 * path ({@see NodeRuntimeDetector}) so the same repo can't come out static
 * on one and server-rendered on the other.
 *
 *   - Next.js is static only with `output: 'export'` (or `next export`);
 *     otherwise it gets a start command, which marks it server-rendered.
 *   - Nuxt always builds with `generate`, so the command matches the
 *     `.output/public` static output (a plain `nuxt build` is a server).
 */
final class NodeFrameworkRules
{
    /** Dependency → framework, most specific first; plain HTTP servers last. */
    private const FRAMEWORKS = [
        '@shaferllc/keel' => 'keel',
        '@nestjs/core' => 'nest',
        'next' => 'next',
        'nuxt' => 'nuxt',
        '@remix-run/node' => 'remix',
        '@remix-run/react' => 'remix',
        '@remix-run/dev' => 'remix',
        '@remix-run/cloudflare' => 'remix',
        'remix' => 'remix',
        '@sveltejs/kit' => 'sveltekit',
        'astro' => 'astro',
        'gatsby' => 'gatsby',
        'vitepress' => 'vitepress',
        '@docusaurus/core' => 'docusaurus',
        '@11ty/eleventy' => 'eleventy',
        'hono' => 'hono',
        'vite' => 'vite',
        'express' => 'express',
        'fastify' => 'fastify',
        'koa' => 'koa',
    ];

    private const OUTPUT_DIRS = [
        'keel' => 'public',
        'next' => 'out',
        'nuxt' => '.output/public',
        'remix' => 'build/client',
        'sveltekit' => 'build',
        'astro' => 'dist',
        'gatsby' => 'public',
        'vitepress' => 'docs/.vitepress/dist',
        'docusaurus' => 'build',
        'eleventy' => '_site',
        'hono' => 'dist',
        'vite' => 'dist',
    ];

    /** Build when package.json has no `build` script. */
    private const DEFAULT_BUILDS = [
        'keel' => 'npm run css:build --if-present',
        'vitepress' => 'npm run docs:build',
        'eleventy' => 'npx @11ty/eleventy',
    ];

    private const NEXT_CONFIGS = ['next.config.js', 'next.config.mjs', 'next.config.ts', 'next.config.cjs'];

    /**
     * @param  array<string, mixed>  $pkg  parsed package.json
     * @param  callable(string): ?string  $readFile  repo-relative file → contents (null when absent)
     * @return array{framework: string, build_command: ?string, start_command: ?string, output_dir: ?string, reasons: list<string>}
     */
    public static function plan(array $pkg, callable $readFile): array
    {
        $deps = [];
        foreach (['dependencies', 'devDependencies', 'peerDependencies'] as $section) {
            if (is_array($pkg[$section] ?? null)) {
                $deps += $pkg[$section];
            }
        }
        $scripts = is_array($pkg['scripts'] ?? null) ? $pkg['scripts'] : [];
        $script = static fn (string $name): string => is_string($scripts[$name] ?? null) ? trim($scripts[$name]) : '';

        $framework = 'node';
        $reasons = [];
        foreach (self::FRAMEWORKS as $dependency => $key) {
            if (array_key_exists($dependency, $deps)) {
                $framework = $key;
                $reasons[] = "Detected {$key} from `package.json` dependency `{$dependency}`.";
                break;
            }
        }

        $build = $script('build') !== '' ? 'npm run build' : (self::DEFAULT_BUILDS[$framework] ?? null);
        $output = self::OUTPUT_DIRS[$framework] ?? ($framework === 'node' && $script('build') !== '' ? 'dist' : null);
        $start = $script('start') !== '' ? 'npm start' : (is_string($pkg['main'] ?? null) && trim($pkg['main']) !== '' ? 'node '.trim($pkg['main']) : null);

        if ($framework === 'next') {
            if (self::nextIsStaticExport($script('build'), $readFile)) {
                $start = null;
                $reasons[] = 'Next.js static export (`output: \'export\'`) — builds to `out`.';
            } else {
                $start ??= 'next start';
                $reasons[] = 'Next.js without `output: \'export\'` renders on the server.';
            }
        }

        if ($framework === 'nuxt') {
            $build = $script('generate') !== '' ? 'npm run generate' : 'npx nuxi generate';
            $start = null;
            $reasons[] = 'Nuxt builds with `generate` so the static `.output/public` is complete.';
        }

        return [
            'framework' => $framework,
            'build_command' => $build,
            'start_command' => $start,
            'output_dir' => $output,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  callable(string): ?string  $readFile
     */
    private static function nextIsStaticExport(string $buildScript, callable $readFile): bool
    {
        if (preg_match('/\bnext\s+export\b/', $buildScript) === 1) {
            return true;
        }

        foreach (self::NEXT_CONFIGS as $file) {
            $contents = $readFile($file);
            if (is_string($contents) && $contents !== '') {
                return preg_match('/\boutput\s*:\s*[\'"`]export[\'"`]/', $contents) === 1;
            }
        }

        return false;
    }
}
