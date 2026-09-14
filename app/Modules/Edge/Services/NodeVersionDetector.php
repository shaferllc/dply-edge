<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

/**
 * Sniffs a cloned repo for a Node.js version hint and picks the right
 * Docker image to run the build in. Avoids dragging in a full semver
 * parser — the goal is "pick a sane major in our supported set," not
 * literal range satisfaction.
 *
 * Priority order (first hit wins):
 *   1. package.json#engines.node
 *   2. .nvmrc
 *   3. .node-version
 *   4. package.json#packageManager   (pnpm/yarn versions imply a Node floor)
 *
 * Output clamps to the supported majors. Anything we can't make sense
 * of falls back to the configured default image so the build still has
 * a fighting chance.
 */
final class NodeVersionDetector
{
    /** Pre-built images shipped by Docker Hub. Update when a new LTS lands. */
    public const SUPPORTED_MAJORS = [18, 20, 22, 24];

    public const DEFAULT_MAJOR = 22;

    /**
     * Inspect a checkout directory and return a structured detection result.
     *
     * @return array{
     *   major: int,
     *   image: string,
     *   source: string,
     *   raw: string|null,
     *   detected: bool,
     * }
     */
    public function detect(string $checkoutDir): array
    {
        $checkoutDir = rtrim($checkoutDir, '/');

        $package = $this->readPackageJson($checkoutDir);

        // 1) package.json#engines.node
        $engines = $package['engines']['node'] ?? null;
        if (is_string($engines) && $engines !== '') {
            $major = $this->majorFromRange($engines);
            if ($major !== null) {
                return $this->result($major, 'package.json#engines.node', $engines);
            }
        }

        // 2) .nvmrc
        $nvmrc = $this->readSmall($checkoutDir.'/.nvmrc');
        if ($nvmrc !== null) {
            $major = $this->majorFromVersionString($nvmrc);
            if ($major !== null) {
                return $this->result($major, '.nvmrc', $nvmrc);
            }
        }

        // 3) .node-version
        $nodeVersion = $this->readSmall($checkoutDir.'/.node-version');
        if ($nodeVersion !== null) {
            $major = $this->majorFromVersionString($nodeVersion);
            if ($major !== null) {
                return $this->result($major, '.node-version', $nodeVersion);
            }
        }

        // 4) package.json#packageManager — infer Node floor from pnpm/yarn version
        $pm = $package['packageManager'] ?? null;
        if (is_string($pm) && $pm !== '') {
            $major = $this->majorFromPackageManager($pm);
            if ($major !== null) {
                return $this->result($major, 'package.json#packageManager', $pm);
            }
        }

        return [
            'major' => self::DEFAULT_MAJOR,
            'image' => $this->imageFor(self::DEFAULT_MAJOR),
            'source' => 'default',
            'raw' => null,
            'detected' => false,
        ];
    }

    public function imageFor(int $major): string
    {
        $supported = $this->clampToSupported($major);

        return 'node:'.$supported.'-bookworm';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPackageJson(string $dir): ?array
    {
        $path = $dir.'/package.json';
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function readSmall(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path, false, null, 0, 256);

        return $raw === false ? null : trim($raw);
    }

    /**
     * Pick a major from an engines.node range. Handles common shapes:
     *   "22", "22.x", "22.0.0", "^22", "~22.10", ">=20", ">=20 <23",
     *   "20 || 22", ">=18.0.0", "lts/*" (unsupported → null).
     *
     * Strategy: collect every integer-looking number; if a `>=` floor is
     * present, return the highest supported major >= that floor; otherwise
     * return the FIRST integer mentioned. Keeps the behaviour predictable
     * without dragging in a semver lib.
     */
    private function majorFromRange(string $range): ?int
    {
        $normalized = strtolower(trim($range));
        if ($normalized === '' || str_starts_with($normalized, 'lts/')) {
            return null;
        }

        preg_match_all('/\d+/', $normalized, $matches);
        $numbers = array_map('intval', $matches[0]);
        if ($numbers === []) {
            return null;
        }

        // Comparator floor: ">=18", ">18", "20 <= x".
        if (preg_match('/>=?\s*(\d+)/', $normalized, $m) === 1) {
            $floor = (int) $m[1];

            // Open-ended (no `<` cap): the floor is a minimum, not a pin —
            // starter templates ship ">=18" while their deps need newer Node
            // (sharp wants >=20.9). Never go below the default LTS.
            if (! str_contains($normalized, '<') && $floor <= self::DEFAULT_MAJOR) {
                return self::DEFAULT_MAJOR;
            }

            // Capped range ("<=" / "<") → LOWEST supported satisfying major.
            foreach (self::SUPPORTED_MAJORS as $candidate) {
                if ($candidate >= $floor) {
                    return $candidate;
                }
            }

            return self::DEFAULT_MAJOR;
        }

        // First number wins for exact / caret / tilde / pipe forms.
        return $numbers[0];
    }

    /**
     * Plain version strings: "20", "v22.13.0", "22.13", "lts/iron" (→ null).
     */
    private function majorFromVersionString(string $value): ?int
    {
        $trimmed = strtolower(trim($value));
        if ($trimmed === '' || str_starts_with($trimmed, 'lts/')) {
            return null;
        }

        if (preg_match('/v?(\d+)/', $trimmed, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Map a packageManager pin to its Node floor.
     *
     *   pnpm@11+ → Node 22
     *   pnpm@10  → Node 20
     *   pnpm@9   → Node 18
     *   yarn@4+  → Node 18
     *   npm@10+  → Node 18
     *
     * Returns null when we don't recognize the tool, so the caller falls
     * through to the configured default.
     */
    private function majorFromPackageManager(string $value): ?int
    {
        if (preg_match('/^([a-z]+)@(\d+)(?:\.\d+){0,2}/i', trim($value), $m) !== 1) {
            return null;
        }
        $tool = strtolower($m[1]);
        $major = (int) $m[2];

        return match ($tool) {
            'pnpm' => match (true) {
                $major >= 11 => 22,
                $major === 10 => 20,
                $major === 9 => 18,
                default => null,
            },
            'yarn' => $major >= 4 ? 18 : null,
            'npm' => $major >= 10 ? 18 : null,
            default => null,
        };
    }

    private function clampToSupported(int $major): int
    {
        if (in_array($major, self::SUPPORTED_MAJORS, true)) {
            return $major;
        }

        // Below the lowest supported → use lowest. Above the highest → use highest.
        $lowest = self::SUPPORTED_MAJORS[0];
        $highest = self::SUPPORTED_MAJORS[count(self::SUPPORTED_MAJORS) - 1];
        if ($major < $lowest) {
            return $lowest;
        }
        if ($major > $highest) {
            return $highest;
        }

        // Odd-numbered Node release (e.g. 19, 21, 23) → round UP to the next
        // even LTS so we never silently downgrade.
        foreach (self::SUPPORTED_MAJORS as $candidate) {
            if ($candidate >= $major) {
                return $candidate;
            }
        }

        return self::DEFAULT_MAJOR;
    }

    /**
     * @return array{major: int, image: string, source: string, raw: string, detected: true}
     */
    private function result(int $major, string $source, string $raw): array
    {
        $clamped = $this->clampToSupported($major);

        return [
            'major' => $clamped,
            'image' => $this->imageFor($clamped),
            'source' => $source,
            'raw' => $raw,
            'detected' => true,
        ];
    }
}
