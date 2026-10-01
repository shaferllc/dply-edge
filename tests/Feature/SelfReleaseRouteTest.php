<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

test('the self release step answers JSON even behind the coming-soon gate', function () {
    config(['dply.coming_soon' => true, 'edge.self.queue_token' => 'secret']);
    Artisan::shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('Nothing to migrate.');

    $this->postJson('/_dply/command', ['command' => 'release'], ['x-dply-queue-token' => 'secret'])
        ->assertOk()
        ->assertExactJson(['output' => 'Nothing to migrate.']);
    $this->postJson('/_dply/command', ['command' => 'release'], ['x-dply-queue-token' => 'wrong'])->assertForbidden();
});
