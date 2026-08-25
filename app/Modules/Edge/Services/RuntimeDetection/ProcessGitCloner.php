<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Production {@see GitCloner} — shells out to `git` via Symfony Process.
 *
 * Uses `--depth=1 --single-branch` so we pull the minimum needed to
 * inspect repo signals at the tip of the requested branch. Full history
 * isn't useful for detection and adding it would multiply clone time on
 * large repos.
 */
final class ProcessGitCloner implements GitCloner
{
    /**
     * Default timeout for the clone process, in seconds. Overridable so
     * tests can drop it; the production value gives a reasonable budget
     * for a large monorepo on a slow connection.
     */
    public function __construct(
        private readonly int $timeoutSeconds = 120,
    ) {}

    public function shallowClone(string $url, string $branch, string $destination): void
    {
        if ($url === '') {
            throw new GitCloneException('Repository URL is required.');
        }
        if ($branch === '') {
            throw new GitCloneException('Branch is required.');
        }

        $process = $this->runClone([
            'git', 'clone', '--depth=1', '--single-branch',
            '--branch', $branch, $url, $destination,
        ], $url);

        if ($process->isSuccessful()) {
            return;
        }

        $stderr = $this->sanitize($process->getErrorOutput(), $url);

        // Stale/wrong branch hint (form pre-filled `main` for a repo whose
        // default is actually `master`/`12.x`/etc.) — re-try cloning the
        // remote HEAD so runtime detection still has something to work on.
        // Form correction is the resolver's job; here we just refuse to
        // hard-fail when a one-line fallback succeeds.
        if ($this->looksLikeMissingBranch($stderr)) {
            $fallback = $this->runClone([
                'git', 'clone', '--depth=1', $url, $destination,
            ], $url);
            if ($fallback->isSuccessful()) {
                return;
            }
            $stderr = $this->sanitize($fallback->getErrorOutput(), $url);
        }

        throw new GitCloneException(
            "Clone failed for {$this->displayUrl($url)} (branch {$branch}): {$stderr}",
        );
    }

    /**
     * @param  array<int, string> $command
     */
    private function runClone(array $command, string $url): Process
    {
        $process = new Process($command);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new GitCloneException(
                "Clone timed out after {$this->timeoutSeconds}s: {$this->displayUrl($url)}",
                previous: $e,
            );
        }

        return $process;
    }

    private function looksLikeMissingBranch(string $stderr): bool
    {
        // git's exact phrasing across versions:
        //   "fatal: Remote branch <name> not found in upstream origin"
        //   "fatal: Could not find remote branch <name>"
        return preg_match('/(Remote branch.*not found|Could not find remote branch)/i', $stderr) === 1;
    }

    /**
     * Strip credentials from a URL for safe display in error messages.
     * Handles git+https URLs of the form https://user:token@host/path.
     */
    private function displayUrl(string $url): string
    {
        return (string) preg_replace('#^(https?://)[^/@]*@#', '$1', $url);
    }

    /**
     * Mask the URL credentials in any captured stderr line. git tends to
     * echo the URL back in its error messages; we never want to surface
     * an embedded token to the UI or log.
     */
    private function sanitize(string $stderr, string $url): string
    {
        $sanitized = preg_replace('#(https?://)[^/@\s]*@#', '$1', $stderr);

        return trim((string) ($sanitized !== null ? $sanitized : $stderr));
    }
}
