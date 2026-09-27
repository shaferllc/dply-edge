<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Modules\Edge\Services\EdgeTemplateRegistry;
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
    'plain static' => [['runtime' => 'static', 'framework' => 'static']],
    'node runtime only' => [['runtime' => 'node', 'framework' => '']],
]);

test('blocks long-running backend frameworks without pointing at servers that no longer exist', function (array $plan) {
    $result = EdgeEligibility::evaluate($plan);

    expect($result['eligible'])->toBeFalse()
        ->and($result['alternative_route'])->toBeNull()
        ->and($result['message'])->toContain('dply doesn’t run')
        ->and($result['message'])->not->toContain('BYO');
})->with([
    'wordpress' => [['runtime' => 'php', 'framework' => 'wordpress']],
    'django' => [['runtime' => 'python', 'framework' => 'django']],
    'go runtime' => [['runtime' => 'go', 'framework' => '']],
]);

test('hugo and jekyll are blocked with a clear message: the node build image has neither', function (string $framework, string $label) {
    $result = EdgeEligibility::evaluate(['runtime' => 'static', 'framework' => $framework]);

    expect($result['eligible'])->toBeFalse()
        ->and($result['message'])->toContain("can’t build {$label} sites yet");
})->with([
    'hugo' => ['hugo', 'Hugo'],
    'jekyll' => ['jekyll', 'Jekyll'],
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

test('php and ruby apps are eligible as containers', function (array $plan) {
    expect(EdgeEligibility::evaluate($plan)['eligible'])->toBeTrue()
        ->and(EdgeEligibility::needsContainer($plan))->toBeTrue();
})->with([
    'laravel' => [['runtime' => 'php', 'framework' => 'laravel']],
    'php runtime' => [['runtime' => 'php', 'framework' => 'php']],
    'rails' => [['runtime' => 'ruby', 'framework' => 'rails']],
    'sinatra' => [['runtime' => 'ruby', 'framework' => 'sinatra']],
    'nest api' => [['runtime' => 'node', 'framework' => 'nest']],
    'express' => [['runtime' => 'node', 'framework' => 'express']],
]);

test('javascript and wordpress plans never ask for a container', function () {
    expect(EdgeEligibility::needsContainer(['runtime' => 'node', 'framework' => 'astro']))->toBeFalse()
        ->and(EdgeEligibility::needsContainer(['runtime' => 'php', 'framework' => 'wordpress']))->toBeFalse();
});

test('every container template is a container workload with a hero image', function () {
    $containers = collect(EdgeTemplateRegistry::all())->where('runtime_mode', 'container');

    expect($containers)->toHaveCount(3);
    foreach ($containers as $template) {
        expect(EdgeEligibility::needsContainer(['framework' => $template['framework']]))->toBeTrue()
            ->and(file_exists(public_path(ltrim($template['hero_url'], '/'))))->toBeTrue();
    }
});
