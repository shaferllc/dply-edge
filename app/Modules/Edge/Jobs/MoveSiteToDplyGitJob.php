<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeGithubWebhookProvisioner;
use App\Modules\SourceControl\Services\DplyGit;
use App\Modules\SourceControl\Services\GitCloneAuth;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Move an app's code onto dply Git (T-034): create its Artifacts repo, copy
 * every branch and tag from the current repo (cloned with the linked
 * account, so private repos work), subscribe the repo's pushes, then point
 * the app at it and disconnect GitHub.
 *
 * Progress lives in `edge.dply_git_move` = {status: moving|failed, error?, at}.
 * The app only switches remotes once everything else succeeded, so a failure
 * leaves it deploying from where it was.
 */
class MoveSiteToDplyGitJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /** Artifacts' per-file limit (developers.cloudflare.com/artifacts/platform/limits). */
    private const MAX_FILE_BYTES = 32 * 1024 * 1024;

    public function __construct(public string $siteId) {}

    public function handle(DplyGit $dplyGit, GitCloneAuth $cloneAuth): void
    {
        $site = Site::find($this->siteId);
        if ($site === null || $site->isEdgePreview() || DplyGit::siteUses($site)) {
            return;
        }

        $workDir = storage_path('app/dply-git-move/'.$site->id);

        try {
            $this->move($site, $dplyGit, $cloneAuth, $workDir);
        } catch (\Throwable $e) {
            Log::warning('dply Git move failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            $site->refresh();
            $site->mergeEdgeMeta(['dply_git_move' => ['status' => 'failed', 'error' => $e->getMessage(), 'at' => now()->toIso8601String()]]);
            $site->save();
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function move(Site $site, DplyGit $dplyGit, GitCloneAuth $cloneAuth, string $workDir): void
    {
        $queueId = (string) config('edge.git.queue_id');
        if ($queueId === '') {
            throw new RuntimeException('dply Git is not set up: DPLY_GIT_QUEUE_ID is empty (run php artisan dply:edge:git-setup).');
        }

        $source = is_array($site->edgeMeta()['source'] ?? null) ? $site->edgeMeta()['source'] : [];
        $oldRepo = trim((string) ($source['repo'] ?? ''));
        if ($oldRepo === '') {
            throw new RuntimeException('This app has no repository to move.');
        }
        $oldUrl = str_contains($oldRepo, '://') ? $oldRepo : 'https://github.com/'.$oldRepo.'.git';
        $branch = (string) ($source['branch'] ?? 'main') ?: 'main';

        // Bare clone carries refs/heads + refs/tags only — GitHub's refs/pull/* stay behind.
        File::deleteDirectory($workDir);
        File::ensureDirectoryExists(dirname($workDir));
        $readEnv = $cloneAuth->envForSite($site, $oldUrl);
        $this->git(['git', 'clone', '--bare', $oldUrl, $workDir], $readEnv);
        $refspecs = $this->refspecs($workDir, $oldRepo);

        $client = $dplyGit->client();
        $namespace = (string) config('edge.git.namespace', 'dply');
        $name = strtolower((string) $site->id);
        $remote = (string) (($client->getRepo($namespace, $name) ?? $client->createRepo($namespace, $name, $branch, (string) $site->name))['remote']);

        // --force: nothing points at this repo until the switch below, so a
        // half-finished earlier attempt is ours to overwrite.
        $token = $client->createToken($namespace, $name, 'write', 3600);
        $this->git(['git', '-C', $workDir, 'push', '--force', $remote, ...$refspecs['refspecs']], DplyGit::envForToken($remote, $token['plaintext']));
        $client->revokeToken($namespace, $token['id']);

        $subscriptionId = $client->subscribeToPushes($namespace, $name, $queueId);

        // Switch over. disable() turns deploy_on_push off; put the user's setting back.
        $deployOnPush = ($source['deploy_on_push'] ?? true) !== false;
        $site->refresh();
        app(EdgeGithubWebhookProvisioner::class)->disable($site);
        $site->refresh();

        $source = is_array($site->edgeMeta()['source'] ?? null) ? $site->edgeMeta()['source'] : [];
        $site->mergeEdgeMeta([
            'source' => array_merge($source, ['repo' => $remote, 'deploy_on_push' => $deployOnPush]),
            'dply_git' => [
                'namespace' => $namespace,
                'repo' => $name,
                'subscription_id' => $subscriptionId,
                'moved_from' => $oldRepo,
                'moved_at' => now()->toIso8601String(),
                'history' => $refspecs['history'],
                'history_note' => $refspecs['note'],
            ],
            'dply_git_move' => null,
        ]);
        $meta = $site->meta;
        unset($meta['repository']['git_source_control_account_id']);
        $site->meta = $meta;
        $site->save();
    }

    /**
     * What to push. Artifacts refuses any file over 32 MB, anywhere in history
     * (artifacts_git_receive_pack_object_too_large). When the big files are
     * only in old commits, push each branch's current code as one fresh
     * commit instead — a deploy needs the code, not the history — and skip
     * tags, which point into that history. A big file in a branch's current
     * code can't be helped: fail before creating anything, naming it.
     *
     * @return array{refspecs: list<string>, history: 'full'|'snapshot', note: ?string}
     */
    private function refspecs(string $workDir, string $oldRepo): array
    {
        $full = ['refspecs' => ['refs/heads/*:refs/heads/*', 'refs/tags/*:refs/tags/*'], 'history' => 'full', 'note' => null];
        $tooBig = $this->blobsOver($workDir, self::MAX_FILE_BYTES);
        if ($tooBig === []) {
            return $full;
        }

        $refspecs = [];
        $branches = array_filter(explode("\n", $this->git(['git', '-C', $workDir, 'for-each-ref', '--format=%(refname:short)', 'refs/heads'], [])));
        foreach ($branches as $branch) {
            foreach (explode("\n", $this->git(['git', '-C', $workDir, 'ls-tree', '-r', '-l', $branch], [])) as $line) {
                // "<mode> blob <sha> <size>\t<path>"
                if (preg_match('/^\d+ blob \S+\s+(\d+)\t(.+)$/', $line, $m) === 1 && (int) $m[1] > self::MAX_FILE_BYTES) {
                    throw new RuntimeException(sprintf(
                        '%s on branch %s is %s MB; dply Git takes files up to 32 MB. Remove it from the repository (or add it to .gitignore), push to %s, then move again.',
                        $m[2], $branch, number_format((int) $m[1] / 1048576, 1), $oldRepo,
                    ));
                }
            }
            $sha = trim($this->git(['git', '-C', $workDir, 'rev-parse', $branch], []));
            $snapshot = trim($this->git(
                ['git', '-C', $workDir, 'commit-tree', $branch.'^{tree}', '-m', "Imported from {$oldRepo} at {$sha}"],
                ['GIT_AUTHOR_NAME' => 'dply', 'GIT_AUTHOR_EMAIL' => 'git@dply.io', 'GIT_COMMITTER_NAME' => 'dply', 'GIT_COMMITTER_EMAIL' => 'git@dply.io'],
            ));
            $refspecs[] = $snapshot.':refs/heads/'.$branch;
        }

        return [
            'refspecs' => $refspecs,
            'history' => 'snapshot',
            'note' => sprintf('Old commits hold files over 32 MB (%s), so each branch was copied as one commit without its history. The full history is still in %s.', implode(', ', array_slice(array_unique($tooBig), 0, 3)), $oldRepo),
        ];
    }

    /**
     * Paths of every blob in the repo's history larger than $bytes.
     *
     * @return list<string>
     */
    private function blobsOver(string $workDir, int $bytes): array
    {
        $objects = $this->git(['git', '-C', $workDir, 'rev-list', '--objects', '--all'], []);
        $result = Process::timeout(900)->input($objects)
            ->run(['git', '-C', $workDir, 'cat-file', '--batch-check=%(objecttype) %(objectsize) %(rest)']);

        $paths = [];
        foreach (explode("\n", $result->output()) as $line) {
            $parts = explode(' ', $line, 3);
            if (($parts[0] ?? '') === 'blob' && (int) ($parts[1] ?? 0) > $bytes) {
                $paths[] = $parts[2] ?? '';
            }
        }

        return $paths;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function git(array $command, array $env): string
    {
        $result = Process::timeout(900)->env($env + ['GIT_TERMINAL_PROMPT' => '0'])->run($command);
        if (! $result->successful()) {
            $verb = $command[1] === '-C' ? $command[3] : $command[1];

            throw new RuntimeException(trim(GitCloneAuth::redact("git {$verb} failed: ".$result->errorOutput(), $env)));
        }

        return $result->output();
    }
}
