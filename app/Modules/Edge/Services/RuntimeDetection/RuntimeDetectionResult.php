<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * Aggregate result of running every registered {@see RuntimeDetector} against
 * a checked-out repository.
 *
 * `best` is the detection the orchestrator selected to drive the site-create
 * form's pre-fill (highest-confidence non-null result, with deterministic
 * tie-breaking by runtime priority — see {@see RuntimeDetectionEngine}).
 *
 * `all` is every non-null detection so the "Detection details" UI panel can
 * surface alternatives and explain why the chosen runtime won.
 */
final readonly class RuntimeDetectionResult
{
    /**
     * @param  list<RuntimeDetection>  $all
     */
    public function __construct(
        public ?RuntimeDetection $best,
        public array $all,
    ) {}

    public static function empty(): self
    {
        return new self(best: null, all: []);
    }
}
