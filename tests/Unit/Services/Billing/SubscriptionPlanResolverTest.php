<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Modules\Billing\Services\SubscriptionPlanResolver;
use InvalidArgumentException;

test('the shipped config carries only the free plan, with unlimited edge apps', function () {
    $free = (new SubscriptionPlanResolver)->resolveByKey('free');

    expect(array_keys((array) config('subscription.standard.plans')))->toBe(['free'])
        ->and($free['key'])->toBe('free')
        ->and($free['price_cents'])->toBe(0)
        ->and($free['max_edge_apps'])->toBeNull();
});

test('a surface ceiling absent from config is unlimited', function () {
    config(['subscription.standard.plans' => [
        'free' => ['label' => 'Free', 'price_cents' => 0, 'max_edge_apps' => 3],
    ]]);

    $free = (new SubscriptionPlanResolver)->resolveByKey('free');

    expect($free['max_edge_apps'])->toBe(3)
        ->and($free['max_sites'])->toBeNull()
        ->and($free['max_functions'])->toBeNull();
});

test('resolve by unknown key throws', function () {
    (new SubscriptionPlanResolver)->resolveByKey('starter');
})->throws(InvalidArgumentException::class);
