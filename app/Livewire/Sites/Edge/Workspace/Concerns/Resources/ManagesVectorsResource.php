<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace\Concerns\Resources;

use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeMeter;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;

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
