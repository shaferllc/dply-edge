<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

/**
 * Aggregated Edge delivery usage for an organization over a billing window.
 */
readonly class EdgeUsageTotals
{
    public function __construct(
        public int $requests = 0,
        public int $bytesEgress = 0,
        public int $r2StorageBytes = 0,
        public int $r2ClassAOps = 0,
        public int $r2ClassBOps = 0,
        public int $r2ObjectCount = 0,
    ) {}

    public function add(self $other): self
    {
        return new self(
            requests: $this->requests + $other->requests,
            bytesEgress: $this->bytesEgress + $other->bytesEgress,
            r2StorageBytes: max($this->r2StorageBytes, $other->r2StorageBytes),
            r2ClassAOps: $this->r2ClassAOps + $other->r2ClassAOps,
            r2ClassBOps: $this->r2ClassBOps + $other->r2ClassBOps,
            r2ObjectCount: max($this->r2ObjectCount, $other->r2ObjectCount),
        );
    }

    public function isEmpty(): bool
    {
        return $this->requests === 0
            && $this->bytesEgress === 0
            && $this->r2StorageBytes === 0
            && $this->r2ClassAOps === 0
            && $this->r2ClassBOps === 0;
    }

    public static function empty(): self
    {
        return new self;
    }
}
