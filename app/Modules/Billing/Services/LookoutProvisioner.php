<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to a Lookout (uselookout.app) instance to create an error-tracking
 * project for a dply site and hand back the ingest DSN the app reports with.
 *
 * This is the single chokepoint for the Lookout one-click resource: the
 * SiteBinding layer calls {@see provision()} and only ever sees an api_key +
 * DSN, so the *account model* (how dply authenticates to Lookout) is isolated
 * here. Today we ship "Model A": the customer's own Lookout API token (Sanctum
 * bearer) hits the existing `POST /api/v1/projects` and the project lands under
 * their account. A future "Model B" (a dply-held service token against a
 * `POST /api/provision` endpoint) swaps the request below without touching any
 * caller — see docs/LOOKOUT_RESOURCE.md.
 */
class LookoutProvisioner
{
    /**
     * Create a Lookout project under the customer's organization and return the
     * ingest credentials. The DSN is built here (api_key + instance host) rather
     * than trusted from the response, so it works against any Lookout version
     * whether or not the projects endpoint echoes a computed `ingest_dsn`.
     *
     * @return array{api_key: string, dsn: string, project_id: ?string, project_name: string}
     *
     * @throws RuntimeException when the Lookout API rejects the request
     */
    public function provision(string $apiToken, string $organizationId, string $projectName): array
    {
        $base = $this->baseUrl();

        $response = Http::asJson()
            ->acceptJson()
            ->withToken($apiToken)
            ->timeout(20)
            ->post($base.'/api/v1/projects', [
                'name' => $projectName,
                'organization_id' => $organizationId,
            ]);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Lookout rejected the API token — check it has access to that organization.');
        }
        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        $data = (array) $response->json();
        // The store endpoint wraps the model in a resource; tolerate both a bare
        // body and a {data: {...}} envelope.
        $project = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

        $apiKey = trim((string) ($project['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('Lookout created the project but returned no ingest key.');
        }

        return [
            'api_key' => $apiKey,
            // Prefer a server-computed DSN if present, else build the canonical
            // `https://<api_key>@<host>` shape the lookout/tracing SDK parses.
            'dsn' => trim((string) ($project['ingest_dsn'] ?? '')) ?: $this->buildDsn($apiKey, $base),
            'project_id' => isset($project['id']) ? (string) $project['id'] : null,
            'project_name' => trim((string) ($project['name'] ?? $projectName)) ?: $projectName,
        ];
    }

    /**
     * Create a project under a dply-managed organization using the service token
     * (the "managed" account model) against POST /api/provision. The customer
     * never needs a Lookout login. Returns the same shape as {@see provision()}.
     *
     * @return array{api_key: string, dsn: string, project_id: ?string, project_name: string}
     *
     * @throws RuntimeException when the provisioning endpoint is unconfigured or rejects the request
     */
    public function provisionManaged(
        string $projectName,
        ?int $retentionDays = null,
        ?string $externalOrgId = null,
        ?string $externalOrgName = null,
    ): array {
        $token = trim((string) config('services.lookout.provision_token', ''));
        if ($token === '') {
            throw new RuntimeException('Lookout managed provisioning is not configured (set LOOKOUT_PROVISION_TOKEN).');
        }

        $base = $this->baseUrl();
        $org = trim((string) config('services.lookout.managed_organization_id', ''));

        // When a dply org identity is given, Lookout provisions the project under
        // a DEDICATED Lookout org keyed to that external id (data isolation for
        // the bundle SSO — one customer never sees another's projects). Omitting
        // it keeps the legacy single-managed-org behavior for non-bundle managed
        // projects.
        $response = Http::asJson()
            ->acceptJson()
            ->withToken($token)
            ->timeout(20)
            ->post($base.'/api/provision', array_filter([
                'name' => $projectName,
                'organization_id' => $externalOrgId === null && $org !== '' ? $org : null,
                'external_org_id' => $externalOrgId,
                'external_org_name' => $externalOrgName,
                'retention_days' => $retentionDays !== null && $retentionDays > 0 ? $retentionDays : null,
            ], fn ($v) => $v !== null));

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Lookout rejected the provisioning token.');
        }
        if ($response->status() === 503) {
            throw new RuntimeException('Lookout provisioning is not enabled on that instance.');
        }
        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        $data = (array) $response->json();
        $project = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

        $apiKey = trim((string) ($project['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('Lookout provisioned the project but returned no ingest key.');
        }

        return [
            'api_key' => $apiKey,
            'dsn' => trim((string) ($project['ingest_dsn'] ?? '')) ?: $this->buildDsn($apiKey, $base),
            'project_id' => isset($project['id']) ? (string) $project['id'] : null,
            'project_name' => trim((string) ($project['name'] ?? $projectName)) ?: $projectName,
        ];
    }

    /**
     * Tear down a managed project on the Lookout side (the "managed" account
     * model), using the service token against DELETE /api/provision/{id}.
     * Best-effort by contract: returns false (never throws) when the project id
     * is empty, the token is unset, or Lookout rejects/404s — the local row is
     * removed regardless, so a lingering remote project is at worst dormant.
     */
    public function deprovision(string $projectId): bool
    {
        $projectId = trim($projectId);
        $token = trim((string) config('services.lookout.provision_token', ''));
        if ($projectId === '' || $token === '') {
            return false;
        }

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->timeout(20)
                ->delete($this->baseUrl().'/api/provision/'.rawurlencode($projectId));

            // A 404 means it's already gone — treat as success for teardown.
            return $response->successful() || $response->status() === 404;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Best-effort list of organizations the customer's token can create projects
     * under, so the modal can offer a picker instead of a raw ULID. Reads the
     * existing `GET /api/v1/me` payload (it already returns `organizations`).
     * Returns [] when the call fails — the UI then falls back to free text.
     *
     * @return list<array{id: string, name: string}>
     */
    public function organizations(string $apiToken): array
    {
        try {
            $response = Http::acceptJson()
                ->withToken($apiToken)
                ->timeout(15)
                ->get($this->baseUrl().'/api/v1/me');

            if (! $response->successful()) {
                return [];
            }

            $body = (array) $response->json();
            $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;
            $rows = is_array($data['organizations'] ?? null) ? $data['organizations'] : [];

            return collect($rows)
                ->map(static fn ($o): array => [
                    'id' => (string) (is_array($o) ? ($o['id'] ?? '') : ''),
                    'name' => (string) (is_array($o) ? ($o['name'] ?? $o['id'] ?? '') : ''),
                ])
                ->filter(static fn (array $o): bool => $o['id'] !== '')
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Canonical `https://<api_key>@<host>` DSN the lookout/tracing SDK parses. */
    private function buildDsn(string $apiKey, string $base): string
    {
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$apiKey.'@'.$host.$port;
    }

    private function baseUrl(): string
    {
        $url = trim((string) config('services.lookout.url', 'https://uselookout.app'));

        return rtrim($url !== '' ? $url : 'https://uselookout.app', '/');
    }

    /** @param  mixed $body */
    private function errorMessage($body, int $status): string
    {
        if (is_array($body)) {
            $message = trim((string) ($body['message'] ?? ''));
            if ($message !== '') {
                return 'Lookout: '.$message;
            }
        }

        return 'Lookout could not create the project (HTTP '.$status.').';
    }
}
