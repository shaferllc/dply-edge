<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgePostgresUsage;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeAppDatabase;
use Carbon\CarbonInterface;

/**
 * MySQL is a cluster that stays on, so each one is a flat monthly charge.
 * Postgres is compute hours plus storage, including while the app sleeps.
 *
 * Called from OrganizationBillingStateComputer and StarterUsageBudget.
 * Reads edge meta and edge_postgres_usage.
 * User instruction: "ok lets move ahead with imp,emeting neon and postgres first".
 */
class EdgeAppDatabaseCost
{
    /**
     * @return array{mysql: int, postgres: int, cents: int}
     */
    public function forOrganization(Organization $organization, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $from ??= now()->startOfMonth();
        $to ??= now()->endOfMonth();
        $mysql = 0;
        $cents = 0;
        Site::query()
            ->where('organization_id', $organization->id)
            ->where('meta->edge->runtime_mode', 'container')
            ->orderBy('id')
            ->each(function (Site $site) use (&$mysql, &$cents): void {
                $database = $site->edgeMeta()['database'] ?? null;
                if (! is_array($database) || ($database['engine'] ?? '') !== 'mysql') {
                    return;
                }
                if ((string) ($database['remote_id'] ?? '') === '') {
                    return;
                }
                $mysql++;
                $cents += EdgeAppDatabase::mysqlCents((string) ($database['size'] ?? ''));
            });

        $postgres = $this->postgresCents($organization, $from, $to);

        return ['mysql' => $mysql, 'postgres' => $postgres['projects'], 'cents' => $cents + $postgres['cents']];
    }

    public function hourly(float $cu): string
    {
        $factor = (100 + $this->markup()) / 100;
        $hour = $cu * (float) config('dply.edge.usage_billing.postgres_compute_millicents_per_cu_hour', 0) / 100_000 * $factor;

        return number_format($hour, 3);
    }

    public function daily(float $cu): string
    {
        return number_format((float) $this->hourly($cu) * 24, 2);
    }

    public function monthly(float $cu): string
    {
        return number_format((float) $this->hourly($cu) * 720, 2);
    }

    /**
     * Customer-facing rates after markup. Hour is the smallest size (0.25 CU).
     *
     * @return array{hour: string, gigabyte: string}
     */
    public function presentation(): array
    {
        $factor = (100 + $this->markup()) / 100;
        $gigabyte = (float) config('dply.edge.usage_billing.postgres_storage_millicents_per_gb_month', 0) / 100_000 * $factor;

        return [
            'hour' => $this->hourly(0.25),
            'gigabyte' => number_format($gigabyte, 2),
        ];
    }

    /**
     * @return array{projects: int, cents: int}
     */
    private function postgresCents(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = EdgePostgresUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();
        $byProject = [];
        foreach ($rows as $row) {
            $project = $byProject[$row->project_id] ?? [
                'compute_unit_seconds' => 0,
                'storage_byte_hours' => 0,
                'history_byte_hours' => 0,
                'snapshot_byte_hours' => 0,
                'transfer_bytes' => 0,
            ];
            $project['compute_unit_seconds'] += (int) $row->compute_unit_seconds;
            $project['storage_byte_hours'] += (int) $row->storage_byte_hours;
            $project['history_byte_hours'] += (int) $row->history_byte_hours;
            $project['snapshot_byte_hours'] += (int) $row->snapshot_byte_hours;
            $project['transfer_bytes'] += (int) $row->transfer_bytes;
            $byProject[$row->project_id] = $project;
        }

        $hours = max(1, $from->daysInMonth * 24);
        $cents = 0;
        foreach ($byProject as $project) {
            $cents += $this->projectCents($project, $hours);
        }

        return ['projects' => count($byProject), 'cents' => $cents];
    }

    /**
     * @param  array{compute_unit_seconds: int, storage_byte_hours: int, history_byte_hours: int, snapshot_byte_hours: int, transfer_bytes: int}  $project
     */
    public function projectCents(array $project, int $hoursInMonth): int
    {
        $rate = static fn (string $key): float => (float) config('dply.edge.usage_billing.'.$key, 0);
        $gbMonth = fn (int $byteHours): float => $byteHours / (1024 ** 3) / $hoursInMonth;
        $included = (int) $rate('postgres_transfer_included_bytes');
        $transfer = max(0, $project['transfer_bytes'] - $included);
        $millicents = $project['compute_unit_seconds'] / 3600 * $rate('postgres_compute_millicents_per_cu_hour')
            + $gbMonth($project['storage_byte_hours']) * $rate('postgres_storage_millicents_per_gb_month')
            + $gbMonth($project['history_byte_hours']) * $rate('postgres_history_millicents_per_gb_month')
            + $gbMonth($project['snapshot_byte_hours']) * $rate('postgres_snapshot_millicents_per_gb_month')
            + $transfer / (1024 ** 3) * $rate('postgres_transfer_millicents_per_gb');

        return (int) ceil($millicents * (100 + $this->markup()) / 100 / 1000);
    }

    private function markup(): int
    {
        return max(0, (int) config('dply.edge.usage_billing.markup_percent', 0));
    }
}
