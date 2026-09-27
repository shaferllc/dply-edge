<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace\Concerns\Resources;

use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeRealtimeUsage;
use App\Modules\Billing\Services\EdgeRealtimeCost;

/**
 * Resources: the Realtime card's cost this month. Mixed into {@see Resources},
 * which calls {kind}CostCents($connection) for each card. Reads collected
 * usage only (no relay call on render).
 */
trait ManagesRealtimeBilling
{
    /**
     * ponytail: the org's monthly allowance is applied to this one app, so
     * with several Realtime apps each card can read low; the invoice
     * (EdgeRealtimeCost::forOrganization) applies it once.
     *
     * @param  array{target: string}  $connection
     */
    public function realtimeCostCents(array $connection): ?int
    {
        if ($connection['target'] === '') {
            return null;
        }
        $usage = EdgeRealtimeUsage::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('realtime_app_id', $connection['target'])
            ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->selectRaw('COALESCE(SUM(connection_seconds), 0) AS seconds, COALESCE(SUM(messages), 0) AS messages')
            ->first();

        $cost = app(EdgeRealtimeCost::class);

        return $cost->cents((int) ($usage->seconds ?? 0), (int) ($usage->messages ?? 0), $cost->allowance($this->site->organization));
    }
}
