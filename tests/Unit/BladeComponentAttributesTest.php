<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// Blade compiles {{ }} inside an <x-component> tag's attributes but not
// directives like @js: `wire:click="run(@js($x))"` reaches the browser as
// literal text and the click throws "Invalid or unexpected token". Use
// {{ \Illuminate\Support\Js::from($x) }} there instead.
test('no component tag passes @js in an attribute', function () {
    $offenders = [];
    foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        preg_match_all('/<x-[a-z0-9.:-]+(?:[^>"]|"[^"]*")*>/is', $file->getContents(), $tags, PREG_OFFSET_CAPTURE);
        foreach ($tags[0] as [$tag, $offset]) {
            if (preg_match('/="[^"]*@js\(/', $tag) === 1) {
                $offenders[] = $file->getRelativePathname().':'.(substr_count(substr($file->getContents(), 0, $offset), "\n") + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
