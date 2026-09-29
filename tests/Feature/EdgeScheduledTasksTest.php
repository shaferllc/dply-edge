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

    // An SSR Worker's own scheduled() branches on the schedule: Cloudflare's 5 still apply.
    $page->assertSee('1 won’t run')->assertSee('6 of 5 schedules used')
        ->assertSee('Won’t run: Cloudflare allows 5 schedules per Worker.');
});

test('a container app runs more than 5 schedules and refuses syntax the Worker can’t read', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id,
        'type' => SiteType::Static, 'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => 'container']],
    ]);

    $page = Livewire::actingAs($user)->test(Crons::class, ['server' => $server, 'site' => $site]);
    foreach (range(1, 7) as $hour) {
        $page->call('newCron')->set('new_schedule', "0 {$hour} * * *")->set('new_handler', "report:{$hour}")->call('saveCron')->assertHasNoErrors();
    }
    $page->assertSee('7 tasks')->assertDontSee('won’t run');

    $page->call('newCron')->set('new_schedule', '0 6 L * *')->call('saveCron')
        ->assertHasErrors('new_schedule');
    expect($site->fresh()->edgeMeta()['crons_overrides'])->toHaveCount(7);

    // A repo schedule the Worker can't read is shown, marked, and not deployed.
    $site->mergeEdgeMeta(['crons_overrides' => [...$site->fresh()->edgeMeta()['crons_overrides'], ['schedule' => '0 6 * * MON#2', 'handler' => 'x']]]);
    $site->save();
    $rows = Crons::schedule($site->fresh())['rows'];
    expect(collect($rows)->where('dropped', true)->pluck('dropped_reason')->all())->toBe(['unsupported']);
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

test('scheduled tasks live on Overview: a map box and an Add-resource row, and the old Crons URL redirects there', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id,
        'type' => SiteType::Static, 'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => [
            'runtime_mode' => 'ssr',
            'crons_overrides' => [['schedule' => '30 6 * * *', 'handler' => null]],
        ]],
    ]);

    expect(collect(\App\Support\SiteSettingsSidebar::items($site, $server))->pluck('id')->all())->not->toContain('crons');

    Livewire::actingAs($user)->test(\App\Livewire\Sites\Edge\Workspace\Resources::class, ['server' => $server, 'site' => $site])
        ->assertSee('Scheduled tasks')
        ->assertSee('Every day at 06:30 UTC')
        ->call('openConnectionBuilder')
        ->assertSee('Scheduled task');

    Livewire::actingAs($user)
        ->test(\App\Livewire\Sites\EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'crons'])
        ->assertRedirect(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']));
});

test('run now shows the command’s error when it fails in the app', function () {
    Http::fake(['*/_dply/schedule' => Http::response(['command' => 'booking:create-admin', 'error' => 'Not enough arguments (missing: "email").'], 500)]);
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
            'live_url' => 'https://app.on-dply.site',
            'crons_overrides' => [['schedule' => '0 6 * * *', 'handler' => 'booking:create-admin']],
        ]],
    ]);

    Livewire::actingAs($user)->test(Crons::class, ['server' => $server, 'site' => $site])
        // The button's argument is compiled (a raw @js here reached the browser and broke the click).
        ->assertSeeHtml('wire:click="runNow(\'booking:create-admin\')"')
        ->call('runNow', 'booking:create-admin')
        ->assertSet('runOutput', 'Not enough arguments (missing: "email").')
        ->assertSee('Not enough arguments (missing: &quot;email&quot;).', false)
        // It asks for what's missing, runs with it, and can keep it on the task.
        ->assertSet('runArgs', ['email' => ''])
        ->assertSee('booking:create-admin needs these to run:')
        ->set('runArgs.email', 'tom@example.com')
        ->call('runWithArgs')
        ->call('saveArgsToTask');

    Http::assertSent(fn ($request) => $request['handler'] === 'booking:create-admin tom@example.com');
    expect($site->fresh()->edgeMeta()['crons_overrides'][0]['handler'])->toBe('booking:create-admin tom@example.com');
});

test('arguments with spaces are quoted for Artisan', function () {
    Http::fake(['*/_dply/schedule' => Http::response(['error' => 'Not enough arguments (missing: "name").'], 500)]);
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id,
        'type' => SiteType::Static, 'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => 'container', 'live_url' => 'https://app.on-dply.site', 'crons_overrides' => [['schedule' => '0 6 * * *', 'handler' => 'greet']]]],
    ]);

    Livewire::actingAs($user)->test(Crons::class, ['server' => $server, 'site' => $site])
        ->call('runNow', 'greet')
        ->set('runArgs.name', 'Ada "the" Lovelace')
        ->call('runWithArgs');

    Http::assertSent(fn ($request) => $request['handler'] === 'greet "Ada \\"the\\" Lovelace"');
});
