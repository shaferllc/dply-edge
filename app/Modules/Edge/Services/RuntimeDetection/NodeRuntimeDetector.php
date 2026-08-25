<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;


/**
 * Detects Node.js apps from package.json and related repo signals.
 *
 * Pre-fills:
 *   - runtime: "node"
 *   - version: from .tool-versions / .nvmrc / package.json#engines.node
 *   - framework: next | nuxt | astro | nest | remix | sveltekit | "node"
 *   - build: package.json#scripts.build (when present)
 *   - start: package.json#scripts.start, then main, then conventional fallbacks
 *   - app port: parsed from start/dev script flags, then framework defaults
 *   - processes: BullMQ/Bull worker hint when a `worker` or `queue` script exists
 */
final class NodeRuntimeDetector implements RuntimeDetector
{
    public function runtime(): string
    {
        return 'node';
    }

    public function detect(string $workingDirectory): ?RuntimeDetection
    {
        $packageJsonPath = rtrim($workingDirectory, '/').'/package.json';
        if (! is_file($packageJsonPath)) {
            return null;
        }

        $packageJson = $this->readJson($packageJsonPath);
        if ($packageJson === null) {
            return null;
        }

        $detectedFiles = ['package.json'];
        $reasons = ['Found `package.json` at the repo root.'];

        $version = $this->detectVersion($workingDirectory, $packageJson, $detectedFiles, $reasons);
        $deps = $this->collectDependencyKeys($packageJson);
        $framework = $this->detectFramework($deps, $reasons);

        $scripts = is_array($packageJson['scripts'] ?? null) ? $packageJson['scripts'] : [];

        $buildCommand = $this->detectBuildCommand($scripts, $reasons);
        $startCommand = $this->detectStartCommand($scripts, $packageJson, $framework, $reasons);
        $appPort = $this->detectAppPort($scripts, $framework, $reasons);
        $processes = $this->detectProcesses($scripts, $deps, $reasons);
        $outputDirectory = $this->detectOutputDirectory($framework, $reasons);

        $confidence = $framework !== 'node' ? 'high' : 'medium';

        return new RuntimeDetection(
            runtime: 'node',
            version: $version,
            framework: $framework,
            buildCommand: $buildCommand,
            startCommand: $startCommand,
            appPort: $appPort,
            detectedFiles: $detectedFiles,
            reasons: $reasons,
            processes: $processes,
            confidence: $confidence,
            outputDirectory: $outputDirectory,
        );
    }

    /**
     * @param  array<string, mixed> $packageJson
     * @param  list<string>  $detectedFiles
     * @param  list<string>  $reasons
     */
    private function detectVersion(
        string $workingDirectory,
        array $packageJson,
        array &$detectedFiles,
        array &$reasons,
    ): ?string {
        // .tool-versions is the highest-priority pin (mise / asdf honor it).
        $toolVersionsPath = rtrim($workingDirectory, '/').'/.tool-versions';
        if (is_file($toolVersionsPath)) {
            $contents = (string) file_get_contents($toolVersionsPath);
            if (preg_match('/^node\s+(\S+)/m', $contents, $matches) === 1) {
                $detectedFiles[] = '.tool-versions';
                $reasons[] = "Pinned Node {$matches[1]} from `.tool-versions`.";

                return trim($matches[1]);
            }
        }

        $nvmrcPath = rtrim($workingDirectory, '/').'/.nvmrc';
        if (is_file($nvmrcPath)) {
            $version = trim((string) file_get_contents($nvmrcPath));
            if ($version !== '') {
                $detectedFiles[] = '.nvmrc';
                $version = ltrim($version, 'vV');
                $reasons[] = "Pinned Node {$version} from `.nvmrc`.";

                return $version;
            }
        }

        $engines = is_array($packageJson['engines'] ?? null) ? $packageJson['engines'] : [];
        $engineNode = $engines['node'] ?? null;
        if (is_string($engineNode) && trim($engineNode) !== '') {
            $reasons[] = "Pinned Node {$engineNode} from `package.json#engines.node`.";

            return trim($engineNode);
        }

        return null;
    }

    /**
     * @param  list<string> $deps
     * @param  list<string>  $reasons
     */
    private function detectFramework(array $deps, array &$reasons): string
    {
        $frameworks = [
            // Keel before hono-adjacent stacks — apps declare both.
            '@shaferllc/keel' => 'keel',
            'next' => 'next',
            'nuxt' => 'nuxt',
            'astro' => 'astro',
            '@nestjs/core' => 'nest',
            'remix' => 'remix',
            '@remix-run/node' => 'remix',
            '@sveltejs/kit' => 'sveltekit',
        ];

        foreach ($frameworks as $packageName => $frameworkKey) {
            if (in_array($packageName, $deps, true)) {
                $reasons[] = "Detected {$frameworkKey} from `package.json` dependency `{$packageName}`.";

                return $frameworkKey;
            }
        }

        return 'node';
    }

    /**
     * @param  array<string, mixed> $scripts
     * @param  list<string>  $reasons
     */
    private function detectBuildCommand(array $scripts, array &$reasons): ?string
    {
        if (isset($scripts['build']) && is_string($scripts['build']) && trim($scripts['build']) !== '') {
            $reasons[] = 'Suggested build: `npm run build` (script defined in `package.json`).';

            return 'npm run build';
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $scripts
     * @param  array<string, mixed> $packageJson
     * @param  list<string>  $reasons
     */
    private function detectStartCommand(
        array $scripts,
        array $packageJson,
        ?string $framework,
        array &$reasons,
    ): ?string {
        if (isset($scripts['start']) && is_string($scripts['start']) && trim($scripts['start']) !== '') {
            $reasons[] = 'Suggested start: `npm start` (script defined in `package.json`).';

            return 'npm start';
        }

        // Framework-specific defaults when there's no `start` script.
        if ($framework === 'next') {
            $reasons[] = 'Suggested start: `next start` (Next.js default).';

            return 'next start';
        }

        if ($framework === 'nuxt') {
            $reasons[] = 'Suggested start: `node .output/server/index.mjs` (Nuxt default).';

            return 'node .output/server/index.mjs';
        }

        $main = $packageJson['main'] ?? null;
        if (is_string($main) && trim($main) !== '') {
            $reasons[] = "Suggested start: `node {$main}` (from `package.json#main`).";

            return "node {$main}";
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $scripts
     * @param  list<string>  $reasons
     */
    private function detectAppPort(array $scripts, ?string $framework, array &$reasons): int
    {
        // Honor explicit `--port=` / `PORT=` values in start/dev scripts when present.
        foreach (['start', 'dev', 'preview'] as $scriptKey) {
            $script = is_string($scripts[$scriptKey] ?? null) ? $scripts[$scriptKey] : '';
            if ($script === '') {
                continue;
            }
            if (preg_match('/(?:--port[ =]|PORT=)(\d{2,5})/', $script, $matches) === 1) {
                $reasons[] = "Suggested app port {$matches[1]} (from `scripts.{$scriptKey}`).";

                return (int) $matches[1];
            }
        }

        // Common framework defaults.
        if (in_array($framework, ['next', 'nuxt', 'remix', 'sveltekit'], true)) {
            return 3000;
        }

        return 3000;
    }

    /**
     * @param  array<string, mixed> $scripts
     * @param  list<string> $deps
     * @param  list<string>  $reasons
     * @return list<DetectedProcess>
     */
    private function detectProcesses(array $scripts, array $deps, array &$reasons): array
    {
        $processes = [];

        // BullMQ / Bull worker hint: present in deps and a `worker` or `queue` script.
        $hasBullMq = in_array('bullmq', $deps, true) || in_array('bull', $deps, true);
        $workerScript = null;
        foreach (['worker', 'workers', 'queue', 'jobs'] as $scriptKey) {
            if (isset($scripts[$scriptKey]) && is_string($scripts[$scriptKey]) && trim($scripts[$scriptKey]) !== '') {
                $workerScript = $scriptKey;
                break;
            }
        }

        if ($hasBullMq && $workerScript !== null) {
            $processes[] = new DetectedProcess(
                type: 'worker',
                name: 'worker',
                command: "npm run {$workerScript}",
                reason: "Detected BullMQ/Bull in dependencies plus `scripts.{$workerScript}` — likely a background queue worker.",
            );
            $reasons[] = "Suggested worker process: `npm run {$workerScript}` (BullMQ/Bull detected).";
        }

        return $processes;
    }

    /**
     * @return list<string>
     * @param  array<string, mixed> $packageJson
     */
    private function collectDependencyKeys(array $packageJson): array
    {
        $deps = [];
        foreach (['dependencies', 'devDependencies', 'peerDependencies'] as $key) {
            $section = $packageJson[$key] ?? null;
            if (! is_array($section)) {
                continue;
            }
            foreach (array_keys($section) as $name) {
                if (is_string($name)) {
                    $deps[] = $name;
                }
            }
        }

        return array_values(array_unique($deps));
    }

    /**
     * @param  list<string>  $reasons
     */
    private function detectOutputDirectory(?string $framework, array &$reasons): ?string
    {
        $directory = match ($framework) {
            'next' => 'out',
            'nuxt' => '.output/public',
            'astro' => 'dist',
            'sveltekit' => 'build',
            'keel' => 'public',
            'vite', 'vue', 'react', 'svelte', 'remix', 'nest' => 'dist',
            default => null,
        };

        if ($directory === null) {
            return null;
        }

        $reasons[] = "Suggested output directory: `{$directory}` ({$framework} convention).";

        return $directory;
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
