<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\Site;
use App\Modules\Edge\Actions\HandleEdgeGitPush;
use App\Modules\SourceControl\Services\DplyGit;
use App\Support\ProductLine\ProductLineKillSwitches;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pull dply Git push events off the Artifacts events queue and deploy
 * (T-034). Scheduled every 5s. A message that can't be acted on — unknown
 * repo, other account, malformed — is acked and logged, never retried
 * forever; only a failure while handling a real push is retried.
 */
class PullDplyGitEventsCommand extends Command
{
    protected $signature = 'dply:edge:git-events';

    protected $description = 'Deploy dply Git pushes from the Artifacts events queue';

    public function handle(DplyGit $dplyGit): int
    {
        $queueId = (string) config('edge.git.queue_id');
        if ($queueId === '' || ProductLineKillSwitches::blocksEdgeDelivery()) {
            return self::SUCCESS;
        }

        $client = $dplyGit->client();
        $acks = $retries = [];
        foreach ($client->pullMessages($queueId) as $message) {
            try {
                $outcome = $this->handleEvent($message['body'], $client->accountId());
                $this->line(json_encode($outcome) ?: '');
                $acks[] = $message['lease_id'];
            } catch (\Throwable $e) {
                Log::warning('dply Git push event failed', ['error' => $e->getMessage(), 'attempts' => $message['attempts']]);
                // ponytail: 5 tries then drop; a dead-letter queue if pushes ever go missing.
                if ($message['attempts'] >= 5) {
                    $acks[] = $message['lease_id'];
                } else {
                    $retries[] = $message['lease_id'];
                }
            }
        }
        $client->ackMessages($queueId, $acks, $retries);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    public function handleEvent(mixed $event, string $accountId): array
    {
        if (! is_array($event) || ($event['type'] ?? null) !== 'cf.artifacts.repo.pushed') {
            return ['ok' => true, 'queued' => false, 'reason' => 'event_ignored'];
        }
        if (($event['metadata']['accountId'] ?? null) !== $accountId) {
            Log::warning('dply Git event from another account ignored');

            return ['ok' => false, 'reason' => 'wrong_account'];
        }

        $namespace = (string) ($event['source']['namespace'] ?? '');
        $repo = (string) ($event['source']['repoName'] ?? '');
        $site = $namespace === (string) config('edge.git.namespace', 'dply') && $repo !== '' ? Site::query()->find($repo) : null;
        // The repo name is the site id; the site must still point at that repo.
        if ($site === null || ! $site->usesEdgeRuntime() || $site->isEdgePreview()
            || (DplyGit::parse((string) ($site->edgeMeta()['source']['repo'] ?? '')) ?? []) !== ['namespace' => $namespace, 'repo' => $repo]) {
            return ['ok' => false, 'reason' => 'unknown_repo', 'repo' => $repo];
        }

        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        return (new HandleEdgeGitPush)->handle(
            $site,
            (string) ($payload['ref'] ?? ''),
            is_string($payload['after'] ?? null) ? $payload['after'] : null,
            branchPreviews: true,
        );
    }
}
