<?php

declare(strict_types=1);

namespace App\Modules\Providers\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Read-only calls to a Kubernetes API server with a ServiceAccount bearer
 * token (deploy/k8s-readonly/rbac.yaml). Used by /admin/cluster. Every call
 * throws on a non-2xx except podMetrics(), which is optional.
 */
final class KubernetesReadClient
{
    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $token,
        private readonly ?string $caPemBase64 = null,
    ) {}

    /** Null when DPLY_K8S_API_URL / DPLY_K8S_TOKEN are not set. */
    public static function fromConfig(): ?self
    {
        $url = trim((string) config('dply.kubernetes.api_url'));
        $token = trim((string) config('dply.kubernetes.token'));
        if ($url === '' || $token === '') {
            return null;
        }
        $ca = trim((string) config('dply.kubernetes.ca'));

        return new self(rtrim($url, '/'), $token, $ca !== '' ? $ca : null);
    }

    /** @return list<array<string, mixed>> */
    public function nodes(): array
    {
        return $this->items('/api/v1/nodes');
    }

    /** @return list<array<string, mixed>> */
    public function pods(): array
    {
        return $this->items('/api/v1/pods');
    }

    /** @return list<array<string, mixed>> */
    public function warningEvents(): array
    {
        return $this->items('/api/v1/events', ['fieldSelector' => 'type=Warning']);
    }

    /**
     * Empty when metrics-server is missing or not ready: the page still works.
     *
     * @return list<array<string, mixed>>
     */
    public function podMetrics(): array
    {
        $response = $this->http()->get('/apis/metrics.k8s.io/v1beta1/pods');

        return $response->successful() ? array_values((array) $response->json('items', [])) : [];
    }

    public function logs(string $namespace, string $pod, string $container, bool $previous = false, int $tailLines = 200): string
    {
        $path = sprintf('/api/v1/namespaces/%s/pods/%s/log', rawurlencode($namespace), rawurlencode($pod));

        return $this->http()
            ->get($path, ['container' => $container, 'tailLines' => $tailLines, 'previous' => $previous ? 'true' : 'false'])
            ->throw()
            ->body();
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function items(string $path, array $query = []): array
    {
        return array_values((array) $this->http()->get($path, $query)->throw()->json('items', []));
    }

    private function http(): PendingRequest
    {
        $request = Http::baseUrl($this->apiUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::TIMEOUT_SECONDS);

        return $this->caPemBase64 !== null ? $request->withOptions(['verify' => $this->caFile()]) : $request;
    }

    /** Guzzle wants a file path; write the CA once per distinct value. */
    private function caFile(): string
    {
        $path = storage_path('framework/cache/k8s-ca-'.md5($this->caPemBase64).'.pem');
        if (! is_file($path)) {
            file_put_contents($path, base64_decode($this->caPemBase64, true) ?: $this->caPemBase64);
        }

        return $path;
    }
}
