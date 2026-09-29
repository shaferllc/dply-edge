<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers\Api;

use App\Models\Organization;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Key-value stores for API tokens / the CLI: admin access, not a data path.
 * Every call goes through Cloudflare's REST API, whose rate limit the whole
 * platform account shares, so routes sit behind the per-organization
 * `kv-api` limiter. Apps read and write through their internal host or binding.
 */
class EdgeKvApiController extends EdgeApiController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => array_map(
            static fn (array $store): array => ['id' => $store['id'], 'name' => $store['label']],
            $this->stores($this->organization($request)),
        )]);
    }

    public function keys(Request $request, string $store): JsonResponse
    {
        // Values can hold sessions and tokens: the dashboard shows keys only to
        // roles that can change the app, so a Viewer token cannot read them here.
        $this->authorizeOrganizationWrite($request);
        $namespace = $this->namespace($request, $store);
        if ($namespace === null) {
            return $this->notFound('Key-value store not found.');
        }
        $data = $request->validate(['prefix' => ['nullable', 'string', 'max:512'], 'cursor' => ['nullable', 'string', 'max:1024']]);

        try {
            $page = EdgeCloudflareClient::fromConfig()->listKvKeysPage($namespace, $data['cursor'] ?? null, $data['prefix'] ?? null, 1000);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $page['keys'], 'cursor' => $page['cursor']]);
    }

    public function show(Request $request, string $store, string $key): JsonResponse
    {
        // Same gate as keys().
        $this->authorizeOrganizationWrite($request);
        $namespace = $this->namespace($request, $store);
        if ($namespace === null) {
            return $this->notFound('Key-value store not found.');
        }

        try {
            $client = EdgeCloudflareClient::fromConfig();
            $value = $client->getKvValue($namespace, $key);
            if ($value === null) {
                return $this->notFound('Key not found.');
            }
            $metadata = $client->getKvMetadata($namespace, $key);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Values are bytes; JSON needs UTF-8, so anything else comes back base64.
        $text = mb_check_encoding($value, 'UTF-8');

        return response()->json(['data' => [
            'key' => $key,
            'value' => $text ? $value : base64_encode($value),
            'encoding' => $text ? 'utf-8' : 'base64',
            'metadata' => $metadata,
        ]]);
    }

    public function update(Request $request, string $store, string $key): JsonResponse
    {
        $this->authorizeOrganizationWrite($request);
        $namespace = $this->namespace($request, $store);
        if ($namespace === null) {
            return $this->notFound('Key-value store not found.');
        }
        $data = $request->validate([
            'value' => ['present', 'nullable', 'string', 'max:1000000'],
            'ttl' => ['nullable', 'integer', 'min:60', 'prohibits:expires_at'],
            'expires_at' => ['nullable', 'integer', 'gte:'.(time() + 60)],
            'metadata' => ['nullable', 'array'],
        ], ['expires_at.gte' => 'expires_at must be a unix time at least 60 seconds ahead.']);
        if (isset($data['metadata']) && strlen((string) json_encode($data['metadata'])) > 1024) {
            return response()->json(['message' => 'metadata must be 1024 bytes or less as JSON.'], 422);
        }

        try {
            EdgeCloudflareClient::fromConfig()->putKvValue($namespace, $key, (string) ($data['value'] ?? ''), $data['ttl'] ?? null, $data['expires_at'] ?? null, $data['metadata'] ?? null);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['key' => $key, 'saved' => true]]);
    }

    public function destroy(Request $request, string $store, string $key): JsonResponse
    {
        $this->authorizeOrganizationWrite($request);
        $namespace = $this->namespace($request, $store);
        if ($namespace === null) {
            return $this->notFound('Key-value store not found.');
        }

        try {
            EdgeCloudflareClient::fromConfig()->deleteKvValue($namespace, $key);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['key' => $key, 'deleted' => true]]);
    }

    /** The store's namespace id, matched by id or name within this organization. */
    private function namespace(Request $request, string $store): ?string
    {
        foreach ($this->stores($this->organization($request)) as $row) {
            if ($row['id'] === $store || $row['label'] === $store) {
                return $row['id'];
            }
        }

        return null;
    }

    /**
     * Listing namespaces walks every page of the shared platform account, so
     * the account-wide list is cached once for every organization, empty or
     * not, and filtered by owner here. A Cloudflare failure throws and is not
     * cached. A store created in the last two minutes may not show yet.
     *
     * @return list<array{id: string, label: string}>
     */
    private function stores(Organization $organization): array
    {
        try {
            $namespaces = Cache::remember('edge-kv-api-namespaces', now()->addMinutes(2), static fn (): array => EdgeCloudflareClient::fromConfig()->listKvNamespaces());
        } catch (Throwable) {
            abort(response()->json(['message' => 'Cloudflare did not answer. Try again.'], 503));
        }
        $prefix = EdgeContainerConnections::ownedPrefix($organization);
        $stores = [];
        foreach ($namespaces as $row) {
            $title = (string) ($row['title'] ?? '');
            $id = (string) ($row['id'] ?? '');
            if ($id !== '' && str_starts_with($title, $prefix)) {
                $stores[] = ['id' => $id, 'label' => substr($title, strlen($prefix))];
            }
        }

        return $stores;
    }
}
