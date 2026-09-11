<?php

declare(strict_types=1);

use App\Models\ApiToken;
use App\Support\Cli\DplyCliCommandCatalog;

it('indexes the edge cli command families', function () {
    $catalog = DplyCliCommandCatalog::forServer();

    expect($catalog['total'])->toBe(count($catalog['entries']))
        ->and(collect($catalog['groups'])->pluck('key')->all())->toContain('setup', 'account', 'edge', 'notifications', 'billing');

    $ids = collect($catalog['entries'])->pluck('id');
    expect($ids->unique()->count())->toBe($ids->count());
});

it('lists no command the cli no longer ships', function () {
    $commands = collect(DplyCliCommandCatalog::entries())->pluck('command')->implode("\n");

    expect($commands)->not->toMatch('/dply (server|serverless|project|site |errors|uptime|init)\b/');
});

it('only names scopes a token can hold', function () {
    $catalog = ApiToken::catalogAbilities();

    foreach (DplyCliCommandCatalog::entries() as $entry) {
        if ($entry['scope'] !== null) {
            expect($catalog)->toContain($entry['scope']);
        }
    }
});
