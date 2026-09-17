<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

/**
 * Converts measured Edge usage into customer-facing cents, applying per-site
 * included allowances and configurable unit rates (rates embed platform margin).
 */
class EdgeUsageCostCalculator
{
    public function isEnabled(): bool
    {
        return (bool) config('dply.edge.usage_billing.enabled', false);
    }

    /**
     * @param  array<string, mixed>|null  $tier  A `subscription.standard.tiers.*` record: its
     *                                           `requests` / `egress_gb` replace the per-site
     *                                           request/egress allowances (org-wide, null = unlimited).
     * @return array{
     *     subtotal_cents: int,
     *     billable_requests: int,
     *     billable_bytes_egress: int,
     *     billable_r2_storage_bytes: int,
     *     included_requests: int,
     *     included_bytes_egress: int,
     *     included_r2_storage_bytes: int,
     * }
     */
    public function estimate(EdgeUsageTotals $usage, int $edgeSiteCount, ?array $tier = null): array
    {
        if (! $this->isEnabled() || $edgeSiteCount <= 0) {
            return $this->emptyEstimate();
        }

        $includedRequests = $edgeSiteCount * max(0, (int) config('dply.edge.usage_billing.included_requests_per_site', 0));
        $includedEgress = $edgeSiteCount * $this->includedEgressBytesPerSite();
        if ($tier !== null) {
            $includedRequests = $tier['requests'] === null ? PHP_INT_MAX : (int) $tier['requests'];
            $includedEgress = $tier['egress_gb'] === null ? PHP_INT_MAX : (int) $tier['egress_gb'] * 1024 ** 3;
        }
        $includedStorage = $edgeSiteCount * $this->includedR2StorageBytesPerSite();
        $includedClassA = $edgeSiteCount * max(0, (int) config('dply.edge.usage_billing.included_r2_class_a_ops_per_site', 0));
        $includedClassB = $edgeSiteCount * max(0, (int) config('dply.edge.usage_billing.included_r2_class_b_ops_per_site', 0));

        $billableRequests = max(0, $usage->requests - $includedRequests);
        $billableEgress = max(0, $usage->bytesEgress - $includedEgress);
        $billableStorage = max(0, $usage->r2StorageBytes - $includedStorage);
        $billableClassA = max(0, $usage->r2ClassAOps - $includedClassA);
        $billableClassB = max(0, $usage->r2ClassBOps - $includedClassB);

        $requestCents = $this->requestsCents($billableRequests);
        $egressCents = $this->egressCents($billableEgress);
        $storageCents = $this->storageCents($billableStorage);
        $r2OpsCents = $this->r2OpsCents($billableClassA, $billableClassB);

        $subtotal = $requestCents + $egressCents + $storageCents + $r2OpsCents;
        $subtotal = $this->applyMarkup($subtotal);

        return [
            'subtotal_cents' => max(0, $subtotal),
            'billable_requests' => $billableRequests,
            'billable_bytes_egress' => $billableEgress,
            'billable_r2_storage_bytes' => $billableStorage,
            'billable_r2_class_a_ops' => $billableClassA,
            'billable_r2_class_b_ops' => $billableClassB,
            'included_requests' => $includedRequests,
            'included_bytes_egress' => $includedEgress,
            'included_r2_storage_bytes' => $includedStorage,
            'included_r2_class_a_ops' => $includedClassA,
            'included_r2_class_b_ops' => $includedClassB,
        ];
    }

    private function requestsCents(int $billableRequests): int
    {
        if ($billableRequests <= 0) {
            return 0;
        }

        $rate = max(0, (int) config('dply.edge.usage_billing.requests_cents_per_million', 0));

        return (int) ceil($billableRequests / 1_000_000 * $rate);
    }

    private function egressCents(int $billableBytes): int
    {
        if ($billableBytes <= 0) {
            return 0;
        }

        $rate = max(0, (int) config('dply.edge.usage_billing.egress_cents_per_gb', 0));
        $gigabytes = $billableBytes / (1024 ** 3);

        return (int) ceil($gigabytes * $rate);
    }

    private function storageCents(int $billableBytes): int
    {
        if ($billableBytes <= 0) {
            return 0;
        }

        $rate = max(0, (int) config('dply.edge.usage_billing.r2_storage_cents_per_gb_month', 0));
        $gigabytes = $billableBytes / (1024 ** 3);

        return (int) ceil($gigabytes * $rate);
    }

    private function r2OpsCents(int $classA, int $classB): int
    {
        $classARate = max(0, (int) config('dply.edge.usage_billing.r2_class_a_cents_per_million', 0));
        $classBRate = max(0, (int) config('dply.edge.usage_billing.r2_class_b_cents_per_million', 0));

        return (int) ceil($classA / 1_000_000 * $classARate)
            + (int) ceil($classB / 1_000_000 * $classBRate);
    }

    private function applyMarkup(int $subtotalCents): int
    {
        if ($subtotalCents <= 0) {
            return 0;
        }

        $markup = max(0, (int) config('dply.edge.usage_billing.markup_percent', 0));

        return (int) ceil($subtotalCents * (100 + $markup) / 100);
    }

    private function includedEgressBytesPerSite(): int
    {
        $gigabytes = max(0, (int) config('dply.edge.usage_billing.included_egress_gb_per_site', 0));

        return $gigabytes * 1024 ** 3;
    }

    private function includedR2StorageBytesPerSite(): int
    {
        $gigabytes = max(0, (int) config('dply.edge.usage_billing.included_r2_storage_gb_per_site', 0));

        return $gigabytes * 1024 ** 3;
    }

    /**
     * @return array{
     *     subtotal_cents: int,
     *     billable_requests: int,
     *     billable_bytes_egress: int,
     *     billable_r2_storage_bytes: int,
     *     included_requests: int,
     *     included_bytes_egress: int,
     *     included_r2_storage_bytes: int,
     * }
     */
    private function emptyEstimate(): array
    {
        return [
            'subtotal_cents' => 0,
            'billable_requests' => 0,
            'billable_bytes_egress' => 0,
            'billable_r2_storage_bytes' => 0,
            'included_requests' => 0,
            'included_bytes_egress' => 0,
            'included_r2_storage_bytes' => 0,
        ];
    }
}
