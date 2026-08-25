<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * Thin abstraction over `git clone` so tests can swap in a fake cloner.
 *
 * Production uses {@see ProcessGitCloner} which shells out to git; tests
 * substitute a fake that materializes files directly in the destination
 * directory. Keeps the rest of the runtime-detection layer free of
 * Symfony Process / network coupling.
 */
interface GitCloner
{
    /**
     * Shallow-clone $url at $branch into $destination (which must not yet
     * exist as a directory — git creates it).
     *
     * @throws GitCloneException on any failure (network, auth, missing branch,
     *                           invalid URL, etc.). Callers translate this into
     *                           UI-friendly messages.
     */
    public function shallowClone(string $url, string $branch, string $destination): void;
}
