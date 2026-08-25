<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * Result of running a per-runtime detector against a checked-out repo.
 *
 * Detectors return null when the runtime is not present (no manifest file
 * found, no relevant signals); they return a populated instance when they
 * recognize the repo as belonging to their runtime.
 *
 * The instance is fed into the site-create form to pre-fill runtime,
 * runtime_version, build_command, the web SiteProcess command, and any
 * suggested non-web processes (workers, schedulers).
 *
 * The {@see reasons} list is surfaced in the UI's "Detection details"
 * panel so users can see *why* dply made each inference.
 */
final readonly class RuntimeDetection
{
    /**
     * @param  string  $runtime  one of DplyManifest::ALLOWED_RUNTIMES
     * @param  ?string  $version  detected version string (or null if undetectable)
     * @param  ?string  $framework  detected framework key (e.g. "next", "django", "rails", "laravel")
     * @param  list<string> $detectedFiles  repo-relative paths of files we read to make the call
     * @param  list<string> $reasons  human-readable explanations of each inference
     * @param  list<\App\Modules\Edge\Services\RuntimeDetection\DetectedProcess> $processes  suggested non-web processes (workers, schedulers)
     * @param  string  $confidence  one of "low", "medium", "high"
     * @param  ?string  $outputDirectory  repo-relative folder containing built static assets
     */
    public function __construct(
        public string $runtime,
        public ?string $version,
        public ?string $framework,
        public ?string $buildCommand,
        public ?string $startCommand,
        public ?int $appPort,
        public array $detectedFiles,
        public array $reasons,
        public array $processes,
        public string $confidence,
        public ?string $outputDirectory = null,
    ) {}
}
