<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Services\SubscriptionPlanResolver;
use App\Modules\Billing\Support\UsagePrice;
use Illuminate\Console\Command;

/**
 * Prints the pricing tables as Markdown, from the same config and helper
 * (UsagePrice) the invoice uses, so docs/site stays in sync with the bill:
 *
 *   php artisan dply:billing:price-table                 every table
 *   php artisan dply:billing:price-table plans           plans, seats, included usage
 *   php artisan dply:billing:price-table limits          non-price limits per plan
 *   php artisan dply:billing:price-table fair-use        the fair-use app limit
 *   php artisan dply:billing:price-table rates --group="Key-value" --group=Queues
 *   php artisan dply:billing:price-table sizes           the size ladder (app, database, Valkey)
 *   php artisan dply:billing:price-table sizes --product=valkey
 */
class PrintPriceTableCommand extends Command
{
    protected $signature = 'dply:billing:price-table
                            {section=all : plans, limits, fair-use, rates, sizes, or all}
                            {--group=* : Only these usage groups (rates)}
                            {--product= : app, database or valkey (sizes)}';

    protected $description = 'Print the pricing tables as Markdown for docs/site.';

    public function handle(): int
    {
        $section = (string) $this->argument('section');
        $tables = [
            'plans' => fn (): string => $this->plans(),
            'limits' => fn (): string => $this->limits(),
            'fair-use' => fn (): string => $this->fairUse(),
            'rates' => fn (): string => $this->rates((array) $this->option('group')),
            'sizes' => fn (): string => $this->sizes($this->option('product')),
        ];
        if ($section !== 'all' && ! isset($tables[$section])) {
            $this->error('Unknown section. Use one of: all, '.implode(', ', array_keys($tables)));

            return self::FAILURE;
        }

        foreach ($section === 'all' ? $tables : [$section => $tables[$section]] as $name => $table) {
            if ($section === 'all') {
                $this->line("<!-- {$name} -->");
            }
            $this->line($table());
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /** @return array<string, array<string, mixed>> */
    private function tiers(): array
    {
        return array_intersect_key((array) config('subscription.standard.tiers'), array_flip(SubscriptionPlanResolver::PAID_TIERS));
    }

    private function plans(): string
    {
        $rows = [];
        foreach ($this->tiers() as $tier) {
            $rows[] = [
                $tier['label'],
                $this->money((int) $tier['price_cents']).'/mo',
                (string) $tier['seats'],
                $tier['extra_seat_cents'] === null ? '—' : $this->money((int) $tier['extra_seat_cents']).'/mo each',
                $this->money((int) $tier['usage_credit_cents']).'/mo',
            ];
        }
        $rows[] = ['Enterprise', 'Contact us', 'Custom', 'Custom', 'Custom'];

        return $this->markdown(['Plan', 'Price', 'Seats', 'Extra seat', 'Included usage'], $rows);
    }

    private function limits(): string
    {
        $tiers = $this->tiers();
        $num = static fn (mixed $n): string => $n === null ? 'Unlimited' : number_format((int) $n);
        $limits = [
            'Sites' => fn (): string => 'Unlimited',
            'Concurrent builds' => fn (array $t): string => (string) $t['concurrent_builds'],
            'Build timeout' => fn (array $t): string => $t['build_timeout_minutes'].' min',
            'Custom domains (per organization)' => fn (array $t): string => $num($t['custom_domains']),
            'Container app instances' => fn (array $t): string => $t['app_instances'] === null ? 'Autoscaling' : $num($t['app_instances']).' per app',
            'Queue workers per app' => fn (array $t): string => $num($t['worker_instances']).($t['worker_autoscale'] ? ', autoscaling' : ''),
            'SQL databases (D1)' => fn (array $t): string => $num($t['databases']),
            'Queues' => fn (array $t): string => $num($t['queues']),
            'Realtime connections per app' => fn (array $t): string => $num($t['realtime_max_connections']),
            'Audit log' => fn (array $t): string => $t['audit_log'] ? 'Yes' : 'No',
        ];
        $rows = [];
        foreach ($limits as $label => $cell) {
            $rows[] = [$label, ...array_map($cell, array_values($tiers))];
        }

        return $this->markdown(['', ...array_column($tiers, 'label')], $rows);
    }

    private function fairUse(): string
    {
        $rows = [];
        foreach ($this->tiers() as $tier) {
            $rows[] = [$tier['label'], $tier['fair_use_apps'] === null ? 'None' : number_format((int) $tier['fair_use_apps'])];
        }
        $rows[] = ['Enterprise', 'None'];

        return $this->markdown(['Plan', 'Apps (previews don’t count)'], $rows);
    }

    /** @param  list<string>  $groups */
    private function rates(array $groups): string
    {
        $rows = [];
        foreach (UsagePrice::rates() as $rate) {
            if ($groups !== [] && ! in_array($rate['group'], $groups, true)) {
                continue;
            }
            $rows[] = $groups === [] || count($groups) > 1
                ? [$rate['group'], $rate['label'], $rate['price'], $rate['unit']]
                : [$rate['label'], $rate['price'], $rate['unit']];
        }

        return $groups === [] || count($groups) > 1
            ? $this->markdown(['Group', 'Meter', 'Price', 'Unit'], $rows)
            : $this->markdown(['Meter', 'Price', 'Unit'], $rows);
    }

    private function sizes(mixed $product): string
    {
        $sizes = UsagePrice::sizes();
        if (in_array($product, ['app', 'database', 'valkey'], true)) {
            $rows = [];
            foreach ($sizes as $size) {
                $cell = $size[$product];
                if ($cell === null) {
                    continue;
                }
                $row = [$size['label'], $cell['memory'], $cell['second'], $cell['hour']];
                if ($product === 'valkey') {
                    $row[] = $cell['cap'];
                    $row[] = $cell['sleeps'] ? 'Yes' : 'No (stays on)';
                }
                $rows[] = $row;
            }
            $head = ['Size', 'Memory', 'Per second awake', 'Per hour awake'];

            return $this->markdown($product === 'valkey' ? [...$head, 'Most per month', 'Sleeps'] : $head, $rows);
        }

        $rows = [];
        foreach ($sizes as $size) {
            $cell = static fn (?array $c): string => $c === null ? '—' : $c['second'].'/s ('.$c['memory'].')';
            $rows[] = [$size['label'], $cell($size['app']), $cell($size['database']), $cell($size['valkey'])];
        }

        return $this->markdown(['Size', 'Container app', 'Database', 'Valkey'], $rows);
    }

    private function money(int $cents): string
    {
        return '$'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }

    /**
     * @param  list<string>  $head
     * @param  list<list<string>>  $rows
     */
    private function markdown(array $head, array $rows): string
    {
        $line = static fn (array $cells): string => '| '.implode(' | ', $cells).' |';
        $out = [$line($head), $line(array_fill(0, count($head), '---'))];
        foreach ($rows as $row) {
            $out[] = $line($row);
        }

        return implode("\n", $out);
    }
}
