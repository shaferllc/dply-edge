<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * Per-runtime repository detector.
 *
 * Implementations inspect a checked-out repository directory and return
 * a {@see RuntimeDetection} when they recognize the repo as belonging to
 * their runtime, or null when nothing matches.
 *
 * The site-create flow runs each registered detector in turn; the highest-
 * confidence non-null result wins. Detectors should be cheap (file existence
 * checks + small JSON / text parsing) and side-effect-free.
 */
interface RuntimeDetector
{
    /**
     * Return the canonical runtime key this detector is responsible for
     * (one of DplyManifest::ALLOWED_RUNTIMES). Used for logging and so the
     * orchestrator can avoid running detectors for runtimes the target
     * server hasn't installed.
     */
    public function runtime(): string;

    /**
     * Inspect $workingDirectory and return a populated RuntimeDetection if
     * the repo matches this runtime, or null if it does not.
     */
    public function detect(string $workingDirectory): ?RuntimeDetection;
}
