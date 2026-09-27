<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\SelfHosting;

use App\Models\EdgeDeployment;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use RuntimeException;
use Throwable;

/**
 * dply's own deploy state, kept outside dply's database so a rollback works
 * while that database is down: one JSON object in the edge R2 bucket
 * (edge.self.state_key) holding the self site id, the deploy history and the
 * last Worker project (non-secret: wrangler.jsonc, src/index.js,
 * package.json). Secrets are never written here.
 *
 * @phpstan-type Deploy array{sha: string, ref: string, status: string, at: string, via?: string}
 */
class SelfDeployState
{
    public const KEEP = 30;

    /** @return array{site_id: string, deploys: list<array<string, string>>, worker: array<string, mixed>, bootstrap: array<string, mixed>} */
    public function load(): array
    {
        $raw = $this->client()->getR2Object($this->bucket(), $this->key());
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $data = is_array($data) ? $data : [];

        return [
            'site_id' => (string) ($data['site_id'] ?? ''),
            'deploys' => array_values(array_filter((array) ($data['deploys'] ?? []), 'is_array')),
            'worker' => is_array($data['worker'] ?? null) ? $data['worker'] : [],
            // dply:self:bootstrap: the pre-created database and Valkey (ids, hosts; no secrets).
            'bootstrap' => is_array($data['bootstrap'] ?? null) ? $data['bootstrap'] : [],
        ];
    }

    /** @param array<string, mixed> $state */
    public function save(array $state): void
    {
        $state['deploys'] = array_slice(array_values($state['deploys'] ?? []), 0, self::KEEP);
        $state['updated_at'] = now()->toIso8601String();
        $this->client()->putR2Object($this->bucket(), $this->key(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, string>  $deploy
     * @return array<string, mixed>
     */
    public static function withDeploy(array $state, array $deploy): array
    {
        $state['deploys'] = array_merge([$deploy + ['at' => now()->toIso8601String()]], array_values($state['deploys'] ?? []));

        return $state;
    }

    /**
     * Deploy history, newest first: the self site's rows in dply's database
     * when it is reachable, plus the R2 history (break-glass deploys and
     * everything when the database is down).
     *
     * @param  list<array<string, string>>  $r2
     * @return list<array<string, string>>
     */
    public static function history(array $r2, ?string $siteId, bool $dbReachable): array
    {
        $rows = $r2;
        if ($dbReachable && $siteId !== null && $siteId !== '') {
            try {
                foreach (EdgeDeployment::query()->where('site_id', $siteId)->whereNotNull('git_commit')->latest('created_at')->limit(self::KEEP)->get() as $deployment) {
                    $rows[] = [
                        'sha' => strtolower((string) $deployment->git_commit),
                        'ref' => (string) $deployment->git_branch,
                        // superseded = was live, and is a fine rollback target
                        'status' => in_array($deployment->status, [EdgeDeployment::STATUS_LIVE, EdgeDeployment::STATUS_SUPERSEDED], true) ? 'live' : (string) $deployment->status,
                        'at' => (string) $deployment->created_at?->toIso8601String(),
                        'via' => 'dashboard',
                    ];
                }
            } catch (Throwable) {
                // A database that answered the probe and then went away: R2 alone.
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));

        return $rows;
    }

    /**
     * What --rollback (and the automatic rollback) redeploys: the newest
     * successful deploy whose commit differs from $current. $current
     * defaults to the newest successful deploy — the one running now.
     *
     * @param  list<array<string, string>>  $history  newest first
     * @return array<string, string>|null
     */
    public static function rollbackTarget(array $history, ?string $current = null): ?array
    {
        $live = array_values(array_filter($history, static fn (array $d): bool => ($d['status'] ?? '') === 'live' && preg_match('/^[0-9a-f]{40}$/', (string) ($d['sha'] ?? '')) === 1));
        $current ??= $live[0]['sha'] ?? null;
        foreach ($live as $deploy) {
            if ($deploy['sha'] !== $current) {
                return $deploy;
            }
        }

        return null;
    }

    private function client(): EdgeCloudflareClient
    {
        return EdgeCloudflareClient::fromConfig();
    }

    private function bucket(): string
    {
        $bucket = trim((string) config('edge.r2.bucket'));
        if ($bucket === '') {
            throw new RuntimeException('DPLY_EDGE_R2_BUCKET is not set: the self-deploy state lives in that bucket.');
        }

        return $bucket;
    }

    private function key(): string
    {
        return (string) config('edge.self.state_key', '_dply-self/state.json');
    }
}
