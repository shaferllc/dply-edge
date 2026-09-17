<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeContainerDetectionTest;

use App\Models\Organization;
use App\Models\User;
use App\Modules\Edge\Livewire\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function creator(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

/** raw.githubusercontent.com responses for one repo; everything else 404s. */
function fakeRepo(string $repo, array $files): void
{
    $fakes = [];
    foreach ($files as $path => $contents) {
        $fakes["raw.githubusercontent.com/{$repo}/*/{$path}"] = Http::response($contents, 200);
    }
    Http::fake($fakes + ['*' => Http::response('Not Found', 404)]);
}

beforeEach(function () {
    Cache::flush();
    config(['edge.fake.enabled' => true]);
});

test('a laravel repo with a vite package.json is detected as laravel and preselects container', function () {
    fakeRepo('laravel/laravel', [
        'composer.json' => json_encode(['require' => ['php' => '^8.2', 'laravel/framework' => '^12.0']]),
        'package.json' => json_encode(['devDependencies' => ['vite' => '^6.0'], 'scripts' => ['build' => 'vite build']]),
    ]);

    Livewire::actingAs(creator())
        ->test(Create::class)
        ->set('repo', 'laravel/laravel')
        ->set('branch', '12.x')
        ->call('detectFromRepository')
        ->assertSet('detectedPlan.runtime', 'php')
        ->assertSet('detectedPlan.framework', 'laravel')
        ->assertSet('form.runtime_mode', 'container')
        ->assertDontSee('Not an Edge workload')
        ->assertSee('/min')
        ->assertSee('Compute billed per second')
        ->assertDontSee('$2.00');
});

test('a rails repo is detected as rails', function () {
    fakeRepo('acme/shop', [
        'Gemfile' => "source 'https://rubygems.org'\ngem \"rails\", \"~> 7.2\"\n",
        'package.json' => json_encode(['dependencies' => ['esbuild' => '^0.24'], 'scripts' => ['build' => 'esbuild app.js']]),
    ]);

    Livewire::actingAs(creator())
        ->test(Create::class)
        ->set('repo', 'acme/shop')
        ->call('detectFromRepository')
        ->assertSet('detectedPlan.framework', 'rails')
        ->assertSet('form.runtime_mode', 'container');
});

test('an express server is a container', function () {
    fakeRepo('acme/api', ['package.json' => json_encode(['dependencies' => ['express' => '^5.0'], 'scripts' => ['start' => 'node server.js']])]);

    Livewire::actingAs(creator())
        ->test(Create::class)
        ->set('repo', 'acme/api')
        ->call('detectFromRepository')
        ->assertSet('detectedPlan.framework', 'express')
        ->assertSet('form.runtime_mode', 'container');
});

test('a vite site stays static', function () {
    fakeRepo('acme/site', ['package.json' => json_encode(['devDependencies' => ['vite' => '^6.0'], 'scripts' => ['build' => 'vite build']])]);

    Livewire::actingAs(creator())
        ->test(Create::class)
        ->set('repo', 'acme/site')
        ->call('detectFromRepository')
        ->assertSet('detectedPlan.framework', 'vite')
        ->assertSet('form.runtime_mode', 'static')
        ->assertSee('Included')
        ->assertSee('0 of 1 sites on Free');
});

test('without container setup, a laravel repo explains what is missing instead of looking static', function () {
    config(['edge.fake.enabled' => false, 'edge.cloudflare.api_token' => '']);
    fakeRepo('laravel/laravel', ['composer.json' => json_encode(['require' => ['laravel/framework' => '^12.0']])]);

    Livewire::actingAs(creator())
        ->test(Create::class)
        ->set('repo', 'laravel/laravel')
        ->call('detectFromRepository')
        ->assertSet('detectedPlan.framework', 'laravel')
        ->assertSee('Laravel app detected — it runs as a Container')
        ->assertSee('DPLY_EDGE_CF_API_TOKEN');
});

test('the loaded example chip is the selected one', function () {
    Http::fake(['*' => Http::response('Not Found', 404)]);

    $component = Livewire::actingAs(creator())->test(Create::class)
        ->call('loadExampleApp', 'laravel-starter');

    $html = $component->html();
    expect($html)->toMatch('/data-testid="edge-example-laravel-starter"[^>]*aria-pressed="true"/s')
        ->and($html)->toMatch('/data-testid="edge-example-keel-workers"[^>]*aria-pressed="false"/s');
});
