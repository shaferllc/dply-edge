<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Crons;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('cron expressions read as words for the common shapes', function (string $cron, string $words) {
    expect(Crons::describe($cron))->toBe($words);
})->with([
    ['* * * * *', 'every minute'],
    ['*/5 * * * *', 'every 5 minutes'],
    ['0 * * * *', 'every hour'],
    ['15 * * * *', 'every hour at 15 past'],
    ['0 */6 * * *', 'every 6 hours'],
    ['30 6 * * *', 'every day at 06:30 UTC'],
    ['0 6 * * 1', 'every Monday at 06:00 UTC'],
    ['0 6 1 * *', 'on day 1 of every month at 06:00 UTC'],
    ['0 6 1-7 * 1', '0 6 1-7 * 1'],
]);

test('schedules are added in a modal and ones past the fifth are flagged', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id,
        'type' => SiteType::Static, 'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => 'ssr']],
    ]);

    $page = Livewire::actingAs($user)->test(Crons::class, ['server' => $server, 'site' => $site])
        ->assertSee('isn’t called on a schedule yet')
        ->call('newCron')
        ->set('new_schedule', '30 6 * * *')
        ->call('saveCron')
        ->assertHasNoErrors()
        ->assertSet('editingCron', null)
        ->assertSee('Every day at 06:30 UTC');

    expect($site->fresh()->edgeMeta()['crons_overrides'])->toBe([['schedule' => '30 6 * * *', 'handler' => null]]);

    foreach (['1 * * * *', '2 * * * *', '3 * * * *', '4 * * * *', '5 * * * *'] as $cron) {
        $page->call('newCron')->set('new_schedule', $cron)->call('saveCron');
    }

    $page->assertSee('1 won’t run')->assertSee('6 of 5 used');
});

test('run now calls the container schedule route for any app and explains a missing route', function () {
    Http::fake(['*/_dply/schedule' => Http::response('Not Found', 404)]);
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id,
        'type' => SiteType::Static, 'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => [
            'runtime_mode' => 'container',
            'live_url' => 'https://node-app.on-dply.site',
            'crons_overrides' => [['schedule' => '0 6 * * *', 'handler' => 'reports:daily']],
        ]],
    ]);

    Livewire::actingAs($user)->test(Crons::class, ['server' => $server, 'site' => $site])
        ->assertSee('Run now')
        ->assertSee('/_dply/schedule')
        ->call('runNow', 'reports:daily')
        ->assertSet('runCommand', 'reports:daily')
        ->assertSee('Handle it in your app')
        ->call('runNow', 'not-on-the-list')
        ->assertStatus(404);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/_dply/schedule') && $r['handler'] === 'reports:daily');
});
