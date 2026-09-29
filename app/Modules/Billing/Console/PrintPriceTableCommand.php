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
 *
 * Every docs table sits under a `<!-- generated: php artisan
 * dply:billing:price-table … -->` marker. After a price change:
 *
 *   php artisan dply:billing:price-table --write-docs    rewrite every marked table in place
 *   php artisan dply:billing:price-table --check-docs    fail if any is stale (DocsPriceTablesTest)
 */
class PrintPriceTableCommand extends Command
{
    protected $signature = 'dply:billing:price-table
                            {section=all : plans, limits, fair-use, rates, sizes, or all}
                            {--group=* : Only these usage groups (rates)}
                            {--product= : app, database or valkey (sizes)}
                            {--write-docs : Rewrite every generated table in the docs in place}
                            {--check-docs : Fail if any generated table in the docs is stale}
                            {--docs-path= : The docs directory (default: docs/site)}';

    protected $description = 'Print the pricing tables as Markdown for docs/site.';

    private const SECTIONS = ['plans', 'limits', 'fair-use', 'rates', 'sizes'];

    public function handle(): int
    {
        if ($this->option('write-docs') || $this->option('check-docs')) {
            return $this->docs((bool) $this->option('write-docs'));
        }

        $section = (string) $this->argument('section');
        if ($section !== 'all' && ! in_array($section, self::SECTIONS, true)) {
            $this->error('Unknown section. Use one of: all, '.implode(', ', self::SECTIONS));

            return self::FAILURE;
        }

        foreach ($section === 'all' ? self::SECTIONS : [$section] as $name) {
            if ($section === 'all') {
                $this->line("<!-- {$name} -->");
            }
            $this->line($this->render($name, (array) $this->option('group'), $this->option('product')));
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /** @param  list<string>  $groups */
    private function render(string $section, array $groups, mixed $product): string
    {
        return match ($section) {
            'plans' => $this->plans(),
            'limits' => $this->limits(),
            'fair-use' => $this->fairUse(),
            'rates' => $this->rates($groups),
            'sizes' => $this->sizes($product),
            default => throw new \InvalidArgumentException("Unknown price table section: {$section}"),
        };
    }

    /**
     * Finds every `<!-- generated: php artisan dply:billing:price-table … -->`
     * marker under the docs directory and compares the table below it with
     * what the command prints now; rewrites stale ones when $write.
     */
    private function docs(bool $write): int
    {
        $dir = (string) ($this->option('docs-path') ?: base_path('docs/site'));
        $checked = 0;
        $stale = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'md' || str_contains($file->getPathname(), '_reports')) {
                continue;
            }
            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            $changed = false;
            for ($i = 0; $i < count($lines); $i++) {
                if (preg_match('/<!-- generated: php artisan dply:billing:price-table(.*?)-->/', $lines[$i], $m) !== 1) {
                    continue;
                }
                [$section, $groups, $product] = $this->markerArgs($m[1]);
                if (! in_array($section, self::SECTIONS, true)) {
                    $stale[] = $file->getPathname().':'.($i + 1).' (unknown section '.$section.')';

                    continue;
                }
                $expected = explode("\n", $this->render($section, $groups, $product));
                // The table: the `|` lines after the marker (blank lines before it allowed).
                $start = $i + 1;
                while ($start < count($lines) && $lines[$start] === '') {
                    $start++;
                }
                $end = $start;
                while ($end < count($lines) && str_starts_with($lines[$end], '|')) {
                    $end++;
                }
                $checked++;
                if (array_slice($lines, $start, $end - $start) === $expected) {
                    continue;
                }
                $stale[] = $file->getPathname().':'.($i + 1);
                if ($write) {
                    if ($start === $end) {
                        // No table yet: add one right under the marker, followed by a blank line.
                        [$start, $end] = [$i + 1, $i + 1];
                        if (($lines[$i + 1] ?? '') !== '') {
                            $expected[] = '';
                        }
                    }
                    array_splice($lines, $start, $end - $start, $expected);
                    $changed = true;
                }
            }
            if ($changed) {
                file_put_contents($file->getPathname(), implode("\n", $lines)."\n");
            }
        }

        foreach ($stale as $where) {
            $this->line(($write ? 'rewrote ' : 'stale ').$where);
        }
        $this->info(sprintf('%d generated table(s) checked, %d %s.', $checked, count($stale), $write ? 'rewritten' : 'stale'));

        return $checked === 0 || (! $write && $stale !== []) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * `rates --group="Key-value" --group=Queues` → ['rates', ['Key-value', 'Queues'], null].
     *
     * @return array{0: string, 1: list<string>, 2: ?string}
     */
    private function markerArgs(string $args): array
    {
        preg_match_all('/--(\w+)=("[^"]*"|\S+)|(\S+)/', trim($args), $parts, PREG_SET_ORDER);
        $section = 'all';
        $options = ['group' => [], 'product' => []];
        foreach ($parts as $part) {
            if (($part[3] ?? '') !== '') {
                $section = $part[3];
            } else {
                $options[$part[1]][] = trim($part[2], '"');
            }
        }

        return [$section, $options['group'], $options['product'][0] ?? null];
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
            'Queue workers per app' => fn (array $t): string => $num($t['worker_instances']).($t['worker_autoscale'] ? ($t['worker_instances'] === 1 ? ', starts when jobs arrive' : ', autoscaling') : ''),
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
                if ($product === 'app') {
                    $row[] = $cell['typical'];
                    $row[] = $cell['cap'];
                }
                $rows[] = $row;
            }
            $head = ['Size', 'Memory', 'Per second awake', 'Per hour awake'];

            return $this->markdown(match ($product) {
                'valkey' => [...$head, 'Most per month', 'Sleeps'],
                'app' => [...$head, 'Always on, 25% CPU', 'Most per month'],
                default => $head,
            }, $rows);
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
