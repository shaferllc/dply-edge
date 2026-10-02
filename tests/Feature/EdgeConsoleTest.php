<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeConsoleTest;

use App\Livewire\Admin\Resources as AdminResources;
use App\Livewire\Sites\Edge\Workspace\Console;
use App\Models\AuditLog;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Jobs\RunContainerCommandJob;
use App\Modules\Edge\Services\Containers\EdgeContainerCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->user = User::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    $this->server = Server::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->user->id]);
    $this->site = Site::factory()->create([
        'organization_id' => $this->org->id,
        'server_id' => $this->server->id,
        'user_id' => $this->user->id,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['runtime_mode' => 'container', 'live_url' => 'https://shop.on-dply.live', 'container' => ['max_instances' => 2, 'dedicated_jobs' => true]]],
    ]);
    EdgeDeployment::query()->create(['site_id' => $this->site->id, 'organization_id' => $this->org->id, 'status' => EdgeDeployment::STATUS_LIVE, 'meta' => ['agent' => '1']]);
});

function console(object $test): Testable
{
    return Livewire::actingAs($test->user)->test(Console::class, ['server' => $test->server, 'site' => $test->site]);
}

test('a command is queued for the chosen container and recorded in the app\'s activity', function () {
    Queue::fake();

    console($this)
        ->assertSee('Web instance 1')
        ->set('target', 'instance-0')
        ->set('command', 'php artisan about')
        ->call('run')
        ->assertHasNoErrors();

    Queue::assertPushed(RunContainerCommandJob::class, fn ($job) => $job->command === 'php artisan about' && $job->target === 'instance-0' && $job->wake === true);
    expect(AuditLog::query()->where('action', 'site.edge.command')->value('new_values'))->toMatchArray(['command' => 'php artisan about', 'target' => 'instance-0']);
});

test('only this app\'s containers can be targeted', function () {
    Queue::fake();

    console($this)->set('target', 'instance-9')->set('command', 'ls')->call('run')->assertHasErrors('target');
    Queue::assertNothingPushed();
});

test('the job streams output into the run and the page shows it with the exit code', function () {
    Http::fake(['shop.on-dply.live/_dply/agent/exec*' => Http::response("{\"out\":\"Laravel 12\\n\"}\n{\"err\":\"careful\\n\"}\n{\"exit\":0,\"seconds\":1.2,\"truncated\":false,\"timed_out\":false}\n")]);
    Queue::fake();
    $component = console($this)->set('command', 'php artisan about')->call('run');
    Queue::assertPushed(RunContainerCommandJob::class, function (RunContainerCommandJob $job): bool {
        $job->handle();

        return true;
    });

    $run = EdgeContainerCommands::read($component->get('runId'));
    expect($run['status'])->toBe('done')
        ->and($run['lines'])->toBe([['out' => "Laravel 12\n"], ['err' => "careful\n"]]);
    $component->call('$refresh')->assertSee('Laravel 12')->assertSee('Exit 0');
});

test('a deploy without the agent asks for a redeploy, and the agent can be turned off', function () {
    EdgeDeployment::query()->update(['meta' => []]);
    console($this)->assertSee('Redeploy this app')->call('setAgent', false)->assertSee('turned off for this app');

    expect($this->site->fresh()->edgeMeta()['container_agent'])->toBeFalse();
});

test('operators run ps right away, need a session for anything else, and can run on every container', function () {
    Queue::fake();
    $admin = User::factory()->create();

    $component = Livewire::actingAs($admin)->test(AdminResources::class)
        ->call('openCommand', (string) $this->site->id)
        ->set('opCommand', 'ps aux')->call('runOpCommand')->assertOk()
        ->set('opCommand', 'cat .env')->call('runOpCommand')->assertForbidden();

    $component = Livewire::actingAs($admin)->test(AdminResources::class)
        ->call('openCommand', (string) $this->site->id)
        ->set('accessReason', 'Ticket 7: queue stuck')->call('startAccess')
        ->set('opCommand', 'php artisan queue:failed')->set('opTarget', 'every')->call('runOpCommand');

    $targets = array_keys(EdgeContainerCommands::targets($this->site->fresh()));
    expect(array_keys($component->get('opRuns')))->toBe($targets);
    Queue::assertPushed(RunContainerCommandJob::class, fn ($job) => $job->command === 'php artisan queue:failed' && $job->wake === false);
    $support = AuditLog::query()->where('action', 'support.container.command')->get();
    expect($support)->toHaveCount(1 + count($targets)) // ps once, then one per container
        ->and($support->last()->new_values['reason'])->toBe('Ticket 7: queue stuck');
});
