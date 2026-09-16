<?php

namespace App\Modules\Providers\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OVH Public Cloud API client.
 *
 * Unlike single-token providers (Vultr/Linode), OVH signs every request with a
 * trio of keys — Application Key, Application Secret, Consumer Key — minted at
 * https://api.ovh.com/createToken/. The signature is:
 *
 *   '$1$' . sha1(appSecret + '+' + consumerKey + '+' + METHOD + '+' + URL + '+' + BODY + '+' + timestamp)
 *
 * Servers are "instances" that live under a Cloud *project* (serviceName), not
 * directly under the account — list them with {@see getProjects()} first, then
 * operate on /cloud/project/{serviceName}/instance.
 */
class OvhService
{
    /** @var array<string, string> endpoint slug => API base host */
    private const ENDPOINTS = [
        'ovh-eu' => 'https://eu.api.ovh.com/1.0',
        'ovh-us' => 'https://api.us.ovhcloud.com/1.0',
        'ovh-ca' => 'https://ca.api.ovh.com/1.0',
    ];

    protected string $baseUrl;

    protected string $applicationKey;

    protected string $applicationSecret;

    protected string $consumerKey;

    /**
     * Cloud project (serviceName) instances live under. Captured from the saved
     * credential when present; otherwise resolved lazily via {@see projectId()}.
     */
    protected ?string $project;

    /**
     * Offset (seconds) between local clock and the OVH server clock. The
     * signature timestamp must match OVH's time or requests are rejected, so we
     * sync once lazily via GET /auth/time.
     */
    protected ?int $timeDelta = null;

    /**
     * @param  ProviderCredential|array{endpoint?: string, application_key: string, application_secret: string, consumer_key: string}  $credentialOrKeys
     */
    public function __construct(ProviderCredential|array $credentialOrKeys)
    {
        $creds = $credentialOrKeys instanceof ProviderCredential
            ? ($credentialOrKeys->credentials ?? [])
            : $credentialOrKeys;

        $endpoint = trim((string) ($creds['endpoint'] ?? 'ovh-eu'));
        $this->applicationKey = trim((string) ($creds['application_key'] ?? ''));
        $this->applicationSecret = trim((string) ($creds['application_secret'] ?? ''));
        $this->consumerKey = trim((string) ($creds['consumer_key'] ?? ''));
        $project = trim((string) ($creds['project'] ?? ''));
        $this->project = $project !== '' ? $project : null;

        if ($this->applicationKey === '' || $this->applicationSecret === '' || $this->consumerKey === '') {
            throw new \InvalidArgumentException('OVH Application Key, Application Secret and Consumer Key are all required.');
        }

        $this->baseUrl = self::ENDPOINTS[$endpoint] ?? self::ENDPOINTS['ovh-eu'];
    }

    /**
     * Validate the credential trio. Confirms the consumer key is validated and
     * not expired by reading its own status — throws on any auth failure.
     */
    public function validateToken(): void
    {
        $response = $this->request('get', '/auth/currentCredential');
        $this->assertSuccess($response, 'validate credentials');

        $status = (string) ($response->json('status') ?? '');
        if ($status !== '' && $status !== 'validated') {
            throw new \RuntimeException("OVH credential is not usable (status: {$status}).");
        }
    }

    /**
     * List Cloud project IDs (serviceName) the credential can access.
     *
     * @return list<string>
     */
    public function getProjects(): array
    {
        $response = $this->request('get', '/cloud/project');
        $this->assertSuccess($response, 'list projects');

        $ids = $response->json();

        return is_array($ids) ? array_values(array_map('strval', $ids)) : [];
    }

    /**
     * The Cloud project (serviceName) bound to this client. Uses the credential's
     * stored project when present, otherwise the account's first project. Throws
     * when the account has no Cloud project at all.
     */
    public function projectId(): string
    {
        if ($this->project !== null) {
            return $this->project;
        }

        $projects = $this->getProjects();
        if ($projects === []) {
            throw new \RuntimeException('OVH account has no Cloud project — create one in the OVH manager first.');
        }

        return $this->project = $projects[0];
    }

    /**
     * Resolve an OS image id by name within a region. `$nameContains` is matched
     * case-insensitively against the image name (e.g. "Ubuntu 24.04").
     */
    public function resolveImageId(string $project, string $region, string $nameContains): string
    {
        $needle = strtolower(trim($nameContains));

        foreach ($this->getImages($project, $region) as $image) {
            $name = strtolower((string) ($image['name'] ?? ''));
            if ($needle !== '' && str_contains($name, $needle)) {
                $id = (string) ($image['id'] ?? '');
                if ($id !== '') {
                    return $id;
                }
            }
        }

        throw new \RuntimeException("OVH image matching \"{$nameContains}\" not found in region {$region}.");
    }

    /**
     * Get a Cloud project's detail (description, status, ...).
     *
     * @return array<string, mixed>
     */
    public function getProject(string $project): array
    {
        $response = $this->request('get', '/cloud/project/'.rawurlencode($project));
        $this->assertSuccess($response, 'get project');

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * List regions available to a project.
     *
     * @return list<string>
     */
    public function getRegions(string $project): array
    {
        $response = $this->request('get', '/cloud/project/'.rawurlencode($project).'/region');
        $this->assertSuccess($response, 'list regions');

        $regions = $response->json();

        return is_array($regions) ? array_values(array_map('strval', $regions)) : [];
    }

    /**
     * List instance flavors (sizes). Optionally filter by region.
     *
     * @return list<string>
     */
    public function getFlavors(string $project, ?string $region = null): array
    {
        $query = $region !== null && $region !== '' ? ['region' => $region] : [];
        $response = $this->request('get', '/cloud/project/'.rawurlencode($project).'/flavor', $query);
        $this->assertSuccess($response, 'list flavors');

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * List OS images. Optionally filter by region.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getImages(string $project, ?string $region = null): array
    {
        $query = $region !== null && $region !== '' ? ['region' => $region] : [];
        $response = $this->request('get', '/cloud/project/'.rawurlencode($project).'/image', $query);
        $this->assertSuccess($response, 'list images');

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Upload an SSH public key to a project and return its OVH key id.
     */
    public function createSshKey(string $project, string $name, string $publicKey): string
    {
        $response = $this->request('post', '/cloud/project/'.rawurlencode($project).'/sshkey', [
            'name' => $name,
            'publicKey' => trim($publicKey),
        ]);
        $this->assertSuccess($response, 'create SSH key');

        $id = $response->json('id');
        if (empty($id)) {
            throw new \RuntimeException('OVH API did not return an SSH key id.');
        }

        return (string) $id;
    }

    /**
     * Create an instance and return its ID. `flavorId`/`imageId`/`sshKeyId` are
     * OVH ids resolved from {@see getFlavors()}/{@see getImages()}/{@see createSshKey()}.
     */
    public function createInstance(
        string $project,
        string $region,
        string $flavorId,
        string $imageId,
        string $name,
        ?string $sshKeyId = null,
    ): string {
        $body = [
            'flavorId' => $flavorId,
            'imageId' => $imageId,
            'name' => $name,
            'region' => $region,
        ];
        if ($sshKeyId !== null && $sshKeyId !== '') {
            $body['sshKeyId'] = $sshKeyId;
        }

        $response = $this->request('post', '/cloud/project/'.rawurlencode($project).'/instance', $body);
        $this->assertSuccess($response, 'create instance');

        $id = $response->json('id');
        if (empty($id)) {
            throw new \RuntimeException('OVH API did not return an instance id.');
        }

        return (string) $id;
    }

    /**
     * Get an instance by ID. Returns the decoded JSON.
     *
     * @return array<string, mixed>
     */
    public function getInstance(string $project, string $id): array
    {
        $response = $this->request('get', '/cloud/project/'.rawurlencode($project).'/instance/'.rawurlencode($id));
        $this->assertSuccess($response, 'get instance');

        $data = $response->json();
        if (! is_array($data) || $data === []) {
            throw new \RuntimeException('OVH API did not return an instance.');
        }

        return $data;
    }

    /**
     * Delete an instance. A 404 (already gone) is treated as success.
     */
    public function deleteInstance(string $project, string $id): void
    {
        if ($id === '') {
            return;
        }

        $response = $this->request('delete', '/cloud/project/'.rawurlencode($project).'/instance/'.rawurlencode($id));
        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccess($response, 'delete instance');
    }

    /**
     * Public IPv4 from an instance payload. OVH exposes addresses under
     * `ipAddresses` as a list of {ip, type, version}.
     *
     * @param  array<string, mixed>  $instance
     */
    public static function getPublicIp(array $instance): ?string
    {
        foreach ($instance['ipAddresses'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if ((string) ($entry['type'] ?? '') === 'public' && (int) ($entry['version'] ?? 0) === 4) {
                $ip = trim((string) ($entry['ip'] ?? ''));
                if ($ip !== '') {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Private IPv4 from an instance payload (type `private`, IPv4).
     *
     * @param  array<string, mixed>  $instance
     */
    public static function getPrivateIp(array $instance): ?string
    {
        foreach ($instance['ipAddresses'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if ((string) ($entry['type'] ?? '') === 'private' && (int) ($entry['version'] ?? 0) === 4) {
                $ip = trim((string) ($entry['ip'] ?? ''));
                if ($ip !== '') {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Sync the local/OVH clock offset once. OVH rejects signatures whose
     * timestamp drifts from its server time, so we anchor on GET /auth/time.
     */
    protected function ovhTimestamp(): int
    {
        if ($this->timeDelta === null) {
            $response = Http::acceptJson()->get($this->baseUrl.'/auth/time');
            $serverTime = (int) $response->body();
            $this->timeDelta = $serverTime > 0 ? $serverTime - time() : 0;
        }

        return time() + $this->timeDelta;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function request(string $method, string $path, array $body = []): Response
    {
        $method = strtolower($method);
        $url = $this->baseUrl.$path;

        // GET query params are part of the signed URL.
        if ($method === 'get' && $body !== []) {
            $url .= '?'.http_build_query($body);
            $body = [];
        }

        // The signed body must be byte-identical to the transmitted body. GET and
        // DELETE carry none; POST/PUT always send a JSON object (never empty).
        $payload = in_array($method, ['post', 'put'], true)
            ? json_encode($body === [] ? new \stdClass : $body, JSON_UNESCAPED_SLASHES)
            : '';
        $timestamp = $this->ovhTimestamp();

        $signature = '$1$'.sha1(implode('+', [
            $this->applicationSecret,
            $this->consumerKey,
            strtoupper($method),
            $url,
            $payload,
            $timestamp,
        ]));

        $request = Http::withHeaders([
            'X-Ovh-Application' => $this->applicationKey,
            'X-Ovh-Consumer' => $this->consumerKey,
            'X-Ovh-Timestamp' => (string) $timestamp,
            'X-Ovh-Signature' => $signature,
            'Content-Type' => 'application/json',
        ])->acceptJson();

        return match ($method) {
            'get' => $request->get($url),
            'post' => $request->withBody($payload, 'application/json')->post($url),
            'put' => $request->withBody($payload, 'application/json')->put($url),
            'delete' => $request->delete($url),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };
    }

    protected function assertSuccess(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('message') ?? ($response->body() ?: $response->reason());

        throw new \RuntimeException("OVH API failed to {$action}: {$message}");
    }
}
