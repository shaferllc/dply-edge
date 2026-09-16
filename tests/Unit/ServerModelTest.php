<?php

namespace Tests\Unit\ServerModelTest;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('is ready returns true when status ready', function () {
    $server = Server::factory()->ready()->create();

    expect($server->isReady())->toBeTrue();
});

test('is ready returns false when status pending', function () {
    $server = Server::factory()->pending()->create();

    expect($server->isReady())->toBeFalse();
});

test('servers table has dual key columns', function () {
    expect(Schema::hasColumn('servers', 'ssh_operational_private_key'))->toBeTrue();
    expect(Schema::hasColumn('servers', 'ssh_recovery_private_key'))->toBeTrue();
});
