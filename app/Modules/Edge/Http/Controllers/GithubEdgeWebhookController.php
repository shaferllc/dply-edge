<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Modules\Edge\Actions\CreateEdgePreviewSite;
use App\Modules\Edge\Actions\HandleEdgeGitPush;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use App\Modules\Edge\Support\EdgeDeployProgress;
use App\Modules\Edge\Support\EdgePreviewPolicy;
use App\Modules\Edge\Support\EdgeRepoRoot;
use App\Support\ProductLine\ProductLineKillSwitches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound GitHub webhook for git-connected Edge sites.
 *
 *   pull_request opened|reopened|synchronize  → CreateEdgePreviewSite
 *   pull_request closed                       → TeardownEdgeSiteJob (preview)
 *   push (to source branch)                   → RedeployEdgeSite (parent)
 */
class GithubEdgeWebhookController extends Controller
{
    public function __invoke(Request $request, Site $site): JsonResponse
    {
        $signatureHeader = (string) $request->header('X-Hub-Signature-256', '');
        if (! $this->verifySignature($request, $site, $signatureHeader)) {
            return response()->json(['ok' => false, 'reason' => 'invalid_signature'], 403);
        }

        if (! $site->usesEdgeRuntime()) {
            return response()->json(['ok' => false, 'reason' => 'not_an_edge_site'], 422);
        }

        if (ProductLineKillSwitches::blocksEdgeDelivery()) {
            return response()->json(['ok' => false, 'reason' => 'edge_delivery_paused'], 503);
        }

        if (! is_array($site->edgeMeta()['source'] ?? null)) {
            return response()->json(['ok' => false, 'reason' => 'site_is_not_source_mode'], 422);
        }

        $event = (string) $request->header('X-GitHub-Event', '');
        $payload = $request->json()->all();

        return match ($event) {
            'pull_request' => $this->handlePullRequest($site, $payload),
            'push' => $this->handlePush($site, $payload),
            'ping' => tap(response()->json(['ok' => true, 'event' => 'ping']), fn () => $this->touchWebhookLastEvent($site)),
            default => response()->json(['ok' => true, 'queued' => false, 'reason' => 'event_ignored', 'event' => $event]),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handlePullRequest(Site $site, array $payload): JsonResponse
    {
        $action = is_string($payload['action'] ?? null) ? (string) $payload['action'] : '';
        $pr = is_array($payload['pull_request'] ?? null) ? $payload['pull_request'] : [];
        $branch = is_string($pr['head']['ref'] ?? null) ? (string) $pr['head']['ref'] : '';
        $prNumber = is_int($pr['number'] ?? null) ? (int) $pr['number'] : null;
        $headSha = is_string($pr['head']['sha'] ?? null) ? (string) $pr['head']['sha'] : null;

        if ($branch === '') {
            return response()->json(['ok' => false, 'reason' => 'no_branch'], 200);
        }

        if (in_array($action, ['opened', 'reopened', 'synchronize'], true)) {
            // Previews build with the parent's env and secrets — never for
            // code from a fork.
            $headRepo = $pr['head']['repo']['full_name'] ?? null;
            $baseRepo = $pr['base']['repo']['full_name'] ?? null;
            if (is_string($headRepo) && is_string($baseRepo) && strcasecmp($headRepo, $baseRepo) !== 0) {
                return response()->json(['ok' => true, 'queued' => false, 'reason' => 'fork_pull_request', 'branch' => $branch]);
            }

            // Gate against the repo's `previews:` policy in dply.yaml.
            // Disabled globally or branch on the exclude list → skip.
            if (! EdgePreviewPolicy::shouldCreatePreview($site, EdgePreviewPolicy::EVENT_PULL_REQUEST, $branch)) {
                $this->touchWebhookLastEvent($site);

                return response()->json([
                    'ok' => true,
                    'queued' => false,
                    'reason' => 'preview_disabled_by_dply_yaml',
                    'branch' => $branch,
                ]);
            }

            $preview = (new CreateEdgePreviewSite)->handle($site, $branch, $prNumber, $headSha);
            $this->touchWebhookLastEvent($site);

            return response()->json([
                'ok' => true,
                'queued' => 'preview',
                'preview_id' => $preview->id,
                'branch' => $branch,
            ]);
        }

        if ($action === 'closed') {
            $preview = CreateEdgePreviewSite::findExisting($site, $branch);
            if ($preview === null) {
                return response()->json(['ok' => true, 'queued' => false, 'reason' => 'no_preview']);
            }
            TeardownEdgeSiteJob::dispatch($preview->id);
            $this->touchWebhookLastEvent($site);

            return response()->json([
                'ok' => true,
                'queued' => 'teardown',
                'preview_id' => $preview->id,
                'branch' => $branch,
            ]);
        }

        return response()->json(['ok' => true, 'queued' => false, 'reason' => 'pr_action_ignored', 'action' => $action]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handlePush(Site $site, array $payload): JsonResponse
    {
        $result = (new HandleEdgeGitPush)->handle(
            $site,
            is_string($payload['ref'] ?? null) ? (string) $payload['ref'] : '',
            is_string($payload['after'] ?? null) ? (string) $payload['after'] : null,
            EdgeRepoRoot::changedFilesFromPushPayload($payload),
            onDeploy: function ($deployment) use ($payload): void {
                // Whoever pushed gets the "is live" toast, when their GitHub is linked.
                if (($pusher = EdgeDeployProgress::githubPusher($payload)) !== null) {
                    EdgeDeployProgress::setMeta($deployment->id, 'triggered_by', $pusher);
                }
            },
        );
        if (in_array($result['reason'] ?? 'redeploy', ['redeploy', 'deploy_on_push_disabled'], true)) {
            $this->touchWebhookLastEvent($site);
        }

        return response()->json($result);
    }

    private function verifySignature(Request $request, Site $site, string $signatureHeader): bool
    {
        if ($signatureHeader === '' || blank($site->webhook_secret)) {
            return false;
        }
        $expectedPrefix = 'sha256=';
        if (! str_starts_with($signatureHeader, $expectedPrefix)) {
            return false;
        }
        $provided = substr($signatureHeader, strlen($expectedPrefix));
        $expected = hash_hmac('sha256', $request->getContent(), $site->webhook_secret);

        return hash_equals($expected, $provided);
    }

    private function touchWebhookLastEvent(Site $site): void
    {
        $webhook = is_array($site->edgeMeta()['webhook'] ?? null) ? $site->edgeMeta()['webhook'] : null;
        if (! is_array($webhook)) {
            return;
        }

        $site->mergeEdgeMeta([
            'webhook' => array_merge($webhook, [
                'last_event_at' => now()->toIso8601String(),
            ]),
        ]);
        $site->save();
    }
}
