<?php

namespace App\Modules\Providers\Services;

use App\Models\ProviderCredential;
use App\Services\Concerns\ManagesHetznerDns;
use App\Services\Concerns\ManagesHetznerFirewall;
use App\Services\Concerns\ManagesHetznerInstances;
use App\Services\Concerns\ManagesHetznerLoadBalancers;
use App\Services\Concerns\ManagesHetznerNetworks;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HetznerService
{
    use ManagesHetznerDns;
    use ManagesHetznerFirewall;
    use ManagesHetznerInstances;
    use ManagesHetznerLoadBalancers;
    use ManagesHetznerNetworks;

    protected string $baseUrl = 'https://api.hetzner.cloud/v1';

    protected string $token;

    /**
     * Accepts either a customer's connected ProviderCredential (BYO servers) or a
     * raw API token (dply-managed servers provisioned on dply's own Hetzner project).
     */
    public function __construct(ProviderCredential|string $credentialOrToken)
    {
        $token = $credentialOrToken instanceof ProviderCredential
            ? $credentialOrToken->getApiToken()
            : trim($credentialOrToken);

        if (empty($token)) {
            throw new \InvalidArgumentException('Hetzner API token is required.');
        }

        $this->token = $token;
    }

    /**
     * Build a service bound to a raw API token (e.g. dply's platform Hetzner project).
     */
    public static function fromToken(string $token): self
    {
        return new self($token);
    }

    // ─── Server actions (snapshot bake) ─────────────────────────────────────────

    // ─── Load Balancers ───────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $body
     */
    protected function request(string $method, string $path, array $body = []): Response
    {
        $url = $this->baseUrl.$path;
        $request = Http::withToken($this->token)
            ->acceptJson()
            ->contentType('application/json');

        if (strtolower($method) === 'get' && $body !== []) {
            return $request->get($url, $body);
        }
        if (strtolower($method) === 'get') {
            return $request->get($url);
        }
        if (strtolower($method) === 'post') {
            return $request->post($url, $body);
        }
        if (strtolower($method) === 'delete') {
            return $request->delete($url);
        }

        throw new \InvalidArgumentException("Unsupported method: {$method}");
    }

    protected function assertSuccess(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message') ?? $response->body();
        throw new \RuntimeException("Hetzner API failed to {$action}: {$message}");
    }
}
