<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\ErrorPages;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Support\EdgeEffectiveErrorPages;
use Livewire\Livewire;

test('editing an error page saves it, cancel drops edits, and maintenance saves on toggle', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
    ]);

    $component = Livewire::actingAs($user)
        ->test(ErrorPages::class, ['server' => $server, 'site' => $site])
        ->assertSee('The site is live')
        ->call('editPage', 'html_404')
        ->assertSet('editingPage', 'html_404')
        ->call('applyTemplate', 'html_404', 'minimal')
        ->call('savePage')
        ->assertHasNoErrors()
        ->assertSet('editingPage', null)
        ->assertSee('A missing page shows your own 404 page');

    expect($site->fresh()->edgeMeta()['error_pages']['html_404'])->toContain('Page not found');

    $component
        ->call('editPage', 'html_404')
        ->set('error_404_html', '<p>changed</p>')
        ->call('closePage')
        ->assertSet('error_404_html', fn ($v) => str_contains($v, 'Page not found'))
        ->set('maintenance_enabled', true)
        ->assertSee('Maintenance is on');

    expect($site->fresh()->edgeMeta()['maintenance']['enabled'])->toBeTrue();

    $component
        ->call('editPage', 'html_403')
        ->call('applyTemplate', 'html_403', 'minimal')
        ->call('savePage')
        ->assertHasNoErrors()
        ->assertSee('sees your own 403 page');

    expect(EdgeEffectiveErrorPages::for($site->fresh(), null)['html_403'])
        ->toContain('Not available in your region');
});
