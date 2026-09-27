<?php

declare(strict_types=1);

namespace Tests\Feature\InAppDocLinksTest;

use App\Support\Docs\DocsSite;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;

test('every in-app docs link points at a page in docs/site/nav.json', function () {
    $patterns = [
        '/<x-docs-link[^>]*?\s(?:doc-)?slug="([^"]+)"/s',
        '/\sdoc-slug="([^"]+)"/',
        '/[\'"]docSlug[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/',
        '/route\(\s*[\'"]docs\.(?:show|markdown)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
    ];

    $used = [];
    foreach (Finder::create()->files()->in([app_path(), resource_path('views')])->exclude('docs')->name('*.php') as $file) {
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $file->getContents(), $matches);
            foreach ($matches[1] as $slug) {
                $used[$slug][] = $file->getRelativePathname();
            }
        }
    }

    $docs = app(DocsSite::class);
    $missing = array_filter($used, fn (array $files, string $slug) => $docs->find($slug) === null, ARRAY_FILTER_USE_BOTH);

    expect($used)->not->toBeEmpty()
        ->and($missing)->toBe([]);
});

test('docs-link renders a link to the public docs page', function () {
    $html = Blade::render('<x-docs-link slug="deployments">Guide</x-docs-link>');

    expect($html)->toContain('href="'.route('docs.show', 'deployments').'"')
        ->and($html)->toContain('Guide');
});

test('docs-link renders nothing without a slug', function () {
    expect(trim(Blade::render('<x-docs-link :slug="null">Guide</x-docs-link>')))->toBe('');
});
