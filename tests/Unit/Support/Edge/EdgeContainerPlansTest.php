<?php

declare(strict_types=1);

use App\Modules\Edge\Support\EdgeContainerPlans;

test('container plans map onto deploy settings', function () {
    expect(EdgeContainerPlans::settings('medium'))->toMatchArray([
        'plan' => 'medium',
        'instance_type' => 'standard-2',
        'max_instances' => 2,
        'sleep_after' => '1h',
        'scheduler' => true,
        'migrate_on_boot' => true,
    ])->and(EdgeContainerPlans::settings('nope')['plan'])->toBe('flex')
        ->and(EdgeContainerPlans::settings('flex', [
            'max_instances' => 4,
            'sleep_after' => '6h',
            'jurisdiction' => 'eu',
            'scheduler' => true,
        ]))->toMatchArray([
            'plan' => 'flex',
            'instance_type' => 'basic',
            'max_instances' => 4,
            'sleep_after' => '6h',
            'jurisdiction' => 'eu',
            'scheduler' => true,
        ]);
});
