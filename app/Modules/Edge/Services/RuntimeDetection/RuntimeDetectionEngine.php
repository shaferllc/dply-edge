<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * Runs every registered {@see RuntimeDetector} against a checked-out repo
 * and picks the best match.
 *
 * Selection rule:
 *   1. Confidence: high > medium > low.
 *   2. Tie-breaker by runtime priority (manifest specificity): PHP > Ruby >
 *      Node > Python > Go > Static. PHP and Ruby apps commonly carry a
 *      package.json for their assets, so their own manifest wins.
 *
 * The priority order reflects how distinctive each detector's trigger file
 * is. composer.json / package.json / Gemfile / go.mod uniquely identify
 * their runtimes; static-site signals (a plain index.html, generic config
 * files) overlap more, so static is the lowest-priority tie-breaker. The
 * common Vite case (package.json + index.html, both medium confidence)
 * resolves to Node, which is what the user expects.
 *
 * Detectors that return null are skipped. Result includes every non-null
 * detection in `all` so the "Detection details" UI panel can surface
 * alternatives alongside the chosen runtime.
 */
final class RuntimeDetectionEngine
{
    private const RUNTIME_PRIORITY = [
        'php' => 1,
        // Rails apps ship a package.json for assets; the Gemfile is the app.
        'ruby' => 2,
        'node' => 3,
        'python' => 4,
        'go' => 5,
        'static' => 6,
    ];

    private const CONFIDENCE_RANK = [
        'high' => 3,
        'medium' => 2,
        'low' => 1,
    ];

    /**
     * @param  iterable<RuntimeDetector>  $detectors
     */
    public function __construct(private iterable $detectors) {}

    public function detect(string $workingDirectory): RuntimeDetectionResult
    {
        $detections = [];
        foreach ($this->detectors as $detector) {
            $result = $detector->detect($workingDirectory);
            if ($result !== null) {
                $detections[] = $result;
            }
        }

        return new RuntimeDetectionResult(
            best: $this->pickBest($detections),
            all: $detections,
        );
    }

    /**
     * @param  list<RuntimeDetection>  $detections
     */
    private function pickBest(array $detections): ?RuntimeDetection
    {
        if ($detections === []) {
            return null;
        }

        // Stable, deterministic ordering: primary by confidence (high first),
        // secondary by runtime priority (lower number first). usort is not
        // stable in PHP, but we explicitly compare both keys so we never fall
        // back to the unstable tiebreaker.
        $sorted = $detections;
        usort($sorted, function (RuntimeDetection $a, RuntimeDetection $b): int {
            $confDiff = (self::CONFIDENCE_RANK[$b->confidence] ?? 0)
                - (self::CONFIDENCE_RANK[$a->confidence] ?? 0);
            if ($confDiff !== 0) {
                return $confDiff;
            }

            return (self::RUNTIME_PRIORITY[$a->runtime] ?? 999)
                - (self::RUNTIME_PRIORITY[$b->runtime] ?? 999);
        });

        return $sorted[0];
    }
}
