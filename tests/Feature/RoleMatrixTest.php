<?php

declare(strict_types=1);

namespace Tests\Feature\RoleMatrixTest;

use App\Enums\SiteType;
use App\Livewire\Organizations\Members;
use App\Livewire\Sites\Edge\Workspace\Deploys;
use App\Livewire\Sites\Edge\Workspace\Domains;
use App\Livewire\Sites\Edge\Workspace\Environment;
use App\Livewire\Sites\Edge\Workspace\Firewall;
use App\Livewire\Sites\Edge\Workspace\Logs;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\ApiToken;
use App\Models\EdgeSiteMember;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Livewire\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * The role matrix (docs/site/roles-and-permissions.md, ruling r-jnv0r3qf1xk49kmc):
 * update = configure (env, domains, resources, security, build settings),
 * deploy = ship (deploy, redeploy, roll back, previews), view = read + logs.
 * An app role replaces the org role on that app for anyone below org admin.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['edge.fake.enabled' => true]);
    Queue::fake();

    $this->owner = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->owner->id, ['role' => 'owner']);

    $this->server = Server::factory()->create([
        'user_id' => $this->owner->id,
        'organization_id' => $this->org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $this->site = Site::factory()->create([
        'server_id' => $this->server->id,
        'user_id' => $this->owner->id,
        'organization_id' => $this->org->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
                'routing' => ['hostname' => 'edge-app.on-dply.site'],
            ],
        ],
    ]);
});

/** @return User an actor for one row of the matrix */
function actor(object $t, string $who): User
{
    if ($who === 'owner') {
        return $t->owner;
    }
    $user = User::factory()->create();
    if ($who === 'outsider') {
        return $user;
    }
    [$orgRole, $appRole] = array_pad(explode('+app:', $who), 2, null);
    $t->org->users()->attach($user->id, ['role' => $orgRole]);
    if ($appRole !== null) {
        EdgeSiteMember::query()->create([
            'site_id' => $t->site->id,
            'user_id' => $user->id,
            'role' => $appRole,
            'invited_by_user_id' => $t->owner->id,
        ]);
    }

    return $user;
}

// who => [view, update, deploy, manageMembers]
dataset('matrix', [
    'owner' => ['owner', true, true, true, true],
    'admin' => ['admin', true, true, true, true],
    'member' => ['member', true, true, true, false],
    'deployer' => ['deployer', true, false, true, false],
    'member + app viewer' => ['member+app:viewer', true, false, false, false],
    'member + app deployer' => ['member+app:deployer', true, false, true, false],
    'member + app admin' => ['member+app:admin', true, true, true, true],
    'deployer + app admin' => ['deployer+app:admin', true, true, true, true],
    'admin + app viewer' => ['admin+app:viewer', true, true, true, true],
    'viewer' => ['viewer', true, false, false, false],
    'viewer + app admin' => ['viewer+app:admin', true, false, false, false],
    'outsider' => ['outsider', false, false, false, false],
]);

test('site policy matrix', function (string $who, bool $view, bool $update, bool $deploy, bool $manage) {
    $user = actor($this, $who);
    session(['current_organization_id' => $this->org->id]);
    $gate = Gate::forUser($user);
    $site = $this->site->fresh();

    expect([
        'view' => $gate->allows('view', $site),
        'update' => $gate->allows('update', $site),
        'deploy' => $gate->allows('deploy', $site),
        'manageMembers' => $gate->allows('manageMembers', $site),
    ])->toBe(['view' => $view, 'update' => $update, 'deploy' => $deploy, 'manageMembers' => $manage]);
})->with('matrix');

test('workspace actions follow the matrix', function (string $who, bool $view, bool $update, bool $deploy) {
    $user = actor($this, $who);
    session(['current_organization_id' => $this->org->id]);
    $params = ['server' => $this->server, 'site' => $this->site];
    $expect = fn ($component, bool $allowed) => $allowed ? $component->assertStatus(200) : $component->assertForbidden();

    if (! $view) {
        // Not a member: the workspace 404s rather than confirming the app exists.
        Livewire::actingAs($user)->test(Logs::class, $params)->assertNotFound();

        return;
    }

    Livewire::actingAs($user)->test(Logs::class, $params)->assertOk();

    // Configure.
    $expect(Livewire::actingAs($user)->test(Environment::class, $params)->set('edgeEnvText', "FOO=bar\n")->call('saveEdgeEnvText'), $update);
    $expect(Livewire::actingAs($user)->test(Domains::class, $params)->set('edge_domain_input', 'www.example.com')->call('attachEdgeDomain'), $update);
    $expect(Livewire::actingAs($user)->test(Resources::class, $params)->call('addScheduler'), $update);
    $expect(Livewire::actingAs($user)->test(Firewall::class, $params)->call('save'), $update);

    // Ship.
    $expect(Livewire::actingAs($user)->test(Deploys::class, $params)->call('redeployEdge'), $deploy);
    $expect(Livewire::actingAs($user)->test(Deploys::class, $params)->call('rollbackEdgeDeployment', 'missing'), $deploy);
})->with('matrix');

test('api writes follow the matrix and a token never outranks its user', function (string $who, bool $view, bool $update, bool $deploy) {
    $user = actor($this, $who);
    ['plaintext' => $plain] = ApiToken::createToken($user, $this->org, 'matrix', null, ['*']);
    $headers = ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
    $base = '/api/v1/edge/sites/'.$this->site->id;
    $isDeployer = str_starts_with($who, 'deployer');
    $check = function ($response, bool $allowed) {
        $allowed ? expect($response->status())->not->toBe(403) : $response->assertForbidden();
    };

    if ($who === 'outsider') {
        $this->getJson($base, $headers)->assertForbidden();

        return;
    }

    $check($this->getJson($base.'/logs', $headers), $view);
    // An org Deployer's token is also capped to deploy/read abilities, whatever its app role.
    $check($this->patchJson($base.'/env/FOO', ['value' => 'bar'], $headers), $update && ! $isDeployer);
    $check($this->postJson($base.'/domains', ['hostname' => 'www.example.com'], $headers), $update && ! $isDeployer);
    $check($this->patchJson($base.'/access', ['mode' => 'public'], $headers), $update && ! $isDeployer);
    $check($this->postJson($base.'/deployments', [], $headers), $deploy);
    $check($this->postJson($base.'/deployments/missing/rollback', [], $headers), $deploy);
})->with('matrix');

test('deployer sees deploy controls but not configuration controls', function () {
    $user = actor($this, 'deployer');
    session(['current_organization_id' => $this->org->id]);
    $params = ['server' => $this->server, 'site' => $this->site];

    Livewire::actingAs($user)->test(Deploys::class, $params)->assertSeeHtml('wire:click="redeployEdge"');
    Livewire::actingAs($user)->test(Firewall::class, $params)->assertDontSeeHtml('wire:click="save"');
});

test('only owners, admins and members can create apps', function (string $role, bool $allowed) {
    $user = actor($this, $role);
    session(['current_organization_id' => $this->org->id]);

    // An empty form fails validation, so reaching validation means the role check passed.
    $component = Livewire::actingAs($user)->test(Create::class)->call('deploy');
    $allowed ? $component->assertHasErrors() : $component->assertHasNoErrors();
})->with([
    'owner' => ['owner', true],
    'member' => ['member', true],
    'deployer' => ['deployer', false],
    'viewer' => ['viewer', false],
]);

test('org viewers can be invited without a seat and cannot be given an app role', function () {
    $viewer = actor($this, 'viewer');
    session(['current_organization_id' => $this->org->id]);

    expect(array_keys((new Members)->inviteableRoles()))->toContain(Organization::VIEW_ONLY_ROLE);

    Livewire::actingAs($this->owner)
        ->test(\App\Livewire\Sites\Edge\Workspace\Members::class, ['server' => $this->server, 'site' => $this->site])
        ->set('member_user_id', (string) $viewer->id)
        ->set('member_role', EdgeSiteMember::ROLE_ADMIN)
        ->call('addMember')
        ->assertHasErrors('member_user_id');
});

test('the viewer device-flow cap is read-only and within the runtime allowlist', function () {
    $granted = config('cli.device_flow_role_caps.viewer', []);

    expect(array_diff($granted, config('api_token_permissions.viewer_api_allowlist', [])))->toBe([])
        ->and($granted)->not->toContain('edge.deploy')
        ->and($granted)->not->toContain('edge.write');
});
