<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

use Throwable;

/**
 * High-level entry point for "given this repo, what's the plan?".
 *
 * Two flavors:
 *
 *   - {@see fromPath} — the repo is already on disk (CLI dogfood, deploy
 *     time when the build server already has the checkout).
 *
 *   - {@see fromUrl}  — clone the repo into an ephemeral temp directory,
 *     run detection, and clean up. This is what the URL-first site-create
 *     form will call as the user types a repo URL.
 *
 * The preview returns the same {@see RepositoryRuntimePlan} shape in both
 * cases, plus the resolved working directory (for the path flavor) so
 * callers don't need to track it themselves.
 */
final class RepositoryRuntimePreview
{
    public function __construct(
        private RepositoryRuntimePlanComposer $composer,
        private GitCloner $cloner,
    ) {}

    public function fromPath(string $workingDirectory): ?RepositoryRuntimePlan
    {
        return $this->composer->compose($workingDirectory);
    }

    /**
     * Clone $url@$branch into a temp directory, compose the plan, then
     * delete the temp directory. The plan is returned even when no
     * runtime is detected (caller distinguishes via the nullable return).
     *
     * @throws GitCloneException when the clone fails
     */
    public function fromUrl(string $url, string $branch = 'main'): ?RepositoryRuntimePlan
    {
        $url = trim($url);
        $branch = trim($branch);
        if ($branch === '') {
            $branch = 'main';
        }

        $tmpRoot = rtrim(sys_get_temp_dir(), '/').'/dply-detect-'.bin2hex(random_bytes(6));
        // Create the parent only — git clone insists on its destination not
        // existing yet, so leave the `repo/` subpath uncreated.
        if (! @mkdir($tmpRoot, 0o700, true) && ! is_dir($tmpRoot)) {
            throw new GitCloneException("Could not create temp directory: {$tmpRoot}");
        }

        $checkoutPath = $tmpRoot.'/repo';

        try {
            $this->cloner->shallowClone($url, $branch, $checkoutPath);

            return $this->composer->compose($checkoutPath);
        } finally {
            try {
                $this->deleteRecursive($tmpRoot);
            } catch (Throwable) {
                // Don't let cleanup failures shadow a real result or clone
                // error; the OS will reap /tmp eventually.
            }
        }
    }

    private function deleteRecursive(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (is_link($path) || ! is_dir($path)) {
            @unlink($path);

            return;
        }
        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteRecursive($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
