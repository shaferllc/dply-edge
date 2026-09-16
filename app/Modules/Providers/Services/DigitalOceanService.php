<?php

namespace App\Modules\Providers\Services;

use App\Models\ProviderCredential;
use App\Services\Concerns\ManagesDoCatalog;
use App\Services\Concerns\ManagesDoDomainsSshKeys;
use App\Services\Concerns\ManagesDoDroplets;
use App\Services\Concerns\ManagesDoKubernetes;
use App\Services\Concerns\ManagesDoSpacesRegistry;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DigitalOceanService
{
    use ManagesDoCatalog;
    use ManagesDoDomainsSshKeys;
    use ManagesDoDroplets;
    use ManagesDoKubernetes;
    use ManagesDoSpacesRegistry;

    protected string $baseUrl = 'https://api.digitalocean.com/v2';

    protected string $token;

    /**
     * @param  ProviderCredential|non-empty-string  $credentialOrToken  Saved credential or a raw API token string.
     */
    public function __construct(ProviderCredential|string $credentialOrToken)
    {
        $token = $credentialOrToken instanceof ProviderCredential
            ? $credentialOrToken->getApiToken()
            : $credentialOrToken;
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            throw new \InvalidArgumentException('DigitalOcean API token is required.');
        }
        $this->token = $token;
    }

    /**
     * Catalog GETs stay short so the create wizard cannot burn the 30s PHP
     * request budget. Writes (cluster create/resize) often sit idle for
     * tens of seconds before DigitalOcean sends the first byte — an 8s
     * cap surfaces as cURL 28 with a valid token.
     */
    public static function transferTimeoutSeconds(string $method): int
    {
        return in_array(strtolower($method), ['post', 'put', 'patch', 'delete'], true)
            ? 60
            : 8;
    }

    /**
     * @param  array<string, mixed>  $bodyOrQuery
     */
    protected function request(string $method, string $path, array $bodyOrQuery = []): Response
    {
        $url = $this->baseUrl.$path;
        $method = strtolower($method);
        $request = Http::withToken($this->token)
            ->acceptJson()
            ->contentType('application/json')
            ->connectTimeout(5)
            ->timeout(self::transferTimeoutSeconds($method));

        if ($method === 'get') {
            return $request->get($url, $bodyOrQuery);
        }
        if ($method === 'post') {
            return $request->post($url, $bodyOrQuery);
        }
        if ($method === 'put') {
            return $request->put($url, $bodyOrQuery);
        }
        if ($method === 'delete') {
            return $request->delete($url);
        }

        throw new \InvalidArgumentException("Unsupported method: {$method}");
    }

    protected function assertSuccess(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('message') ?? $response->json('error') ?? $response->body() ?: $response->reason();

        throw new \RuntimeException("DigitalOcean API failed to {$action}: {$message}");
    }

    /**
     * Whether this DigitalOcean account hosts the given domain (zone). 404 means
     * not found; any other non-2xx is surfaced as an error.
     */
    public function domainExists(string $domainName): bool
    {
        $response = $this->request('get', '/domains/'.rawurlencode(strtolower(trim($domainName))));
        if ($response->status() === 404) {
            return false;
        }

        $this->assertSuccess($response, 'look up domain');

        return true;
    }
}
