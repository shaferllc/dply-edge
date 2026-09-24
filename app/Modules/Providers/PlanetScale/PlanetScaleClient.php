<?php

declare(strict_types=1);

namespace App\Modules\Providers\PlanetScale;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Platform PlanetScale account. Creates one MySQL database per app. The
 * cluster stays on. The password is only in the create-password response.
 *
 * Called from EdgeAppDatabase and FinishEdgeMysqlDatabaseJob.
 * API: POST /organizations/{org}/databases, GET that database, POST
 * .../branches/{branch}/passwords, DELETE the database.
 */
final class PlanetScaleClient
{
    public function __construct(
        private readonly string $organization,
        private readonly string $tokenId,
        private readonly string $token,
        private readonly string $region,
        private readonly string $clusterSize,
    ) {}

    public static function configured(): bool
    {
        return self::tokenId() !== '' && self::token() !== '';
    }

    public static function fromConfig(): self
    {
        $tokenId = self::tokenId();
        $token = self::token();
        if ($tokenId === '' || $token === '') {
            throw new RuntimeException('MySQL cannot be started from here yet.');
        }

        return new self(
            (string) config('edge.planetscale.organization'),
            $tokenId,
            $token,
            (string) config('edge.planetscale.region', ''),
            self::normalizeClusterSize((string) config('edge.planetscale.cluster_size', 'PS_10')),
        );
    }

    private static function tokenId(): string
    {
        $tokenId = (string) config('edge.planetscale.token_id');

        return $tokenId !== '' ? $tokenId : (string) config('edge.planetscale.id');
    }

    private static function token(): string
    {
        $token = (string) config('edge.planetscale.token');

        return $token !== '' ? $token : (string) config('edge.planetscale.secret');
    }

    private static function normalizeClusterSize(string $size): string
    {
        if (str_starts_with($size, 'PS-')) {
            return 'PS_'.substr($size, 3);
        }

        return $size !== '' ? $size : 'PS_10';
    }

    /**
     * @return array{name: string, ready: bool, branch: string}
     */
    public function create(string $name, ?string $clusterSize = null): array
    {
        $size = $clusterSize !== null && $clusterSize !== ''
            ? self::normalizeClusterSize($clusterSize)
            : ($this->clusterSize !== '' ? $this->clusterSize : 'PS_10');
        $body = [
            'name' => $name,
            'kind' => 'mysql',
            'cluster_size' => $size,
            'foreign_keys_enabled' => true,
        ];
        if ($this->region !== '') {
            $body['region'] = $this->region;
        }
        $response = $this->http()->post('/organizations/'.$this->organizationName().'/databases', $body);
        if (! $response->successful()) {
            throw new RuntimeException($this->failure($response->json(), 'MySQL could not be started.'));
        }

        return $this->readyState($response->json(), $name);
    }

    /**
     * @return array{name: string, ready: bool, branch: string}
     */
    public function database(string $name): array
    {
        $response = $this->http()->get('/organizations/'.$this->organizationName().'/databases/'.$name);
        if (! $response->successful()) {
            throw new RuntimeException($this->failure($response->json(), 'MySQL did not answer.'));
        }

        return $this->readyState($response->json(), $name);
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    public function createPassword(string $name, string $branch): array
    {
        $branch = $branch !== '' ? $branch : 'main';
        $response = $this->http()->post(
            '/organizations/'.$this->organizationName().'/databases/'.$name.'/branches/'.$branch.'/passwords',
            ['name' => 'dply', 'role' => 'admin'],
        );
        if (! $response->successful()) {
            throw new RuntimeException($this->failure($response->json(), 'MySQL started, but the password did not come back.'));
        }

        $json = $response->json();
        $username = (string) data_get($json, 'username', '');
        $password = (string) data_get($json, 'plain_text', '');
        $host = (string) data_get($json, 'access_host_url', data_get($json, 'hostname', ''));
        if (str_contains($host, '://')) {
            $host = (string) (parse_url($host, PHP_URL_HOST) ?: '');
        }
        if ($username === '' || $password === '' || $host === '') {
            throw new RuntimeException('MySQL started, but the address did not come back.');
        }

        return [
            'host' => $host,
            'port' => '3306',
            'database' => $name,
            'username' => $username,
            'password' => $password,
        ];
    }

    public function resize(string $name, string $clusterSize): void
    {
        $remote = $this->database($name);
        $response = $this->http()->patch(
            '/organizations/'.$this->organizationName().'/databases/'.$name.'/branches/'.$remote['branch'],
            ['cluster_size' => self::normalizeClusterSize($clusterSize)],
        );
        if (! $response->successful()) {
            throw new RuntimeException($this->failure($response->json(), 'MySQL size could not be changed.'));
        }
    }

    public function delete(string $name): void
    {
        if ($name === '') {
            return;
        }
        $response = $this->http()->delete('/organizations/'.$this->organizationName().'/databases/'.$name);
        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException($this->failure($response->json(), 'MySQL could not be removed.'));
        }
    }

    /**
     * @return array{name: string, ready: bool, branch: string}
     */
    private function readyState(mixed $json, string $fallbackName): array
    {
        $name = (string) data_get($json, 'name', $fallbackName);
        $state = (string) data_get($json, 'state', '');

        return [
            'name' => $name !== '' ? $name : $fallbackName,
            'ready' => (bool) data_get($json, 'ready', false) || $state === 'ready',
            'branch' => (string) data_get($json, 'default_branch', 'main'),
        ];
    }

    private ?string $resolvedOrganization = null;

    private function organizationName(): string
    {
        if ($this->organization !== '') {
            return $this->organization;
        }
        if ($this->resolvedOrganization !== null) {
            return $this->resolvedOrganization;
        }

        $response = $this->http()->get('/organizations');
        if (! $response->successful()) {
            throw new RuntimeException('MySQL cannot be started from here yet.');
        }

        $body = $response->json();
        $rows = is_array($body) && array_is_list($body) ? $body : (is_array($body) ? ($body['data'] ?? []) : []);
        $names = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && (string) ($row['name'] ?? '') !== '') {
                $names[] = (string) $row['name'];
            }
        }
        if (count($names) !== 1) {
            throw new RuntimeException('MySQL cannot be started from here yet.');
        }

        return $this->resolvedOrganization = $names[0];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl('https://api.planetscale.com/v1')
            ->withHeaders([
                'Authorization' => $this->tokenId.':'.$this->token,
                'Accept' => 'application/json',
            ])
            ->asJson()
            ->timeout(30);
    }

    private function failure(mixed $json, string $fallback): string
    {
        $message = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';

        return $message !== '' ? $message : $fallback;
    }
}
