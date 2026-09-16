<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * A long-running process suggested by a runtime detector.
 *
 * Maps directly onto a {@see SiteProcess} row that the user
 * can accept (one-click create) or ignore at site-create time.
 */
final readonly class DetectedProcess
{
    public function __construct(
        public string $type,    // SiteProcess::TYPE_* (worker, scheduler, etc.)
        public string $name,    // Process name (e.g. "sidekiq", "horizon", "celery-worker")
        public string $command, // The command to run
        public string $reason,  // Why we suggest this (shown in detection panel UI)
    ) {}
}
