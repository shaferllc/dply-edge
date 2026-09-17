<?php

declare(strict_types=1);

use App\Modules\Edge\Support\EdgeDeliveryRecommender;

test('recommends the delivery mode from the detection plan', function (array $plan, bool $workerSsr, string $mode) {
    config(['edge.fake.enabled' => $workerSsr, 'edge.cloudflare.api_token' => '']);

    expect(EdgeDeliveryRecommender::for($plan)['mode'])->toBe($mode);
})->with([
    'laravel' => [['runtime' => 'php', 'framework' => 'laravel'], false, 'container'],
    'rails with puma start' => [['runtime' => 'ruby', 'framework' => 'rails', 'start_command' => 'bundle exec puma'], false, 'container'],
    'express' => [['runtime' => 'node', 'framework' => 'express'], false, 'container'],
    'vite' => [['runtime' => 'node', 'framework' => 'vite', 'build_command' => 'vite build'], false, 'static'],
    'astro static' => [['runtime' => 'node', 'framework' => 'astro'], true, 'static'],
    'next ssr, worker ssr available' => [['runtime' => 'node', 'framework' => 'next', 'start_command' => 'next start', 'build_command' => 'next build'], true, 'hybrid'],
    'next ssr, no worker ssr' => [['runtime' => 'node', 'framework' => 'next', 'start_command' => 'next start', 'build_command' => 'next build'], false, 'hybrid'],
    'next export' => [['runtime' => 'node', 'framework' => 'next', 'start_command' => '', 'build_command' => 'next build && next export'], true, 'static'],
    'keel' => [['runtime' => 'node', 'framework' => 'keel'], false, 'hybrid'],
]);

test('no recommendation without a detected runtime', function () {
    expect(EdgeDeliveryRecommender::for([]))->toBeNull()
        ->and(EdgeDeliveryRecommender::for(['error' => 'timeout']))->toBeNull()
        ->and(EdgeDeliveryRecommender::for(['runtime' => 'php', 'framework' => 'laravel'])['reason'])->toContain('Laravel');
});
