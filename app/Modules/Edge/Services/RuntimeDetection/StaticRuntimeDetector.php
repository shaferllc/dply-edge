<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * Detects static-site repos (NGINX-served, no language runtime at request time).
 *
 * Pre-fills:
 *   - runtime: "static"
 *   - framework: jekyll | hugo | eleventy | "static"
 *   - build: framework-aware (jekyll build / hugo --minify / 11ty)
 *   - start: null (NGINX serves files; no long-running process)
 *   - app port: null (no upstream)
 *   - processes: none
 *
 * Triggers on a Jekyll/Hugo/Eleventy config file OR a plain `index.html`
 * at the repo root. Note: a repo that has both `package.json` and
 * `index.html` (e.g. Vite) will also be picked up by NodeRuntimeDetector;
 * the orchestrator picks the higher-confidence result, so the Static
 * detector only returns "high" confidence for repos that match a known
 * static-site generator.
 */
final class StaticRuntimeDetector implements RuntimeDetector
{
    public function runtime(): string
    {
        return 'static';
    }

    public function detect(string $workingDirectory): ?RuntimeDetection
    {
        $root = rtrim($workingDirectory, '/');

        $detectedFiles = [];
        $reasons = [];

        $framework = $this->detectFramework($root, $detectedFiles, $reasons);
        $hasIndexHtml = is_file($root.'/index.html');

        if ($framework === null && ! $hasIndexHtml) {
            return null;
        }

        if ($hasIndexHtml) {
            $detectedFiles[] = 'index.html';
            $reasons[] = 'Found `index.html` at the repo root — treating as a plain static site.';
        }

        $buildCommand = $this->detectBuildCommand($framework, $reasons);
        $outputDirectory = $this->detectOutputDirectory($root, $framework, $reasons);

        $confidence = $framework !== null ? 'high' : 'medium';

        return new RuntimeDetection(
            runtime: 'static',
            version: null,
            framework: $framework ?? 'static',
            buildCommand: $buildCommand,
            startCommand: null,
            appPort: null,
            detectedFiles: $detectedFiles,
            reasons: $reasons,
            processes: [],
            confidence: $confidence,
            outputDirectory: $outputDirectory,
        );
    }

    /**
     * @param  list<string>  $detectedFiles
     * @param  list<string>  $reasons
     */
    private function detectFramework(string $root, array &$detectedFiles, array &$reasons): ?string
    {
        if (is_file($root.'/_config.yml')) {
            $detectedFiles[] = '_config.yml';
            $reasons[] = 'Detected jekyll from `_config.yml`.';

            return 'jekyll';
        }

        if (is_file($root.'/hugo.toml')) {
            $detectedFiles[] = 'hugo.toml';
            $reasons[] = 'Detected hugo from `hugo.toml`.';

            return 'hugo';
        }

        if (is_file($root.'/config.toml')) {
            $contents = (string) @file_get_contents($root.'/config.toml');
            // Distinguish a Hugo config.toml from any other tool's config.toml
            // by looking for Hugo-specific keys.
            if (preg_match('/^\s*(?:baseURL|theme|languageCode)\s*=/m', $contents) === 1) {
                $detectedFiles[] = 'config.toml';
                $reasons[] = 'Detected hugo from `config.toml`.';

                return 'hugo';
            }
        }

        foreach (['.eleventy.js', 'eleventy.config.js', 'eleventy.config.cjs', 'eleventy.config.mjs'] as $candidate) {
            if (is_file($root.'/'.$candidate)) {
                $detectedFiles[] = $candidate;
                $reasons[] = "Detected eleventy from `{$candidate}`.";

                return 'eleventy';
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function detectBuildCommand(?string $framework, array &$reasons): ?string
    {
        if ($framework === 'jekyll') {
            $reasons[] = 'Suggested build: `bundle exec jekyll build` (Jekyll convention).';

            return 'bundle exec jekyll build';
        }

        if ($framework === 'hugo') {
            $reasons[] = 'Suggested build: `hugo --minify` (Hugo convention).';

            return 'hugo --minify';
        }

        if ($framework === 'eleventy') {
            $reasons[] = 'Suggested build: `npx @11ty/eleventy` (Eleventy convention).';

            return 'npx @11ty/eleventy';
        }

        return null;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function detectOutputDirectory(string $root, ?string $framework, array &$reasons): ?string
    {
        if ($framework === 'eleventy') {
            $configured = $this->parseEleventyOutputDirectory($root);
            if ($configured !== null) {
                $reasons[] = "Suggested output directory: `{$configured}` (from Eleventy config).";

                return $configured;
            }

            $reasons[] = 'Suggested output directory: `_site` (Eleventy default).';

            return '_site';
        }

        if ($framework === 'jekyll') {
            $reasons[] = 'Suggested output directory: `_site` (Jekyll default).';

            return '_site';
        }

        if ($framework === 'hugo') {
            $reasons[] = 'Suggested output directory: `public` (Hugo default).';

            return 'public';
        }

        if ($framework === null) {
            $reasons[] = 'Suggested output directory: `.` (static files at repo root).';

            return '.';
        }

        return null;
    }

    private function parseEleventyOutputDirectory(string $root): ?string
    {
        foreach (['.eleventy.js', 'eleventy.config.js', 'eleventy.config.cjs', 'eleventy.config.mjs'] as $candidate) {
            $path = $root.'/'.$candidate;
            if (! is_file($path)) {
                continue;
            }

            $contents = (string) @file_get_contents($path);
            if ($contents === '') {
                continue;
            }

            if (preg_match('/output\s*:\s*["\']([^"\']+)["\']/i', $contents, $matches) === 1) {
                $dir = trim((string) $matches[1]);
                if ($dir !== '') {
                    return $dir;
                }
            }
        }

        return null;
    }
}
