<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * Detects Node.js apps from package.json and related repo signals.
 *
 * Pre-fills:
 *   - runtime: "node"
 *   - version: from .tool-versions / .nvmrc / package.json#engines.node
 *   - framework, build, start, output: {@see NodeFrameworkRules} (shared
 *     with the create page's GitHub fast path)
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

        // Same rules as the create page's GitHub fast path.
        $root = rtrim($workingDirectory, '/');
        $rules = NodeFrameworkRules::plan($packageJson, static function (string $file) use ($root): ?string {
            $contents = is_file($root.'/'.$file) ? @file_get_contents($root.'/'.$file) : false;

            return $contents === false ? null : $contents;
        });
        array_push($reasons, ...$rules['reasons']);
        $framework = $rules['framework'];

        $scripts = is_array($packageJson['scripts'] ?? null) ? $packageJson['scripts'] : [];

        $buildCommand = $rules['build_command'];
        $startCommand = $rules['start_command'];
        $appPort = $this->detectAppPort($scripts, $framework, $reasons);
        $processes = $this->detectProcesses($scripts, $deps, $reasons);
        $outputDirectory = $rules['output_dir'];

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
     * @param  array<string, mixed>  $packageJson
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
     * @param  array<string, mixed>  $scripts
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
     * @param  array<string, mixed>  $scripts
     * @param  list<string>  $deps
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
     * @param  array<string, mixed>  $packageJson
     * @return list<string>
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
