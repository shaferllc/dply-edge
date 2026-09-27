<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Support\UnitCosts;
use App\Modules\Billing\Support\UsagePrice;
use Illuminate\Console\Command;

/**
 * Real unit costs of dply's own infrastructure (estimates in dply.unit_costs)
 * next to our prices, and the fixed monthly bills. docs/pricing-review.md §9.
 *
 *   php artisan dply:billing:unit-costs
 */
class PrintUnitCostsCommand extends Command
{
    protected $signature = 'dply:billing:unit-costs';

    protected $description = 'Compare estimated real unit costs (dply.unit_costs) with our prices, and total the fixed monthly costs.';

    public function handle(): int
    {
        $money = static fn (float $usd): string => UsagePrice::dollars($usd * 100_000);

        $this->line('Unit costs vs price (estimates; markup = price / cost - 1, needs >= '.round(((float) config('dply.unit_costs.min_markup') - 1) * 100).'%)');
        $this->table(['Meter', 'Unit', 'Real cost', 'Price', 'Markup', 'OK', 'Basis'], array_map(static fn (array $r): array => [
            $r['meter'], $r['unit'], $money($r['cost']), $money($r['price']),
            is_finite($r['markup']) ? round($r['markup'] * 100).'%' : '—',
            $r['ok'] ? 'yes' : 'NO', $r['note'],
        ], UnitCosts::rows()));

        $fixed = UnitCosts::fixedMonthly();
        $this->line('Fixed monthly costs (estimates)');
        $this->table(['Line', 'Per month'], [
            ...array_map(static fn (string $line, float $usd): array => [$line, $money($usd)], array_keys($fixed), $fixed),
            ['Total', $money(array_sum($fixed))],
        ]);
        $this->line(sprintf('Stripe: %s%% + %d¢ per invoice (card + Billing).', config('dply.unit_costs.stripe.percent'), config('dply.unit_costs.stripe.fixed_cents')));

        return self::SUCCESS;
    }
}
