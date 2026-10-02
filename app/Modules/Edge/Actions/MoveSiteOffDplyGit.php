<?php

declare(strict_types=1);

namespace App\Modules\Edge\Actions;

use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\EdgeGithubWebhookProvisioner;
use App\Modules\SourceControl\Services\DplyGit;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Undo a move to dply Git: point the app back at the repo it came from
 * (`edge.dply_git.moved_from`), stop dply Git push events and reconnect the
 * GitHub webhook when that repo is on GitHub.
 *
 * The dply Git repo is kept (repo name = site id, so moving again reuses it).
 * Nothing is copied back: commits pushed only to dply Git must be pushed to
 * the original repo first — {@see pushedSinceMove()} lets the UI warn.
 */
class MoveSiteOffDplyGit
{
    /**
     * @return array{repo: string, webhook: ?string} webhook = why GitHub didn't reconnect, null when fine or not GitHub
     */
    public function handle(Site $site, ?User $user): array
    {
        $dplyGit = is_array($site->edgeMeta()['dply_git'] ?? null) ? $site->edgeMeta()['dply_git'] : [];
        $movedFrom = trim((string) ($dplyGit['moved_from'] ?? ''));
        if (! DplyGit::siteUses($site) || $movedFrom === '') {
            throw new RuntimeException(__('This app wasn’t moved to dply Git from another repository.'));
        }

        if (is_string($subscriptionId = $dplyGit['subscription_id'] ?? null) && $subscriptionId !== '') {
            try {
                app(DplyGit::class)->client()->deleteSubscription($subscriptionId);
            } catch (\Throwable $e) {
                // An orphaned subscription is harmless: its events no longer match the site's repo.
                Log::warning('dply Git subscription delete failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            }
        }

        $source = is_array($site->edgeMeta()['source'] ?? null) ? $site->edgeMeta()['source'] : [];
        $deployOnPush = ($source['deploy_on_push'] ?? true) !== false;
        $site->mergeEdgeMeta([
            'source' => array_merge($source, ['repo' => $movedFrom]),
            'dply_git' => null,
            'dply_git_move' => null,
        ]);
        $site->save();

        // GitHub owner/name repos get their webhook back. enable() rewrites
        // deploy_on_push either way, so restore the user's setting after.
        $webhook = null;
        if ($deployOnPush && $user !== null) {
            $result = app(EdgeGithubWebhookProvisioner::class)->enableWithDefaultAccount($site, $user);
            $webhook = $result !== null && ! $result['ok'] ? $result['message'] : null;
            $site->refresh();
            $site->mergeEdgeMeta(['source' => array_merge($site->edgeMeta()['source'] ?? [], ['deploy_on_push' => $deployOnPush])]);
            $site->save();
        }

        return ['repo' => $movedFrom, 'webhook' => $webhook];
    }

    /** Whether anyone pushed to the dply Git repo after the move (null = couldn't tell). */
    public static function pushedSinceMove(Site $site): ?bool
    {
        $meta = $site->edgeMeta()['dply_git'] ?? [];
        $repo = DplyGit::parse((string) ($site->edgeMeta()['source']['repo'] ?? ''));
        if ($repo === null || ! is_string($meta['moved_at'] ?? null)) {
            return null;
        }

        try {
            $lastPush = app(DplyGit::class)->client()->getRepo($repo['namespace'], $repo['repo'])['last_push_at'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        // The move's own push lands a few seconds before moved_at is written.
        return is_string($lastPush) && strtotime($lastPush) > strtotime($meta['moved_at']) + 60;
    }
}
