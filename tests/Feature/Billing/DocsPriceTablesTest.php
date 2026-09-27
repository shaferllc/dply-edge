<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
 * Every pricing table in docs/site sits under a
 * `<!-- generated: php artisan dply:billing:price-table … -->` comment. The
 * table must be exactly what the command prints now, so the docs cannot drift
 * from the bill (UsagePrice, subscription.standard.tiers).
 */
test('every generated pricing table in the docs matches dply:billing:price-table', function () {
    $checked = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('docs/site'))) as $file) {
        if ($file->getExtension() !== 'md' || str_contains($file->getPathname(), '_reports')) {
            continue;
        }
        $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
        foreach ($lines as $i => $line) {
            if (preg_match('/<!-- generated: php artisan dply:billing:price-table(.*?)-->/', $line, $m) !== 1) {
                continue;
            }
            preg_match_all('/--(\w+)=("[^"]*"|\S+)|(\S+)/', trim($m[1]), $parts, PREG_SET_ORDER);
            $args = [];
            foreach ($parts as $part) {
                if (($part[3] ?? '') !== '') {
                    $args['section'] = $part[3];
                } else {
                    $args['--'.$part[1]][] = trim($part[2], '"');
                }
            }
            if (isset($args['--product'])) {
                $args['--product'] = $args['--product'][0];
            }
            Artisan::call('dply:billing:price-table', $args);
            $expected = trim(Artisan::output());

            $table = [];
            for ($j = $i + 1; $j < count($lines) && ($lines[$j] === '' && $table === [] || str_starts_with($lines[$j], '|')); $j++) {
                if ($lines[$j] !== '') {
                    $table[] = $lines[$j];
                }
            }

            expect(implode("\n", $table))->toBe($expected, $file->getFilename().':'.($i + 1));
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(0);
});
