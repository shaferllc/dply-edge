<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Modules\Edge\Support\EdgeEligibility;

test('empty and unknown plans stay eligible', function () {
    expect(EdgeEligibility::isEligible([]))->toBeTrue();
    expect(EdgeEligibility::isEligible(['no_match' => true]))->toBeTrue();
    expect(EdgeEligibility::isEligible(['error' => 'clone failed']))->toBeTrue();
});

test('allows node static and ssg frameworks', function (array $plan) {
    expect(EdgeEligibility::isEligible($plan))->toBeTrue();
})->with([
    'vite' => [['runtime' => 'node', 'framework' => 'vite']],
    'astro' => [['runtime' => 'node', 'framework' => 'astro']],
    'keel' => [['runtime' => 'node', 'framework' => 'keel']],
    'next static alias' => [['runtime' => 'node', 'framework' => 'nextjs']],
    'next' => [['runtime' => 'node', 'framework' => 'next']],
    'node generic' => [['runtime' => 'node', 'framework' => 'node_generic']],
    'hugo' => [['runtime' => 'static', 'framework' => 'hugo']],
    'jekyll' => [['runtime' => 'static', 'framework' => 'jekyll']],
    'plain static' => [['runtime' => 'static', 'framework' => 'static']],
    'node runtime only' => [['runtime' => 'node', 'framework' => '']],
]);

test('blocks long-running backend frameworks', function (array $plan, string $route) {
    $result = EdgeEligibility::evaluate($plan);

    expect($result['eligible'])->toBeFalse()
        ->and($result['alternative_route'])->toBe($route)
        ->and($result['message'])->not->toBeNull();
})->with([
    'laravel' => [['runtime' => 'php', 'framework' => 'laravel'], 'servers.create'],
    'wordpress' => [['runtime' => 'php', 'framework' => 'wordpress'], 'servers.create'],
    'rails' => [['runtime' => 'ruby', 'framework' => 'rails'], 'servers.create'],
    'django' => [['runtime' => 'python', 'framework' => 'django'], 'servers.create'],
    'nest api' => [['runtime' => 'node', 'framework' => 'nest'], 'servers.create'],
    'php runtime' => [['runtime' => 'php', 'framework' => 'php'], 'servers.create'],
    'go runtime' => [['runtime' => 'go', 'framework' => ''], 'servers.create'],
]);

test('blocks framework monorepo package roots flagged not_a_site', function () {
    $result = EdgeEligibility::evaluate([
        'runtime' => 'node',
        'framework' => 'astro',
        'not_a_site' => true,
    ]);

    expect($result['eligible'])->toBeFalse()
        ->and($result['alternative_route'])->toBeNull()
        ->and($result['message'])->toContain('monorepo');
});
