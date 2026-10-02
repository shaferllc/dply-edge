<?php

declare(strict_types=1);

namespace App\Modules\Providers\Cloudflare;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cloudflare Artifacts (Git repos hosted on Cloudflare) plus the Queues pull
 * API its push events arrive on — the platform side of dply Git (T-034).
 *
 * Docs: developers.cloudflare.com/artifacts/api/rest-api/ and
 * developers.cloudflare.com/queues/configuration/pull-consumers/.
 */
class CloudflareArtifactsClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly string $accountId,
        private readonly string $apiToken,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('edge.cloudflare.account_id'),
            (string) config('edge.cloudflare.api_token'),
        );
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    /** Idempotent: an existing namespace is fine. */
    public function ensureNamespace(string $namespace): void
    {
        $response = $this->http()->post($this->url('/artifacts/namespaces'), ['namespace' => $namespace]);
        if ($response->status() === 409) {
            return;
        }
        $this->decode($response);
    }

    /**
     * @return array{id?: string, name?: string, remote: string, default_branch?: string}
     */
    public function createRepo(string $namespace, string $name, string $defaultBranch, string $description = ''): array
    {
        $result = $this->decode($this->http()->post($this->repoUrl($namespace), array_filter([
            'name' => $name,
            'description' => $description,
            'default_branch' => $defaultBranch,
        ], static fn ($v) => $v !== '')));
        unset($result['token']); // never keep the create-time write token around

        return $this->withRemote($result);
    }

    /**
     * @return array<string, mixed>|null null when it does not exist
     */
    public function getRepo(string $namespace, string $name): ?array
    {
        $response = $this->http()->get($this->repoUrl($namespace, $name));
        if ($response->status() === 404) {
            return null;
        }

        return $this->decode($response);
    }

    public function deleteRepo(string $namespace, string $name): void
    {
        $response = $this->http()->delete($this->repoUrl($namespace, $name));
        if ($response->status() !== 404) {
            $this->decode($response);
        }
    }

    /**
     * @param  'read'|'write'  $scope
     * @return array{id: string, plaintext: string, scope: string, expires_at: ?string}
     */
    public function createToken(string $namespace, string $repo, string $scope, int $ttlSeconds): array
    {
        $result = $this->decode($this->http()->post($this->url('/artifacts/namespaces/'.rawurlencode($namespace).'/tokens'), [
            'repo' => $repo,
            'scope' => $scope,
            'ttl' => max(60, min($ttlSeconds, 31_536_000)),
        ]));

        $plaintext = (string) ($result['plaintext'] ?? '');
        if ($plaintext === '') {
            throw new RuntimeException('Cloudflare Artifacts returned no token.');
        }

        return [
            'id' => (string) ($result['id'] ?? ''),
            'plaintext' => $plaintext,
            'scope' => (string) ($result['scope'] ?? $scope),
            'expires_at' => is_string($result['expires_at'] ?? null) ? $result['expires_at'] : null,
        ];
    }

    /**
     * Ids of a repo's active tokens.
     *
     * @return list<string>
     */
    public function activeTokenIds(string $namespace, string $repo): array
    {
        $ids = [];
        for ($page = 1; $page <= 50; $page++) {
            $rows = $this->decodeList($this->http()->get($this->repoUrl($namespace, $repo).'/tokens', ['state' => 'active', 'per_page' => 100, 'page' => $page]));
            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['id'] ?? null)) {
                    $ids[] = $row['id'];
                }
            }
            if (count($rows) < 100) {
                break;
            }
        }

        return $ids;
    }

    public function revokeToken(string $namespace, string $tokenId): void
    {
        $response = $this->http()->delete($this->url('/artifacts/namespaces/'.rawurlencode($namespace).'/tokens/'.rawurlencode($tokenId)));
        if ($response->status() !== 404) {
            $this->decode($response);
        }
    }

    /**
     * Send one repo's push events to a queue. Returns the subscription id.
     *
     * ponytail: the Artifacts source body is not in the API reference yet —
     * this follows the docs' repo-level source (`artifacts.repo` + namespace
     * + repo name) in the snake_case the other sources use. Verify on beta
     * access; an account-level source would replace one-per-repo.
     */
    public function subscribeToPushes(string $namespace, string $repo, string $queueId): string
    {
        $result = $this->decode($this->http()->post($this->url('/event_subscriptions/subscriptions'), [
            'name' => 'dply-git-'.$repo,
            'enabled' => true,
            'source' => ['type' => 'artifacts.repo', 'namespace' => $namespace, 'repo_name' => $repo],
            'destination' => ['type' => 'queues.queue', 'queue_id' => $queueId],
            'events' => ['pushed'],
        ]));

        return (string) ($result['id'] ?? '');
    }

    public function deleteSubscription(string $subscriptionId): void
    {
        $response = $this->http()->delete($this->url('/event_subscriptions/subscriptions/'.rawurlencode($subscriptionId)));
        if ($response->status() !== 404) {
            $this->decode($response);
        }
    }

    /** Create the events queue (or find it by name). Returns its id. */
    public function ensureQueue(string $name): string
    {
        foreach ((array) ($this->decodeList($this->http()->get($this->url('/queues'), ['per_page' => 100]))) as $queue) {
            if (is_array($queue) && ($queue['queue_name'] ?? null) === $name) {
                return (string) $queue['queue_id'];
            }
        }

        $result = $this->decode($this->http()->post($this->url('/queues'), ['queue_name' => $name]));

        return (string) ($result['queue_id'] ?? '');
    }

    /** Switch the queue to an HTTP pull consumer. Idempotent. */
    public function enableHttpPull(string $queueId): void
    {
        $response = $this->http()->post($this->url('/queues/'.rawurlencode($queueId).'/consumers'), ['type' => 'http_pull']);
        if ($response->status() === 409 || str_contains((string) $response->body(), 'already')) {
            return;
        }
        $this->decode($response);
    }

    /**
     * Lease up to $batch messages. Bodies are decoded to arrays where they
     * are JSON (Queues may hand JSON back base64-encoded); anything else is
     * left as the raw string.
     *
     * @return list<array{lease_id: string, attempts: int, body: mixed}>
     */
    public function pullMessages(string $queueId, int $batch = 50, int $visibilityMs = 60_000): array
    {
        $result = $this->decode($this->http()->post($this->url('/queues/'.rawurlencode($queueId).'/messages/pull'), [
            'batch_size' => max(1, min($batch, 100)),
            'visibility_timeout_ms' => $visibilityMs,
        ]));

        $out = [];
        foreach ((array) ($result['messages'] ?? []) as $message) {
            if (! is_array($message) || ! is_string($message['lease_id'] ?? null)) {
                continue;
            }
            $out[] = [
                'lease_id' => $message['lease_id'],
                'attempts' => (int) ($message['attempts'] ?? 1),
                'body' => self::decodeBody($message['body'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $ackLeaseIds
     * @param  list<string>  $retryLeaseIds
     */
    public function ackMessages(string $queueId, array $ackLeaseIds, array $retryLeaseIds = []): void
    {
        if ($ackLeaseIds === [] && $retryLeaseIds === []) {
            return;
        }

        $this->decode($this->http()->post($this->url('/queues/'.rawurlencode($queueId).'/messages/ack'), [
            'acks' => array_map(static fn (string $id): array => ['lease_id' => $id], $ackLeaseIds),
            'retries' => array_map(static fn (string $id): array => ['lease_id' => $id, 'delay_seconds' => 30], $retryLeaseIds),
        ]));
    }

    public static function decodeBody(mixed $body): mixed
    {
        if (! is_string($body)) {
            return $body;
        }
        $json = json_decode($body, true);
        if (is_array($json)) {
            return $json;
        }
        $raw = base64_decode($body, true);
        $json = $raw !== false ? json_decode($raw, true) : null;

        return is_array($json) ? $json : $body;
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->apiToken)->acceptJson()->timeout(30);
    }

    private function url(string $path): string
    {
        return self::BASE.'/accounts/'.$this->accountId.$path;
    }

    private function repoUrl(string $namespace, ?string $name = null): string
    {
        return $this->url('/artifacts/namespaces/'.rawurlencode($namespace).'/repos'.($name !== null ? '/'.rawurlencode($name) : ''));
    }

    /**
     * @param  array<string, mixed>  $repo
     * @return array<string, mixed>
     */
    private function withRemote(array $repo): array
    {
        if (! is_string($repo['remote'] ?? null) || $repo['remote'] === '') {
            throw new RuntimeException('Cloudflare Artifacts returned no remote URL.');
        }

        return $repo;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Cloudflare API returned a non-JSON response (HTTP '.$response->status().').');
        }

        if (($json['success'] ?? false) !== true) {
            $message = (string) ($json['errors'][0]['message'] ?? 'Cloudflare API request failed.');

            throw new RuntimeException($message.' (HTTP '.$response->status().')');
        }

        $result = $json['result'] ?? [];

        return is_array($result) ? $result : ['value' => $result];
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeList(Response $response): array
    {
        return array_values($this->decode($response));
    }
}
