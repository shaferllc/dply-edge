<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

use App\Modules\Edge\Services\Manifest\DplyManifest;

/**
 * Single merged plan for what to do with a checked-out repository.
 *
 * Combines the dply.yaml manifest (when present) with the detector engine's
 * best detection, applying the precedence rule from the strategy memory:
 * **manifest → auto-detection → dashboard default**.
 *
 * Each top-level field carries provenance via {@see $sources}, so the UI's
 * "Detection details" panel can show which fields came from dply.yaml,
 * which were inferred, and which the user will need to fill in (a "default"
 * entry means we couldn't compute the field — the dashboard supplies the
 * baseline).
 *
 * Non-web processes (workers/schedulers) merge by name: manifest entries
 * win where they collide with detector suggestions, and detector-only
 * suggestions are appended so the user can accept or ignore each one.
 */
final readonly class RepositoryRuntimePlan
{
    public const SOURCE_MANIFEST = 'manifest';

    public const SOURCE_DETECTION = 'detection';

    public const SOURCE_DEFAULT = 'default';

    /**
     * @param  array<string, mixed> $sources  field => one of the SOURCE_* constants
     * @param  list<\App\Modules\Edge\Services\RuntimeDetection\DetectedProcess> $processes  merged worker/scheduler suggestions (excludes the `web` process)
     * @param  list<mixed> $reasons  combined reasons from manifest+detection, in the order they were produced
     * @param  list<string> $warnings  manifest parse warnings (forward-compat unknown keys, etc.)
     */
    public function __construct(
        public string $runtime,
        public ?string $version,
        public ?string $framework,
        public ?string $buildCommand,
        public ?string $startCommand,
        public ?int $appPort,
        public string $confidence,
        public array $processes,
        public array $sources,
        public array $reasons,
        public array $warnings,
        public ?DplyManifest $manifest,
        public ?RuntimeDetection $detection,
    ) {}

    public function hasManifest(): bool
    {
        return $this->manifest !== null;
    }

    public function fieldSource(string $field): string
    {
        return $this->sources[$field] ?? self::SOURCE_DEFAULT;
    }
}
