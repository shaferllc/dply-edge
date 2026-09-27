<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
 * Every pricing table in docs/site sits under a
 * `<!-- generated: php artisan dply:billing:price-table … -->` comment. The
 * table must be exactly what the command prints now, so the docs cannot drift
 * from the bill (UsagePrice, subscription.standard.tiers). After a price
 * change, `php artisan dply:billing:price-table --write-docs` rewrites them.
 */
test('every generated pricing table in the docs matches dply:billing:price-table', function () {
    $exit = Artisan::call('dply:billing:price-table', ['--check-docs' => true]);

    expect($exit)->toBe(0, Artisan::output());
});

test('--write-docs rewrites a stale table in place and a second run changes nothing', function () {
    $dir = sys_get_temp_dir().'/dply-docs-'.uniqid();
    mkdir($dir);
    $page = "# Key-value\n\nIntro.\n\n<!-- generated: php artisan dply:billing:price-table rates --group=\"Key-value\" -->\n| Meter | Price | Unit |\n| --- | --- | --- |\n| Reads | \$9.99 | per million |\n\nAfter the table.\n";
    file_put_contents($dir.'/kv.md', $page);

    expect(Artisan::call('dply:billing:price-table', ['--check-docs' => true, '--docs-path' => $dir]))->toBe(1);

    expect(Artisan::call('dply:billing:price-table', ['--write-docs' => true, '--docs-path' => $dir]))->toBe(0);
    Artisan::call('dply:billing:price-table', ['section' => 'rates', '--group' => ['Key-value']]);
    $table = trim(Artisan::output());
    $written = file_get_contents($dir.'/kv.md');

    expect($written)->toBe("# Key-value\n\nIntro.\n\n<!-- generated: php artisan dply:billing:price-table rates --group=\"Key-value\" -->\n{$table}\n\nAfter the table.\n")
        ->and(Artisan::call('dply:billing:price-table', ['--check-docs' => true, '--docs-path' => $dir]))->toBe(0);

    Artisan::call('dply:billing:price-table', ['--write-docs' => true, '--docs-path' => $dir]);
    expect(file_get_contents($dir.'/kv.md'))->toBe($written);

    unlink($dir.'/kv.md');
    rmdir($dir);
});
