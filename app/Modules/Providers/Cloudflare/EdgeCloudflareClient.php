<?php

declare(strict_types=1);

namespace App\Modules\Providers\Cloudflare;

use App\Modules\Billing\Services\EdgeUsageTotals;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Minimal Cloudflare API client for dply Edge platform provisioning.
 */
class EdgeCloudflareClient
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

    /**
     * @return array<string, mixed>
     */
    public function verifyToken(): array
    {
        return $this->decode(
            Http::withToken($this->apiToken)->get(self::BASE.'/user/tokens/verify'),
        );
    }

    public function listAccounts(): array
    {
        $payload = $this->decode(
            Http::withToken($this->apiToken)->get(self::BASE.'/accounts'),
        );

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listR2Buckets(): array
    {
        $payload = $this->decode(
            Http::withToken($this->apiToken)
                ->get(self::BASE.'/accounts/'.$this->accountId.'/r2/buckets'),
        );

        return is_array($payload['buckets'] ?? null) ? $payload['buckets'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function createR2Bucket(string $name, ?string $locationHint = null, ?string $jurisdiction = null): array
    {
        // Cloudflare R2 jurisdictions: "default", "eu", "fedramp".
        // locationHint: a hub like "weur", "eeur", "apac", "wnam", "enam".
        // When both are unset, the bucket is created with default
        // settings (Cloudflare picks the location).
        $body = ['name' => $name];
        if ($locationHint !== null && $locationHint !== '') {
            $body['locationHint'] = $locationHint;
        }

        $headers = [];
        if ($jurisdiction !== null && $jurisdiction !== '' && $jurisdiction !== 'default') {
            $headers['cf-r2-jurisdiction'] = $jurisdiction;
        }

        return $this->decode(
            Http::withToken($this->apiToken)
                ->withHeaders($headers)
                ->post(self::BASE.'/accounts/'.$this->accountId.'/r2/buckets', $body),
        );
    }

    public function r2BucketExists(string $name): bool
    {
        foreach ($this->listR2Buckets() as $bucket) {
            if (($bucket['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listKvNamespaces(): array
    {
        $payload = $this->decode(
            Http::withToken($this->apiToken)
                ->get(self::BASE.'/accounts/'.$this->accountId.'/storage/kv/namespaces'),
        );

        // decode() already unwraps `result`, so $payload IS the namespace list.
        // (Mirrors listD1Databases/listQueues; the previous `$payload['result']`
        // re-index always yielded [] → ensureKvNamespace never matched an
        // existing namespace and re-created one on every call.)
        if (isset($payload['value']) && is_array($payload['value'])) {
            return $payload['value'];
        }

        return $payload !== [] && array_is_list($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function createKvNamespace(string $title): array
    {
        return $this->decode(
            Http::withToken($this->apiToken)
                ->post(self::BASE.'/accounts/'.$this->accountId.'/storage/kv/namespaces', [
                    'title' => $title,
                ]),
        );
    }

    public function kvNamespaceIdByTitle(string $title): ?string
    {
        foreach ($this->listKvNamespaces() as $namespace) {
            if (($namespace['title'] ?? null) === $title && is_string($namespace['id'] ?? null)) {
                return $namespace['id'];
            }
        }

        return null;
    }

    /**
     * Resolve an existing KV namespace by title or create it.
     */
    public function ensureKvNamespace(string $title): string
    {
        $existing = $this->kvNamespaceIdByTitle($title);
        if ($existing !== null) {
            return $existing;
        }

        $created = $this->createKvNamespace($title);
        $id = is_string($created['id'] ?? null) ? trim($created['id']) : '';
        if ($id === '') {
            throw new RuntimeException('Cloudflare did not return an id when creating KV namespace '.$title.'.');
        }

        return $id;
    }

    /**
     * Workers for Platforms — list dispatch namespaces under this account.
     *
     * @return list<array<string, mixed>>
     */
    public function listDispatchNamespaces(): array
    {
        $payload = $this->decode(
            Http::withToken($this->apiToken)
                ->get(self::BASE.'/accounts/'.$this->accountId.'/workers/dispatch/namespaces'),
        );

        return array_values(array_filter($payload, 'is_array'));
    }

    public function createDispatchNamespace(string $name): array
    {
        return $this->decode(
            Http::withToken($this->apiToken)
                ->post(self::BASE.'/accounts/'.$this->accountId.'/workers/dispatch/namespaces', [
                    'name' => $name,
                ]),
        );
    }

    public function dispatchNamespaceIdByName(string $name): ?string
    {
        foreach ($this->listDispatchNamespaces() as $namespace) {
            // The list endpoint returns namespace_name / namespace_id.
            if (($namespace['namespace_name'] ?? $namespace['name'] ?? null) === $name) {
                $id = $namespace['namespace_id'] ?? $namespace['id'] ?? null;
                if (is_string($id) && $id !== '') {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * Resolve an existing dispatch namespace by name or create it.
     * Returns the namespace id (UUID assigned by Cloudflare).
     */
    public function ensureDispatchNamespace(string $name): string
    {
        $existing = $this->dispatchNamespaceIdByName($name);
        if ($existing !== null) {
            return $existing;
        }

        $created = $this->createDispatchNamespace($name);
        $id = $created['namespace_id'] ?? $created['id'] ?? '';
        $id = is_string($id) ? trim($id) : '';
        if ($id === '') {
            throw new RuntimeException('Cloudflare did not return a namespace id when creating dispatch namespace '.$name.'.');
        }

        return $id;
    }

    /**
     * Upload a per-deployment Worker script into a dispatch namespace.
     * The script is private to the namespace — only callable via the
     * `env.DISPATCHER.get($scriptName).fetch(request)` binding from
     * the platform Worker, never via a public workers.dev URL.
     *
     * @param  string  $namespace  Namespace NAME (not id) — CF API uses name here.
     * @param  string  $entryModulePath  File name the metadata.main_module points at (e.g. "worker.js").
     * @param  array<string, mixed>  $modules  Map of module file name → module source. Must include $entryModulePath.
     * @param  list<array<string, mixed>>  $bindings  Cloudflare binding descriptors (kv_namespace, r2_bucket, plain_text, secret_text, etc.).
     * @param  array{compatibility_date?: string, compatibility_flags?: list<string>, tags?: list<string>}  $metaExtras
     * @return array<string, mixed>
     */
    public function uploadDispatchScript(
        string $namespace,
        string $scriptName,
        string $entryModulePath,
        array $modules,
        array $bindings = [],
        array $metaExtras = [],
    ): array {
        if ($namespace === '' || $scriptName === '') {
            throw new RuntimeException('Dispatch namespace + script name are required.');
        }
        if (! array_key_exists($entryModulePath, $modules)) {
            throw new RuntimeException('Entry module '.$entryModulePath.' is missing from the modules map.');
        }

        $metadata = array_merge(
            [
                'main_module' => $entryModulePath,
                'bindings' => $bindings,
            ],
            array_intersect_key($metaExtras, array_flip(['compatibility_date', 'compatibility_flags', 'tags'])),
        );

        // Laravel HTTP's attach() converts to Guzzle multipart parts.
        // Each part has its own Content-Type so Cloudflare can tell
        // the metadata JSON apart from the JS module bodies.
        $request = Http::withToken($this->apiToken)
            ->attach(
                'metadata',
                json_encode($metadata, JSON_THROW_ON_ERROR),
                'metadata.json',
                ['Content-Type' => 'application/json'],
            );

        foreach ($modules as $moduleName => $source) {
            $request = $request->attach(
                $moduleName,
                $source,
                $moduleName,
                ['Content-Type' => 'application/javascript+module'],
            );
        }

        $response = $request->put(
            self::BASE.'/accounts/'.$this->accountId
                .'/workers/dispatch/namespaces/'.rawurlencode($namespace)
                .'/scripts/'.rawurlencode($scriptName),
        );

        return $this->decode($response);
    }

    public function deleteDispatchScript(string $namespace, string $scriptName): void
    {
        if ($namespace === '' || $scriptName === '') {
            return;
        }

        $response = Http::withToken($this->apiToken)
            ->delete(
                self::BASE.'/accounts/'.$this->accountId
                    .'/workers/dispatch/namespaces/'.rawurlencode($namespace)
                    .'/scripts/'.rawurlencode($scriptName)
                    .'?force=true',
            );

        if ($response->status() === 404) {
            return;
        }

        $this->decode($response);
    }

    /**
     * Cloudflare cron triggers attached to a Workers for Platforms
     * dispatch script (P10b / Phase 4c). Pass an empty array to
     * clear all schedules.
     *
     * @param  list<string>  $schedules  Cron expressions ("0 * * * *")
     */
    public function setDispatchScriptSchedules(string $namespace, string $scriptName, array $schedules): void
    {
        if ($namespace === '' || $scriptName === '') {
            return;
        }

        $body = array_values(array_map(
            static fn (string $cron): array => ['cron' => trim($cron)],
            array_filter($schedules, static fn ($s) => ($s) && trim($s) !== ''),
        ));

        $this->decode(
            Http::withToken($this->apiToken)
                ->put(
                    self::BASE.'/accounts/'.$this->accountId
                        .'/workers/dispatch/namespaces/'.rawurlencode($namespace)
                        .'/scripts/'.rawurlencode($scriptName)
                        .'/schedules',
                    $body,
                ),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listD1Databases(): array
    {
        $payload = $this->decode(
            Http::withToken($this->apiToken)
                ->get(self::BASE.'/accounts/'.$this->accountId.'/d1/database'),
        );

        if (isset($payload['value']) && is_array($payload['value'])) {
            return $payload['value'];
        }

        return $payload && array_is_list($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function createD1Database(string $name, string $primaryLocationHint = 'wnam'): array
    {
        $body = ['name' => $name];
        $hint = trim($primaryLocationHint);
        if ($hint !== '') {
            $body['primary_location_hint'] = $hint;
        }

        return $this->decode(
            Http::withToken($this->apiToken)
                ->post(self::BASE.'/accounts/'.$this->accountId.'/d1/database', $body),
        );
    }

    /**
     * D1 database details (file_size, num_tables, running_in_region…).
     *
     * @return array<string, mixed>
     */
    public function getD1Database(string $databaseId): array
    {
        return $this->decode(Http::withToken($this->apiToken)->get(self::BASE.'/accounts/'.$this->accountId.'/d1/database/'.$databaseId));
    }

    /**
     * Run SQL against a D1 database. Returns one result per statement:
     * {results: list<row>, success, meta{changes, duration, rows_read, rows_written}}.
     *
     * @param  list<mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function queryD1(string $databaseId, string $sql, array $params = []): array
    {
        $payload = $this->decode(Http::withToken($this->apiToken)->timeout(35)->post(
            self::BASE.'/accounts/'.$this->accountId.'/d1/database/'.$databaseId.'/query',
            array_filter(['sql' => $sql, 'params' => $params], static fn ($v) => $v !== []),
        ));

        return array_values(array_filter($payload, 'is_array'));
    }

    public function deleteD1Database(string $databaseId): void
    {
        $response = Http::withToken($this->apiToken)->delete(self::BASE.'/accounts/'.$this->accountId.'/d1/database/'.$databaseId);
        if ($response->status() !== 404) {
            $this->decode($response);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listQueues(): array
    {
        $payload = $this->decode(
            Http::withToken($this->apiToken)
                ->get(self::BASE.'/accounts/'.$this->accountId.'/queues'),
        );

        if (isset($payload['value']) && is_array($payload['value'])) {
            return $payload['value'];
        }

        return $payload && array_is_list($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function createQueue(string $name): array
    {
        return $this->decode(
            Http::withToken($this->apiToken)
                ->post(self::BASE.'/accounts/'.$this->accountId.'/queues', [
                    'queue_name' => $name,
                ]),
        );
    }

    /**
     * Queue details: consumers, producers, settings.
     *
     * @return array<string, mixed>
     */
    public function getQueue(string $queueId): array
    {
        return $this->decode(Http::withToken($this->apiToken)->get(self::BASE.'/accounts/'.$this->accountId.'/queues/'.$queueId));
    }

    public function deleteQueue(string $queueId): void
    {
        $response = Http::withToken($this->apiToken)->delete(self::BASE.'/accounts/'.$this->accountId.'/queues/'.$queueId);
        if ($response->status() !== 404) {
            $this->decode($response);
        }
    }

    /** @param mixed $body JSON-serialisable message body */
    public function sendQueueMessage(string $queueId, mixed $body): void
    {
        $this->decode(Http::withToken($this->apiToken)->post(
            self::BASE.'/accounts/'.$this->accountId.'/queues/'.$queueId.'/messages',
            ['body' => $body, 'content_type' => 'json'],
        ));
    }

    /**
     * Latest backlog (messages waiting) per queue id over the last hour.
     *
     * @param  list<string>  $queueIds
     * @return array<string, int>
     */
    public function queueBacklogs(array $queueIds): array
    {
        if ($queueIds === []) {
            return [];
        }

        $query = <<<'GRAPHQL'
        query Backlog($accountTag: string!, $ids: [string!], $since: Time!) {
          viewer {
            accounts(filter: { accountTag: $accountTag }) {
              queueBacklogAdaptiveGroups(limit: 1000, filter: { queueId_in: $ids, datetime_geq: $since }, orderBy: [datetimeMinute_DESC]) {
                dimensions { queueId datetimeMinute }
                avg { messages }
              }
            }
          }
        }
        GRAPHQL;

        $json = Http::withToken($this->apiToken)->post(self::BASE.'/graphql', [
            'query' => $query,
            'variables' => ['accountTag' => $this->accountId, 'ids' => $queueIds, 'since' => now()->subHour()->toIso8601String()],
        ])->json();

        $out = [];
        foreach ((array) data_get($json, 'data.viewer.accounts.0.queueBacklogAdaptiveGroups', []) as $group) {
            $id = (string) data_get($group, 'dimensions.queueId', '');
            $out[$id] ??= (int) round((float) data_get($group, 'avg.messages', 0)); // newest minute first
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function zoneSetting(string $zoneId, string $settingId): ?array
    {
        $response = Http::withToken($this->apiToken)->get(
            self::BASE.'/zones/'.$zoneId.'/settings/'.$settingId,
        );

        $payload = $response->json();
        if (! is_array($payload) || ($payload['success'] ?? false) !== true) {
            return null;
        }

        $result = $payload['result'] ?? null;

        return is_array($result) ? $result : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function patchZoneSetting(string $zoneId, string $settingId, string $value): array
    {
        return $this->decode(
            Http::withToken($this->apiToken)->patch(
                self::BASE.'/zones/'.$zoneId.'/settings/'.$settingId,
                ['value' => $value],
            ),
        );
    }

    /**
     * Enable Cloudflare Image Resizing on a zone when the plan allows it.
     *
     * @return array{ok: bool, zone: string, value: ?string, detail: string}
     */
    public function ensureImageResizingEnabled(string $zoneName): array
    {
        $zoneName = strtolower(trim($zoneName));
        if ($zoneName === '') {
            return [
                'ok' => false,
                'zone' => '',
                'value' => null,
                'detail' => 'Zone name is empty.',
            ];
        }

        $zoneId = $this->activeZoneId($zoneName);
        if ($zoneId === null) {
            return [
                'ok' => false,
                'zone' => $zoneName,
                'value' => null,
                'detail' => 'Zone is not active on Cloudflare.',
            ];
        }

        $current = $this->zoneSetting($zoneId, 'image_resizing');
        $value = is_string($current['value'] ?? null) ? strtolower($current['value']) : '';
        if (in_array($value, ['on', 'open'], true)) {
            return [
                'ok' => true,
                'zone' => $zoneName,
                'value' => $value,
                'detail' => 'Image Resizing already enabled ('.$value.').',
            ];
        }

        try {
            $result = $this->patchZoneSetting($zoneId, 'image_resizing', 'on');
            $newValue = is_string($result['value'] ?? null) ? (string) $result['value'] : 'on';

            return [
                'ok' => true,
                'zone' => $zoneName,
                'value' => $newValue,
                'detail' => 'Image Resizing enabled.',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'zone' => $zoneName,
                'value' => $value !== '' ? $value : null,
                'detail' => $e->getMessage(),
            ];
        }
    }

    public function canCollectAnalytics(): bool
    {
        return $this->accountId !== ''
            && $this->apiToken !== ''
            && is_string(config('edge.cloudflare.worker_zone_name'))
            && config('edge.cloudflare.worker_zone_name') !== '';
    }

    /**
     * Pull zone HTTP request + bandwidth totals grouped by client request host.
     *
     * Uses Cloudflare GraphQL httpRequestsAdaptiveGroups. Requires Analytics
     * read on the API token and a resolvable zone for worker_zone_name.
     *
     * @param  list<string>  $hostnames
     * @return Collection<string, EdgeUsageTotals>
     */
    public function fetchHttpUsageByHostnames(
        array $hostnames,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        ?string $zoneName = null,
    ): Collection {
        if ($hostnames === [] || ! $this->canCollectAnalytics()) {
            return collect();
        }

        $zoneName = strtolower(trim($zoneName ?? (string) config('edge.cloudflare.worker_zone_name')));
        if ($zoneName === '') {
            throw new RuntimeException('Could not resolve Cloudflare zone for Edge analytics.');
        }

        $zoneId = $this->resolveZoneId($zoneName);
        if ($zoneId === null) {
            throw new RuntimeException('Could not resolve Cloudflare zone for Edge analytics.');
        }

        $query = <<<'GRAPHQL'
        query EdgeHttpUsage($zoneTag: string!, $since: Time!, $until: Time!) {
          viewer {
            zones(filter: { zoneTag: $zoneTag }) {
              httpRequestsAdaptiveGroups(
                limit: 10000
                filter: { datetime_geq: $since, datetime_leq: $until }
                orderBy: [count_DESC]
              ) {
                count
                dimensions { clientRequestHTTPHost }
                sum { edgeResponseBytes }
              }
            }
          }
        }
        GRAPHQL;

        $response = Http::withToken($this->apiToken)
            ->post(self::BASE.'/graphql', [
                'query' => $query,
                'variables' => [
                    'zoneTag' => $zoneId,
                    'since' => $periodStart->toIso8601String(),
                    'until' => $periodEnd->toIso8601String(),
                ],
            ]);

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Cloudflare GraphQL returned a non-JSON response.');
        }

        if (! empty($json['errors'])) {
            $message = is_array($json['errors'][0] ?? null)
                ? (string) ($json['errors'][0]['message'] ?? 'Cloudflare GraphQL request failed.')
                : 'Cloudflare GraphQL request failed.';

            throw new RuntimeException($message);
        }

        $groups = data_get($json, 'data.viewer.zones.0.httpRequestsAdaptiveGroups', []);
        if (! is_array($groups)) {
            return collect();
        }

        $normalizedHosts = array_fill_keys(
            array_map(static fn (string $host): string => strtolower($host), $hostnames),
            true,
        );

        /** @var Collection<string, EdgeUsageTotals> $totals */
        $totals = collect();

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $host = strtolower(trim((string) data_get($group, 'dimensions.clientRequestHTTPHost', '')));
            if ($host === '' || ! isset($normalizedHosts[$host])) {
                continue;
            }

            $requests = (int) data_get($group, 'count', 0);
            $bytes = (int) data_get($group, 'sum.edgeResponseBytes', 0);

            $existing = $totals->get($host, new EdgeUsageTotals);
            $totals->put($host, $existing->add(new EdgeUsageTotals(
                requests: $requests,
                bytesEgress: $bytes,
            )));
        }

        return $totals;
    }

    public function canQueryAnalyticsEngine(): bool
    {
        return $this->accountId !== ''
            && $this->apiToken !== ''
            && trim((string) config('edge.cloudflare.analytics_dataset', '')) !== '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queryAnalyticsEngineSql(string $sql): array
    {
        if (! $this->canQueryAnalyticsEngine()) {
            return [];
        }

        $response = Http::withToken($this->apiToken)
            ->withHeaders(['Content-Type' => 'text/plain'])
            ->withBody($sql, 'text/plain')
            ->post(self::BASE.'/accounts/'.$this->accountId.'/analytics_engine/sql');

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Analytics Engine SQL failed.');
        }

        if (array_key_exists('success', $json) && $json['success'] !== true) {
            $message = is_array($json['errors'][0] ?? null)
                ? (string) ($json['errors'][0]['message'] ?? 'Analytics Engine SQL failed.')
                : 'Analytics Engine SQL failed.';

            throw new RuntimeException($message);
        }

        $rows = $json['result']['data'] ?? $json['data'] ?? (is_array($json['result'] ?? null) && array_is_list($json['result']) ? $json['result'] : []);
        if (! is_array($rows)) {
            return [];
        }

        $meta = $json['result']['meta'] ?? $json['meta'] ?? [];
        if (is_array($meta) && $meta !== [] && isset($rows[0]) && is_array($rows[0]) && ! array_is_list($rows[0])) {
            return array_values(array_filter($rows, is_array(...)));
        }

        if (! is_array($meta) || $meta === [] || ! isset($rows[0]) || ! is_array($rows[0])) {
            return [];
        }

        $columns = array_map(
            static fn ($column): string => is_array($column) ? (string) ($column['name'] ?? '') : '',
            $meta,
        );

        $mapped = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $assoc = [];
            foreach ($columns as $index => $column) {
                if ($column === '') {
                    continue;
                }
                $assoc[$column] = $row[$index] ?? null;
            }

            $mapped[] = $assoc;
        }

        return $mapped;
    }

    public function ensureLogpushJob(string $zoneId, string $destinationConf, string $dataset = 'http_requests'): array
    {
        foreach ($this->listLogpushJobs($zoneId) as $job) {
            if (($job['dataset'] ?? null) === $dataset && ($job['enabled'] ?? false) === true) {
                return [
                    'id' => (string) ($job['id'] ?? ''),
                    'enabled' => true,
                ];
            }
        }

        $payload = $this->decode(
            Http::withToken($this->apiToken)->post(self::BASE.'/zones/'.$zoneId.'/logpush/jobs', [
                'name' => 'dply-edge-'.$dataset,
                'destination_conf' => $destinationConf,
                'dataset' => $dataset,
                'enabled' => true,
            ]),
        );

        return [
            'id' => (string) ($payload['id'] ?? ''),
            'enabled' => (bool) ($payload['enabled'] ?? true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLogpushJobs(string $zoneId): array
    {
        $response = Http::withToken($this->apiToken)->get(self::BASE.'/zones/'.$zoneId.'/logpush/jobs');
        $json = $response->json();
        if (! is_array($json) || ($json['success'] ?? false) !== true) {
            return [];
        }

        $result = $json['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    public function fetchR2BucketUsage(
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        ?string $bucketName = null,
    ): EdgeUsageTotals {
        $bucketName = trim($bucketName ?? (string) config('edge.r2.bucket', ''));
        if ($bucketName === '' || $this->accountId === '') {
            return EdgeUsageTotals::empty();
        }

        $query = <<<'GRAPHQL'
        query EdgeR2Usage($accountTag: string!, $since: Time!, $until: Time!, $bucket: string!) {
          viewer {
            accounts(filter: { accountTag: $accountTag }) {
              r2StorageAdaptiveGroups(
                limit: 100
                filter: { datetime_geq: $since, datetime_leq: $until, bucketName: $bucket }
              ) {
                max { payloadSize, metadataSize }
              }
              r2OperationsAdaptiveGroups(
                limit: 1000
                filter: { datetime_geq: $since, datetime_leq: $until, bucketName: $bucket }
              ) {
                sum { requests }
                dimensions { actionType }
              }
            }
          }
        }
        GRAPHQL;

        $response = Http::withToken($this->apiToken)
            ->post(self::BASE.'/graphql', [
                'query' => $query,
                'variables' => [
                    'accountTag' => $this->accountId,
                    'since' => $periodStart->toIso8601String(),
                    'until' => $periodEnd->toIso8601String(),
                    'bucket' => $bucketName,
                ],
            ]);

        $json = $response->json();
        if (! is_array($json) || ! empty($json['errors'])) {
            // Surface the real cause — HTTP status for transport failures,
            // Cloudflare's errors[] for auth/permission/schema problems.
            $detail = is_array($json['errors'] ?? null)
                ? json_encode($json['errors'], JSON_UNESCAPED_SLASHES)
                : Str::limit((string) $response->body(), 500);

            throw new RuntimeException(sprintf(
                'Cloudflare R2 GraphQL request failed (HTTP %d): %s',
                $response->status(),
                $detail !== '' ? $detail : 'no response body',
            ));
        }

        $storageGroups = data_get($json, 'data.viewer.accounts.0.r2StorageAdaptiveGroups', []);
        $operationGroups = data_get($json, 'data.viewer.accounts.0.r2OperationsAdaptiveGroups', []);

        // Cloudflare's R2 storage dataset reports max{payloadSize, metadataSize}
        // per sample. Billed storage = payload + metadata (object headers count
        // against quota), so sum them per group before taking the peak.
        $storedBytes = 0;
        if (is_array($storageGroups)) {
            foreach ($storageGroups as $group) {
                $groupBytes = (int) data_get($group, 'max.payloadSize', 0)
                    + (int) data_get($group, 'max.metadataSize', 0);
                $storedBytes = max($storedBytes, $groupBytes);
            }
        }

        $classA = 0;
        $classB = 0;
        $classAActions = [
            'PutObject', 'CopyObject', 'ListObjects', 'CreateMultipartUpload',
            'UploadPart', 'CompleteMultipartUpload', 'DeleteObject', 'AbortMultipartUpload',
        ];

        if (is_array($operationGroups)) {
            foreach ($operationGroups as $group) {
                $action = (string) data_get($group, 'dimensions.actionType', '');
                $requests = (int) data_get($group, 'sum.requests', 0);
                if (in_array($action, $classAActions, true)) {
                    $classA += $requests;
                } elseif (in_array($action, ['GetObject', 'HeadObject'], true)) {
                    $classB += $requests;
                }
            }
        }

        return new EdgeUsageTotals(
            r2StorageBytes: $storedBytes,
            r2ClassAOps: $classA,
            r2ClassBOps: $classB,
        );
    }

    public function activeZoneId(string $zoneName): ?string
    {
        return $this->resolveZoneId($zoneName);
    }

    private function resolveZoneId(string $zoneName): ?string
    {
        $zoneName = strtolower(trim($zoneName));
        if ($zoneName === '') {
            return null;
        }

        $response = Http::withToken($this->apiToken)->get(self::BASE.'/zones', [
            'name' => $zoneName,
            'status' => 'active',
            'per_page' => 1,
        ]);

        $json = $response->json();
        if (! is_array($json) || ($json['success'] ?? false) !== true) {
            return null;
        }

        $result = $json['result'] ?? [];
        if (! is_array($result) || ! isset($result[0]['id'])) {
            return null;
        }

        return (string) $result[0]['id'];
    }

    /**
     * Create a Custom Hostname (SSL for SaaS) on the managed Edge zone.
     *
     * @param  array<string, mixed>  $options  Extra Cloudflare payload fields (merged over defaults).
     * @return array<string, mixed>
     */
    public function createCustomHostname(string $zoneId, string $hostname, array $options = []): array
    {
        $sslMethod = (string) config('edge.custom_hostnames.ssl_method', 'http');
        if (! in_array($sslMethod, ['http', 'txt', 'email'], true)) {
            $sslMethod = 'http';
        }

        $payload = [
            'hostname' => strtolower(trim($hostname)),
            'ssl' => [
                'method' => $sslMethod,
                'type' => 'dv',
            ],
        ];

        $customOrigin = trim((string) config('edge.custom_hostnames.fallback_origin', ''));
        if ($customOrigin !== '') {
            $payload['custom_origin_server'] = $customOrigin;
        }

        /** @var array<string, mixed> $payload */
        $payload = array_replace_recursive($payload, $options);

        $response = Http::withToken($this->apiToken)
            ->post(self::BASE.'/zones/'.$zoneId.'/custom_hostnames', $payload);

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomHostname(string $zoneId, string $customHostnameId): array
    {
        $response = Http::withToken($this->apiToken)
            ->get(self::BASE.'/zones/'.$zoneId.'/custom_hostnames/'.$customHostnameId);

        return $this->decode($response);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCustomHostnames(string $zoneId, ?string $hostname = null): array
    {
        $query = ['per_page' => 50];
        if (is_string($hostname) && $hostname !== '') {
            $query['hostname'] = strtolower(trim($hostname));
        }

        $response = Http::withToken($this->apiToken)
            ->get(self::BASE.'/zones/'.$zoneId.'/custom_hostnames', $query);

        $payload = $this->decode($response);
        if ($payload === []) {
            return [];
        }

        // List endpoints return a numeric list in `result`.
        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteCustomHostname(string $zoneId, string $customHostnameId): array
    {
        $response = Http::withToken($this->apiToken)
            ->delete(self::BASE.'/zones/'.$zoneId.'/custom_hostnames/'.$customHostnameId);

        return $this->decode($response);
    }

    /**
     * Find an existing Custom Hostname by hostname (first page match).
     *
     * @return array<string, mixed>|null
     */
    public function findCustomHostnameByHostname(string $zoneId, string $hostname): ?array
    {
        $hostname = strtolower(trim($hostname));
        foreach ($this->listCustomHostnames($zoneId, $hostname) as $row) {
            if (strtolower((string) ($row['hostname'] ?? '')) === $hostname) {
                return $row;
            }
        }

        return null;
    }

    /**
     * One UTC day of D1 and Queues usage: rows read/written and peak size per
     * database, billable operations per queue.
     *
     * @return array{d1: array<string, array{rows_read: int, rows_written: int, storage_bytes: int}>, queues: array<string, int>}
     */
    public function dataUsageForDate(CarbonInterface $date): array
    {
        $query = <<<'GRAPHQL'
        query DataUsage($accountTag: string!, $date: Date!) {
          viewer {
            accounts(filter: { accountTag: $accountTag }) {
              d1AnalyticsAdaptiveGroups(limit: 10000, filter: { date_geq: $date, date_leq: $date }) {
                dimensions { databaseId }
                sum { rowsRead rowsWritten }
              }
              d1StorageAdaptiveGroups(limit: 10000, filter: { date_geq: $date, date_leq: $date }) {
                dimensions { databaseId }
                max { databaseSizeBytes }
              }
              queueMessageOperationsAdaptiveGroups(limit: 10000, filter: { date_geq: $date, date_leq: $date }) {
                dimensions { queueId }
                sum { billableOperations }
              }
            }
          }
        }
        GRAPHQL;

        $response = Http::withToken($this->apiToken)->post(self::BASE.'/graphql', [
            'query' => $query,
            'variables' => ['accountTag' => $this->accountId, 'date' => $date->toDateString()],
        ]);
        $json = $response->json();
        if (! is_array($json) || ! empty($json['errors'])) {
            throw new RuntimeException('Cloudflare D1/Queues GraphQL request failed: '.Str::limit(json_encode($json['errors'] ?? $response->body()) ?: '', 500));
        }

        $account = (array) data_get($json, 'data.viewer.accounts.0', []);
        $d1 = [];
        foreach ((array) ($account['d1AnalyticsAdaptiveGroups'] ?? []) as $group) {
            $id = (string) data_get($group, 'dimensions.databaseId', '');
            $row = $d1[$id] ?? ['rows_read' => 0, 'rows_written' => 0, 'storage_bytes' => 0];
            $row['rows_read'] += (int) data_get($group, 'sum.rowsRead', 0);
            $row['rows_written'] += (int) data_get($group, 'sum.rowsWritten', 0);
            $d1[$id] = $row;
        }
        foreach ((array) ($account['d1StorageAdaptiveGroups'] ?? []) as $group) {
            $id = (string) data_get($group, 'dimensions.databaseId', '');
            $row = $d1[$id] ?? ['rows_read' => 0, 'rows_written' => 0, 'storage_bytes' => 0];
            $row['storage_bytes'] = max($row['storage_bytes'], (int) data_get($group, 'max.databaseSizeBytes', 0));
            $d1[$id] = $row;
        }
        $queues = [];
        foreach ((array) ($account['queueMessageOperationsAdaptiveGroups'] ?? []) as $group) {
            $id = (string) data_get($group, 'dimensions.queueId', '');
            $queues[$id] = ($queues[$id] ?? 0) + (int) data_get($group, 'sum.billableOperations', 0);
        }

        return ['d1' => $d1, 'queues' => $queues];
    }

    /**
     * Recent Workers Logs events for one script (Workers Observability).
     * The events payload isn't fully documented, so fields are read
     * defensively.
     *
     * @return list<array{at: ?string, level: string, message: string}>
     */
    public function workerLogs(string $scriptName, int $minutes = 15, int $limit = 200): array
    {
        $payload = $this->decode(Http::withToken($this->apiToken)->post(self::BASE.'/accounts/'.$this->accountId.'/workers/observability/telemetry/query', [
            'queryId' => 'dply-logs-'.$scriptName,
            'view' => 'events',
            'limit' => $limit,
            'timeframe' => ['from' => now()->subMinutes($minutes)->getTimestampMs(), 'to' => now()->getTimestampMs()],
            'parameters' => ['filters' => [['key' => '$metadata.service', 'operation' => 'eq', 'type' => 'string', 'value' => $scriptName]]],
        ]));

        $events = data_get($payload, 'events.events', data_get($payload, 'events', []));
        $out = [];
        foreach ((array) $events as $event) {
            $message = data_get($event, '$metadata.message', data_get($event, 'source.message', data_get($event, 'message')));
            $timestamp = data_get($event, 'timestamp', data_get($event, '$metadata.timestamp'));
            $out[] = [
                'at' => is_numeric($timestamp) ? Carbon::createFromTimestampMs((int) $timestamp)->toIso8601String() : (is_string($timestamp) ? $timestamp : null),
                'level' => (string) data_get($event, '$metadata.level', data_get($event, 'source.level', 'log')),
                'message' => is_string($message) ? $message : (string) json_encode($message ?? data_get($event, 'source')),
            ];
        }

        return $out;
    }

    /**
     * Container applications on the account (Containers Read).
     *
     * @return list<array{id: string, name: string}>
     */
    public function listContainerApplications(): array
    {
        $rows = $this->decode(Http::withToken($this->apiToken)->get(self::BASE.'/accounts/'.$this->accountId.'/containers/applications'));

        return array_values(array_map(
            static fn (array $app): array => ['id' => (string) ($app['id'] ?? ''), 'name' => (string) ($app['name'] ?? '')],
            array_filter($rows, 'is_array'),
        ));
    }

    /**
     * Full state for one container application.
     *
     * The list endpoint returns only id/name, so rollout progress — `health`
     * (active/healthy/failed/starting counts), `version`, `configuration` — is
     * only visible here. Without it a deploy looks "done" the moment wrangler
     * returns, which says nothing about whether the container came up.
     *
     * @return array<string, mixed>
     */
    public function containerApplication(string $applicationId): array
    {
        return $this->decode(
            Http::withToken($this->apiToken)
                ->get(self::BASE.'/accounts/'.$this->accountId.'/containers/applications/'.$applicationId),
        );
    }

    /**
     * Rollouts for one application, newest first.
     *
     * `status` is `completed` once the new image is fully in place.
     * `progress.version_distribution.target_version_percentage` is 0 while
     * the dashboard still says "Rollout in progress". Instance health can
     * read idle at that point.
     *
     * @return list<array<string, mixed>>
     */
    public function containerRollouts(string $applicationId): array
    {
        $rows = $this->decode(
            Http::withToken($this->apiToken)
                ->get(self::BASE.'/accounts/'.$this->accountId.'/containers/applications/'.$applicationId.'/rollouts'),
        );

        return array_values(array_filter($rows, 'is_array'));
    }

    public function deleteContainerApplication(string $applicationId): void
    {
        $response = Http::withToken($this->apiToken)->delete(self::BASE.'/accounts/'.$this->accountId.'/containers/applications/'.$applicationId);
        if ($response->status() !== 404) {
            $this->decode($response);
        }
    }

    /**
     * One UTC day of container usage per application, from
     * containersUsageAdaptiveGroups — the resources Cloudflare bills
     * (container plus its micro VM).
     *
     * @return array<string, array{cpu_seconds: float, memory_gib_seconds: float, disk_gb_seconds: float, tx_bytes: int}>
     */
    public function containerUsageForDate(CarbonInterface $date): array
    {
        $query = <<<'GRAPHQL'
        query ContainerUsage($accountTag: string!, $date: Date!) {
          viewer {
            accounts(filter: { accountTag: $accountTag }) {
              containersUsageAdaptiveGroups(limit: 10000, filter: { date_geq: $date, date_leq: $date }) {
                dimensions { applicationId }
                sum { cpuTimeSec allocatedMemory allocatedDisk txBytes }
              }
            }
          }
        }
        GRAPHQL;

        $response = Http::withToken($this->apiToken)->post(self::BASE.'/graphql', [
            'query' => $query,
            'variables' => ['accountTag' => $this->accountId, 'date' => $date->toDateString()],
        ]);
        $json = $response->json();
        if (! is_array($json) || ! empty($json['errors'])) {
            throw new RuntimeException('Cloudflare containers GraphQL request failed: '.Str::limit(json_encode($json['errors'] ?? $response->body()) ?: '', 500));
        }

        $out = [];
        foreach ((array) data_get($json, 'data.viewer.accounts.0.containersUsageAdaptiveGroups', []) as $group) {
            $appId = (string) data_get($group, 'dimensions.applicationId', '');
            if ($appId === '') {
                continue;
            }
            $row = $out[$appId] ?? ['cpu_seconds' => 0.0, 'memory_gib_seconds' => 0.0, 'disk_gb_seconds' => 0.0, 'tx_bytes' => 0];
            $row['cpu_seconds'] += (float) data_get($group, 'sum.cpuTimeSec', 0);
            $row['memory_gib_seconds'] += (float) data_get($group, 'sum.allocatedMemory', 0) / 1024 ** 3;
            $row['disk_gb_seconds'] += (float) data_get($group, 'sum.allocatedDisk', 0) / 1000 ** 3;
            $row['tx_bytes'] += (int) data_get($group, 'sum.txBytes', 0);
            $out[$appId] = $row;
        }

        return $out;
    }

    /**
     * Load Balancing monitor (Account.Load Balancing: Monitors and Pools Edit).
     * PUT when an id is known, POST when not — or when the stored id 404s
     * because someone deleted it in the dashboard.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function upsertLbMonitor(?string $monitorId, array $payload): array
    {
        return $this->upsertLb('/accounts/'.$this->accountId.'/load_balancers/monitors', $monitorId, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function upsertLbPool(?string $poolId, array $payload): array
    {
        return $this->upsertLb('/accounts/'.$this->accountId.'/load_balancers/pools', $poolId, $payload);
    }

    /**
     * Zone load balancer (Zone.Load Balancers Edit on the platform zone).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function upsertLoadBalancer(string $zoneId, ?string $loadBalancerId, array $payload): array
    {
        return $this->upsertLb('/zones/'.$zoneId.'/load_balancers', $loadBalancerId, $payload);
    }

    public function deleteLoadBalancer(string $zoneId, string $loadBalancerId): void
    {
        $this->deleteLb('/zones/'.$zoneId.'/load_balancers/'.$loadBalancerId);
    }

    public function deleteLbPool(string $poolId): void
    {
        $this->deleteLb('/accounts/'.$this->accountId.'/load_balancers/pools/'.$poolId);
    }

    public function deleteLbMonitor(string $monitorId): void
    {
        $this->deleteLb('/accounts/'.$this->accountId.'/load_balancers/monitors/'.$monitorId);
    }

    /**
     * Per-PoP origin health for a pool.
     *
     * @return array<string, mixed>
     */
    public function lbPoolHealth(string $poolId): array
    {
        return $this->decode(
            Http::withToken($this->apiToken)->get(self::BASE.'/accounts/'.$this->accountId.'/load_balancers/pools/'.$poolId.'/health'),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function upsertLb(string $collectionPath, ?string $id, array $payload): array
    {
        if ($id !== null && $id !== '') {
            $response = Http::withToken($this->apiToken)->put(self::BASE.$collectionPath.'/'.$id, $payload);
            if ($response->status() !== 404) {
                return $this->decode($response);
            }
        }

        return $this->decode(Http::withToken($this->apiToken)->post(self::BASE.$collectionPath, $payload));
    }

    /** Already-gone (404) counts as deleted. */
    private function deleteLb(string $path): void
    {
        $response = Http::withToken($this->apiToken)->delete(self::BASE.$path);
        if ($response->status() !== 404) {
            $this->decode($response);
        }
    }

    /**
     * Create a Turnstile widget (Account.Turnstile Edit).
     *
     * @param  list<string>  $domains
     * @return array{id: string, sitekey: string, secret: string, mode: string, name: string, domains: list<string>}
     */
    public function createTurnstileWidget(string $name, array $domains, string $mode = 'managed'): array
    {
        $accountId = trim($this->accountId);
        if ($accountId === '') {
            throw new RuntimeException('Cloudflare account id is not configured.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Turnstile widget name is required.');
        }

        $domains = array_values(array_unique(array_filter(array_map(
            static fn (mixed $domain): string => strtolower(trim((string) $domain)),
            $domains,
        ), static fn (string $domain): bool => $domain !== '')));

        if ($domains === []) {
            throw new RuntimeException('At least one domain is required for a Turnstile widget.');
        }

        $mode = in_array($mode, ['managed', 'non-interactive', 'invisible'], true)
            ? $mode
            : 'managed';

        $result = $this->decode(
            Http::withToken($this->apiToken)
                ->post(self::BASE.'/accounts/'.$accountId.'/challenges/widgets', [
                    'name' => $name,
                    'domains' => $domains,
                    'mode' => $mode,
                ]),
        );

        $sitekey = trim((string) ($result['sitekey'] ?? ''));
        $secret = trim((string) ($result['secret'] ?? $result['client_token'] ?? ''));
        if ($sitekey === '' || $secret === '') {
            throw new RuntimeException('Cloudflare created a Turnstile widget without sitekey/secret.');
        }

        $resultDomains = is_array($result['domains'] ?? null) ? $result['domains'] : $domains;

        return [
            'id' => (string) ($result['sitekey'] ?? $result['id'] ?? ''),
            'sitekey' => $sitekey,
            'secret' => $secret,
            'mode' => (string) ($result['mode'] ?? $mode),
            'name' => (string) ($result['name'] ?? $name),
            'domains' => array_values(array_filter(array_map('strval', $resultDomains))),
        ];
    }

    /**
     * Cloudflare returns a numeric list in `result` for list endpoints and a
     * map for single-object ones, so the key type is deliberately open.
     *
     * @return array<array-key, mixed>
     */
    private function decode(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Cloudflare API returned a non-JSON response.');
        }

        if (($json['success'] ?? false) !== true) {
            $errors = $json['errors'] ?? [];
            $message = is_array($errors) && isset($errors[0]['message'])
                ? (string) $errors[0]['message']
                : 'Cloudflare API request failed.';

            throw new RuntimeException($message);
        }

        $result = $json['result'] ?? [];

        return is_array($result) ? $result : ['value' => $result];
    }
}
