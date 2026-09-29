<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace\Concerns\Resources;

use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeMeter;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Renderless;

/**
 * Resources sheet: Vector search (Vectorize) and Workflow. Mixed into
 * {@see Resources}; the open connection
 * is $this->openResourceConnection() (set by openResource()).
 */
trait ManagesVectorsResource
{
    /** Add a resource → Vector search: the new index's size and metric. */
    public int $vectorsDimensions = 768;

    public string $vectorsMetric = 'cosine';

    /** @var array{dimensions: int, metric: string, count: int}|null */
    public ?array $vectorsInfo = null;

    public ?string $vectorsError = null;

    /** Query demo: a JSON array of numbers. */
    public string $vectorsQuery = '';

    public int $vectorsTopK = 5;

    /** @var list<array{id: string, score: float, metadata: string}>|null */
    public ?array $vectorsMatches = null;

    /** @return array{dimensions: int, metric: string} */
    protected function vectorsProvisionOptions(): array
    {
        return ['dimensions' => $this->vectorsDimensions, 'metric' => $this->vectorsMetric];
    }

    /** Overview numbers for the open index. Only indexes this organization created are read. */
    public function loadVectors(): void
    {
        $this->authorize('view', $this->site);
        $this->reset('vectorsInfo', 'vectorsError', 'vectorsMatches');
        $index = $this->vectorsIndex();
        if ($index === null) {
            return;
        }
        try {
            $row = EdgeCloudflareClient::fromConfig()->getVectorizeIndex($index);
        } catch (\Throwable $e) {
            $this->vectorsError = $e->getMessage();

            return;
        }
        $this->vectorsInfo = [
            'dimensions' => (int) ($row['config']['dimensions'] ?? $row['info']['dimensions'] ?? 0),
            'metric' => (string) ($row['config']['metric'] ?? ''),
            'count' => (int) ($row['info']['vectorCount'] ?? 0),
        ];
    }

    public function runVectorsQuery(): void
    {
        $this->authorize('view', $this->site);
        $this->resetErrorBag('vectorsQuery');
        $this->vectorsMatches = null;
        $index = $this->vectorsIndex();
        if ($index === null) {
            return;
        }
        $vector = json_decode($this->vectorsQuery, true);
        if (! is_array($vector) || $vector === [] || ! array_is_list($vector) || count($vector) > 1536
            || array_filter($vector, static fn ($v): bool => ! is_int($v) && ! is_float($v)) !== []) {
            $this->addError('vectorsQuery', __('Paste a JSON array of numbers, like [0.1, 0.2, 0.3].'));

            return;
        }
        $dimensions = (int) ($this->vectorsInfo['dimensions'] ?? 0);
        if ($dimensions > 0 && count($vector) !== $dimensions) {
            $this->addError('vectorsQuery', __('This index takes :n numbers. That vector has :count.', ['n' => $dimensions, 'count' => count($vector)]));

            return;
        }
        // Runs on the platform account, so it counts toward the org's cap like the app's queries.
        if (($refused = EdgeMeter::refusal($this->site->organization, 'vectors')) !== null) {
            $this->addError('vectorsQuery', $refused['message']);

            return;
        }
        // Vectorize returns at most 50 matches with metadata.
        $this->vectorsTopK = max(1, min(50, $this->vectorsTopK));
        try {
            $result = EdgeCloudflareClient::fromConfig()->queryVectorize($index, $vector, $this->vectorsTopK);
        } catch (\Throwable $e) {
            $this->addError('vectorsQuery', $e->getMessage());

            return;
        }
        EdgeMeter::record($this->site, ['vector_query_dims' => count($vector)]);
        $this->vectorsMatches = array_map(static fn (array $match): array => [
            'id' => (string) ($match['id'] ?? ''),
            'score' => (float) ($match['score'] ?? 0),
            'metadata' => isset($match['metadata']) ? (string) json_encode($match['metadata'], JSON_UNESCAPED_SLASHES) : '',
        ], array_values(array_filter((array) ($result['matches'] ?? []), 'is_array')));
    }

    /** Workers AI embedding model for each index size the builder offers (1536 has none). */
    public const VECTORS_DEMO_MODELS = [
        384 => '@cf/baai/bge-small-en-v1.5',
        768 => '@cf/baai/bge-base-en-v1.5',
        1024 => '@cf/baai/bge-large-en-v1.5',
    ];

    /** Demo documents: ids carry this prefix so they are easy to find and remove. */
    public const VECTORS_DEMO_PREFIX = 'dply-demo-';

    public const VECTORS_DEMO_DOCS = [
        'Reset your password from the sign-in page with Forgot password.',
        'Invoices are emailed on the first of each month and listed under Billing.',
        'Apps sleep after a few idle minutes and wake up on the next request.',
        'Add a custom domain, then point a CNAME record at your app.',
        'Queue workers run background jobs such as sending email.',
        'Deleting an app removes its data after 30 days.',
    ];

    /** After Add a resource → Vector search: open the new index's sheet straight away. */
    protected function openNewVectorsIndex(string $host, bool $created): void
    {
        $this->resourceHost = $host;
        $this->reset('vectorsError', 'vectorsMatches');
        // A new index can take a moment to answer /info; it is empty anyway.
        $created
            ? $this->vectorsInfo = ['dimensions' => $this->vectorsDimensions, 'metric' => $this->vectorsMetric, 'count' => 0]
            : $this->loadVectors();
        $this->renderIsland('resources-vectors');
        $this->dispatch('open-modal', 'resources-vectors');
    }

    /**
     * Try it: store the demo documents' embeddings in the open index. Opt-in,
     * because they show up in the app's own searches until removed.
     *
     * @return array{ok: bool, error?: string, count?: int}
     */
    #[Renderless]
    public function seedVectorsDemo(): array
    {
        $this->authorize('update', $this->site);
        $prepared = $this->vectorsDemoPrepare();
        if (isset($prepared['error'])) {
            return ['ok' => false, 'error' => $prepared['error']];
        }
        try {
            $vectors = $this->vectorsDemoEmbed($prepared['model'], self::VECTORS_DEMO_DOCS);
            $ndjson = collect($vectors)->map(fn (array $values, int $i): string => (string) json_encode([
                'id' => self::VECTORS_DEMO_PREFIX.($i + 1),
                'values' => $values,
                'metadata' => ['text' => self::VECTORS_DEMO_DOCS[$i], 'dply_demo' => true],
            ]))->implode("\n");
            EdgeCloudflareClient::fromConfig()->upsertVectors($prepared['index'], $ndjson);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'count' => count(self::VECTORS_DEMO_DOCS)];
    }

    /** @return array{ok: bool, error?: string} */
    #[Renderless]
    public function removeVectorsDemo(): array
    {
        $this->authorize('update', $this->site);
        $index = $this->vectorsIndex();
        if ($index === null) {
            return ['ok' => false, 'error' => $this->vectorsError ?? __('No index is open.')];
        }
        try {
            EdgeCloudflareClient::fromConfig()->deleteVectorsByIds($index, array_map(fn (int $i): string => self::VECTORS_DEMO_PREFIX.$i, range(1, count(self::VECTORS_DEMO_DOCS))));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true];
    }

    /**
     * Try it: embed a question, then search the index. A container app's
     * search goes through the live app (the site Worker's /_dply/vectors,
     * the same path as its calls to the index host), so it proves the app
     * is wired up. A Worker app has no such route: the index is searched
     * from here.
     *
     * @return array{ok: bool, error?: string, via?: string, ms?: int, model?: string, dims?: int, matches?: list<array{id: string, score: float, text: ?string}>}
     */
    #[Renderless]
    public function runVectorsDemo(string $question): array
    {
        $this->authorize('update', $this->site);
        $question = trim($question);
        if ($question === '' || mb_strlen($question) > 500) {
            return ['ok' => false, 'error' => __('Ask a question under 500 characters.')];
        }
        $prepared = $this->vectorsDemoPrepare();
        if (isset($prepared['error'])) {
            return ['ok' => false, 'error' => $prepared['error']];
        }
        try {
            $vector = $this->vectorsDemoEmbed($prepared['model'], [$question])[0] ?? [];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $started = hrtime(true);
        $container = ($this->site->edgeMeta()['runtime_mode'] ?? '') === 'container';
        try {
            if ($container) {
                $url = $this->site->edgeLiveUrl();
                if (! is_string($url) || $url === '') {
                    return ['ok' => false, 'error' => __('This app has no live URL yet. Deploy it first.')];
                }
                // Metered in the site Worker, like the app's own queries.
                $response = Http::timeout(30)
                    ->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($this->site)])
                    ->post(rtrim($url, '/').'/_dply/vectors', ['host' => $prepared['host'], 'vector' => $vector, 'topK' => 5]);
                if ($response->status() === 404) {
                    return ['ok' => false, 'error' => __('The live app does not have this demo yet. Redeploy it with this index attached.')];
                }
                if (! $response->successful()) {
                    return ['ok' => false, 'error' => (string) ($response->json('error') ?: __('The app answered HTTP :status.', ['status' => $response->status()]))];
                }
                $result = (array) $response->json();
            } else {
                $result = EdgeCloudflareClient::fromConfig()->queryVectorize($prepared['index'], $vector, 5);
                EdgeMeter::record($this->site, ['vector_query_dims' => count($vector)]);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'via' => $container ? 'app' : 'dashboard',
            'ms' => (int) ((hrtime(true) - $started) / 1e6),
            'model' => $prepared['model'],
            'dims' => count($vector),
            'matches' => array_map(function (array $match): array {
                $id = (string) ($match['id'] ?? '');
                $n = str_starts_with($id, self::VECTORS_DEMO_PREFIX) ? (int) substr($id, strlen(self::VECTORS_DEMO_PREFIX)) : 0;

                return [
                    'id' => $id,
                    'score' => round((float) ($match['score'] ?? 0), 4),
                    'text' => self::VECTORS_DEMO_DOCS[$n - 1] ?? (is_string($match['metadata']['text'] ?? null) ? $match['metadata']['text'] : null),
                ];
            }, array_values(array_filter((array) ($result['matches'] ?? []), 'is_array'))),
        ];
    }

    /** @return array{index: string, host: string, model: string}|array{error: string} */
    private function vectorsDemoPrepare(): array
    {
        $index = $this->vectorsIndex();
        $connection = $this->openResourceConnection();
        if ($index === null || $connection === null) {
            return ['error' => $this->vectorsError ?? __('No index is open.')];
        }
        if ($connection['asleep']) {
            return ['error' => __('This index is asleep. Wake it first.')];
        }
        if ($this->vectorsInfo === null) {
            $this->loadVectors();
        }
        $model = self::VECTORS_DEMO_MODELS[(int) ($this->vectorsInfo['dimensions'] ?? 0)] ?? null;
        if ($model === null) {
            return ['error' => __('No built-in embedding model makes vectors of this size. Use Search with a vector instead.')];
        }
        // The demo runs on the platform account, so keep it to a few calls.
        if (! RateLimiter::attempt('edge-vectors-demo:'.$this->site->id, 10, static fn (): bool => true, 60)) {
            return ['error' => __('Too many tries. Wait a minute and run it again.')];
        }
        foreach (['ai', 'vectors'] as $service) {
            if (($refused = EdgeMeter::refusal($this->site->organization, $service)) !== null) {
                return ['error' => $refused['message']];
            }
        }

        return ['index' => $index, 'host' => $connection['host'], 'model' => $model];
    }

    /**
     * Embeddings from Workers AI on the platform account, billed as AI usage.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    private function vectorsDemoEmbed(string $model, array $texts): array
    {
        $result = EdgeCloudflareClient::fromConfig()->runAi($model, ['text' => $texts]);
        $tokens = (int) ($result['usage']['prompt_tokens'] ?? ceil(mb_strlen(implode(' ', $texts)) / 4));
        EdgeMeter::record($this->site, ['ai_neurons' => EdgeMeter::neurons($model, $tokens, 0)]);
        $data = $result['data'] ?? null;
        if (! is_array($data) || count($data) !== count($texts)) {
            throw new \RuntimeException(__('The embedding model did not answer.'));
        }

        return array_values($data);
    }

    /**
     * The open connection's index name, or null (with vectorsError set) when
     * it is not an index this organization created, such as an older row
     * with a typed name.
     */
    private function vectorsIndex(): ?string
    {
        $connection = $this->openResourceConnection();
        if ($connection === null || $connection['kind'] !== 'vectors' || $this->site->organization === null) {
            return null;
        }
        if (! EdgeContainerConnections::owns('vectors', $connection['target'], $this->site->organization)) {
            $this->vectorsError = __('This index was not created by this organization, so it is not read from here. Delete this resource and create one to see it.');

            return null;
        }

        return $connection['target'];
    }
}
