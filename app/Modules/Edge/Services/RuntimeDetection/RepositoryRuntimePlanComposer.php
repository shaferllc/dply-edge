<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

use App\Modules\Edge\Services\Manifest\DplyManifest;
use App\Modules\Edge\Services\Manifest\DplyManifestException;
use App\Modules\Edge\Services\Manifest\DplyManifestParser;

/**
 * Composes a {@see RepositoryRuntimePlan} by merging dply.yaml + detector output.
 *
 * Precedence (per strategy memory):
 *   1. dply.yaml — explicit user intent, wins per-field where present.
 *   2. RuntimeDetectionEngine — auto-detected from repo signals.
 *   3. Caller default — what the dashboard pre-fills if both layers are silent.
 *
 * The composer is field-aware: a manifest with only `runtime: node` will
 * pin the runtime but inherit the detector's framework / version / build /
 * start / processes for everything else.
 *
 * Manifest filenames probed: dply.yaml, dply.yml. (.yaml first, matching
 * the existing parser's default and how the strategy memory describes it.)
 */
final class RepositoryRuntimePlanComposer
{
    public function __construct(
        private RuntimeDetectionEngine $engine,
        private DplyManifestParser $manifestParser,
    ) {}

    public function compose(string $workingDirectory): ?RepositoryRuntimePlan
    {
        $manifest = $this->loadManifest($workingDirectory);
        $detectionResult = $this->engine->detect($workingDirectory);
        $detection = $detectionResult->best;

        if ($manifest === null && $detection === null) {
            return null;
        }

        $sources = [];
        $reasons = [];

        $runtime = $this->pickRuntime($manifest, $detection, $sources, $reasons);
        $version = $this->pickField('version', $manifest?->version, $detection?->version, $sources, $reasons,
            manifestNote: 'Pinned %s from `dply.yaml`.',
        );
        $buildCommand = $this->pickField(
            'build_command',
            $this->joinCommandList($manifest->build ?? []),
            $detection?->buildCommand,
            $sources,
            $reasons,
            manifestNote: 'Build command set by `dply.yaml`.',
        );

        $manifestWebCommand = $this->extractWebCommand($manifest, $runtime);
        $startCommand = $this->pickField(
            'start_command',
            $manifestWebCommand,
            $this->normalizeStartForRuntime($runtime, $detection?->startCommand),
            $sources,
            $reasons,
            manifestNote: 'Start command set by `dply.yaml` (`processes.web`).',
        );

        $appPort = $this->normalizeAppPort($runtime, $detection?->appPort, $sources);
        $framework = $detection?->framework;
        $confidence = $this->computeConfidence($manifest, $detection);
        $processes = $this->mergeProcesses($manifest, $detection);
        $warnings = $manifest->warnings ?? [];

        // Surface detection's own reasoning trail — gives the UI panel the
        // human-readable explanations of every inference.
        if ($detection !== null) {
            foreach ($detection->reasons as $reason) {
                $reasons[] = $reason;
            }
        }

        return new RepositoryRuntimePlan(
            runtime: $runtime,
            version: $version,
            framework: $framework,
            buildCommand: $buildCommand,
            startCommand: $startCommand,
            appPort: $appPort,
            confidence: $confidence,
            processes: $processes,
            sources: $sources,
            reasons: $reasons,
            warnings: $warnings,
            manifest: $manifest,
            detection: $detection,
        );
    }

    private function loadManifest(string $workingDirectory): ?DplyManifest
    {
        $root = rtrim($workingDirectory, '/');
        foreach (['dply.yaml', 'dply.yml'] as $filename) {
            $path = $root.'/'.$filename;
            if (! is_file($path)) {
                continue;
            }
            try {
                return $this->manifestParser->parseFile($path);
            } catch (DplyManifestException) {
                // A malformed manifest is a real problem we'd want to flag in
                // the UI eventually, but for plan composition we fall back to
                // detection-only rather than fail-closed. The detector and
                // dashboard layers can still produce a usable plan.
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $sources
     * @param  list<string>  $reasons
     */
    private function pickRuntime(
        ?DplyManifest $manifest,
        ?RuntimeDetection $detection,
        array &$sources,
        array &$reasons,
    ): string {
        if ($manifest?->runtime !== null) {
            $sources['runtime'] = RepositoryRuntimePlan::SOURCE_MANIFEST;
            $reasons[] = "Runtime pinned to `{$manifest->runtime}` by `dply.yaml`.";

            return $manifest->runtime;
        }

        if ($detection !== null) {
            $sources['runtime'] = RepositoryRuntimePlan::SOURCE_DETECTION;

            return $detection->runtime;
        }

        $sources['runtime'] = RepositoryRuntimePlan::SOURCE_DEFAULT;

        // Caller is responsible for picking a default when both layers are
        // silent. We default to "static" here only as a non-null shape — the
        // composer returns null entirely when both manifest and detection are
        // missing, so this branch is unreachable in practice.
        return 'static';
    }

    /**
     * @template T of string|int
     *
     * @param  T|null  $manifestValue
     * @param  T|null  $detectionValue
     * @param  array<string, mixed>  $sources
     * @param  list<string>  $reasons
     * @return T|null
     */
    private function pickField(
        string $fieldKey,
        $manifestValue,
        $detectionValue,
        array &$sources,
        array &$reasons,
        ?string $manifestNote = null,
    ) {
        if ($manifestValue !== null && $manifestValue !== '') {
            $sources[$fieldKey] = RepositoryRuntimePlan::SOURCE_MANIFEST;
            if ($manifestNote !== null) {
                $reasons[] = sprintf($manifestNote, (string) $manifestValue);
            }

            return $manifestValue;
        }

        if ($detectionValue !== null && $detectionValue !== '') {
            $sources[$fieldKey] = RepositoryRuntimePlan::SOURCE_DETECTION;

            return $detectionValue;
        }

        $sources[$fieldKey] = RepositoryRuntimePlan::SOURCE_DEFAULT;

        return null;
    }

    /**
     * @param  list<string>  $commands
     */
    private function joinCommandList(array $commands): ?string
    {
        $filtered = array_values(array_filter(array_map('trim', $commands), fn ($c) => $c !== ''));
        if ($filtered === []) {
            return null;
        }

        return implode(' && ', $filtered);
    }

    /**
     * Pull the manifest's `web` process command, except for PHP where the
     * `web` key is documented as ignored (FPM is implicit — see strategy
     * memory).
     */
    private function extractWebCommand(?DplyManifest $manifest, string $runtime): ?string
    {
        if ($manifest === null || $runtime === 'php') {
            return null;
        }

        $web = $manifest->processes['web'] ?? null;

        return $web?->command;
    }

    /**
     * For PHP and static, there is no long-running start command — even if a
     * detector accidentally proposed one, normalize it away here so the plan
     * stays consistent with the runtime semantics.
     */
    private function normalizeStartForRuntime(string $runtime, ?string $startCommand): ?string
    {
        if ($runtime === 'php' || $runtime === 'static') {
            return null;
        }

        return $startCommand;
    }

    /**
     * @param  array<string, mixed>  $sources
     */
    private function normalizeAppPort(string $runtime, ?int $detectorPort, array &$sources): ?int
    {
        if ($runtime === 'php' || $runtime === 'static') {
            $sources['app_port'] = RepositoryRuntimePlan::SOURCE_DEFAULT;

            return null;
        }

        if ($detectorPort !== null) {
            $sources['app_port'] = RepositoryRuntimePlan::SOURCE_DETECTION;

            return $detectorPort;
        }

        $sources['app_port'] = RepositoryRuntimePlan::SOURCE_DEFAULT;

        return null;
    }

    private function computeConfidence(?DplyManifest $manifest, ?RuntimeDetection $detection): string
    {
        // An explicit manifest is the strongest signal we have — user intent
        // beats inference.
        if ($manifest !== null && $manifest->runtime !== null) {
            return 'high';
        }

        return $detection->confidence ?? 'low';
    }

    /**
     * Merge non-web processes. Manifest entries by name win over detector
     * suggestions; detector-only suggestions append so the user can opt in
     * to each one in the UI.
     *
     * @return list<DetectedProcess>
     */
    private function mergeProcesses(?DplyManifest $manifest, ?RuntimeDetection $detection): array
    {
        $merged = [];
        $seenNames = [];

        if ($manifest !== null) {
            foreach ($manifest->processes as $name => $process) {
                if ($name === 'web') {
                    // `web` is the upstream process, handled via startCommand.
                    continue;
                }
                $merged[] = new DetectedProcess(
                    type: $this->processTypeFor($name),
                    name: $name,
                    command: $process->command,
                    reason: "Defined in `dply.yaml` under `processes.{$name}`.",
                );
                $seenNames[$name] = true;
            }
        }

        if ($detection !== null) {
            foreach ($detection->processes as $detected) {
                if (isset($seenNames[$detected->name])) {
                    continue;
                }
                $merged[] = $detected;
            }
        }

        return $merged;
    }

    /**
     * Map a manifest process name to the SiteProcess type. Reserved names
     * (`worker`, `scheduler`) get their canonical type; anything else is
     * treated as a custom worker — the user can edit the type in the UI.
     */
    private function processTypeFor(string $name): string
    {
        return match ($name) {
            'scheduler' => 'scheduler',
            default => 'worker',
        };
    }
}
