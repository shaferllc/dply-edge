<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeCreatePageTest;

use App\Enums\SiteType;
use App\Modules\Edge\Livewire\Create;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use ReflectionMethod;

uses(RefreshDatabase::class);

usesFeatures('surface.edge', 'surface.cloud');

test('guest is redirected from edge create', function () {
    $this->get(route('edge.create'))
        ->assertRedirect(route('login'));
});

test('authenticated user can load edge create form', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('edge.create'))
        ->assertOk()
        ->assertSee('Deploy an edge app')
        ->assertSee('Connect Git')
        ->assertSee('Build & delivery')
        ->assertSee('Advanced settings')
        ->assertSee('Est. cost')
        ->assertSee('SPA fallback')
        ->assertSee('Deploy on push')
        ->assertSee('Dply Edge (managed)')
        ->assertSee('Your own account')
        ->assertSee('Or start from an example')
        ->assertSee('Keel on Edge')
        ->assertSee('data-testid="edge-example-apps"', false)
        ->assertSee('data-testid="edge-example-keel-workers"', false)
        ->assertSee('Load sample app')
        ->assertSee('data-testid="load-sample-edge-app"', false);
});

test('load sample app prefills public eleventy template in local development', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->call('loadSampleApp')
        ->assertSet('repo_source', 'manual')
        ->assertSet('repo', '11ty/eleventy-base-blog')
        ->assertSet('branch', 'main')
        ->assertSet('form.name', 'eleventy-portfolio')
        ->assertSet('form.runtime_mode', 'static')
        ->assertSet('form.output_dir', '_site');
});

test('load example app prefills keel starter', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->call('loadExampleApp', 'keel-workers')
        ->assertSet('repo_source', 'manual')
        ->assertSet('repo', 'shaferllc/keel-site')
        ->assertSet('branch', 'main')
        ->assertSet('form.name', 'keel-workers')
        ->assertSet('form.runtime_mode', 'hybrid');
});

test('byo delivery without credentials offers in page cloudflare token modal', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.delivery_mode', 'byo')
        ->assertSee('No CDN account connected')
        ->assertSee('Add Cloudflare token')
        ->assertDontSee('Select a connected account…')
        ->call('openCloudflareCredentialModal')
        ->assertDispatched('open-add-provider-credential-modal');
});

test('newly saved cloudflare credential is selected for byo delivery', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.delivery_mode', 'managed')
        ->call('applyStoredCloudflareCredential', 'cloudflare', 'cred-123')
        ->assertSet('form.delivery_mode', 'byo')
        ->assertSet('form.edge_provider_credential_id', 'cred-123');
});

test('returns 404 when surface edge inactive', function () {
    Feature::define('surface.edge', fn () => false);
    Feature::flushCache();

    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('edge.create'))
        ->assertStatus(400);
});

test('ssr detection still selects hybrid when output_dir is present', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('repo', 'acme/next-app')
        ->set('branch', 'main')
        ->set('detectedPlan', [
            'framework' => 'next',
            'start_command' => 'next start',
            'build_command' => 'npm run build',
            'output_dir' => '.next',
        ])
        ->tap(function ($component): void {
            $method = new ReflectionMethod($component->instance(), 'applyDetectedRuntimePrefills');
            $method->setAccessible(true);
            $method->invoke($component->instance());
        })
        ->assertSet('form.runtime_mode', 'hybrid')
        ->assertSet('form.output_dir', '.next');
});

test('hybrid framework preset selects hybrid without start command', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('repo', 'acme/kit-app')
        ->set('branch', 'main')
        ->set('detectedPlan', [
            'framework' => 'sveltekit',
            'build_command' => 'npm run build',
            'output_dir' => 'build',
        ])
        ->tap(function ($component): void {
            $method = new ReflectionMethod($component->instance(), 'applyDetectedRuntimePrefills');
            $method->setAccessible(true);
            $method->invoke($component->instance());
        })
        ->assertSet('form.runtime_mode', 'hybrid')
        ->assertSet('form.output_dir', 'build');
});

test('rejects ssr-looking detection on deploy when hybrid origin missing', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'SSR App')
        ->set('repo', 'acme/next-app')
        ->set('branch', 'main')
        ->set('form.runtime_mode', 'static')
        ->set('runtimeModeTouched', true)
        ->set('detectedPlan', [
            'framework' => 'next',
            'start_command' => 'next start',
            'build_command' => 'npm run build',
        ])
        ->call('deploy')
        ->assertNoRedirect();

    expect(Site::query()->count())->toBe(0);
});

test('rejects laravel detection on edge deploy', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'Laravel App')
        ->set('repo', 'acme/laravel-app')
        ->set('branch', 'main')
        ->set('form.runtime_mode', 'static')
        ->set('detectedPlan', [
            'runtime' => 'php',
            'framework' => 'laravel',
            'build_command' => 'composer install',
        ])
        ->assertSee('Not an Edge workload')
        ->assertSee('Create a server')
        ->call('deploy')
        ->assertNoRedirect();

    expect(Site::query()->count())->toBe(0);
});

test('rejects nest api detection on edge deploy', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'API')
        ->set('repo', 'acme/nest-api')
        ->set('branch', 'main')
        ->set('form.runtime_mode', 'static')
        ->set('detectedPlan', [
            'runtime' => 'node',
            'framework' => 'nest',
            'start_command' => 'node dist/main',
        ])
        ->call('deploy')
        ->assertNoRedirect();

    expect(Site::query()->count())->toBe(0);
});

test('shows manual entry when no git accounts linked', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->assertSet('linkedSourceControlAccounts', [])
        ->assertSee('owner/repo or a full GitHub URL')
        ->assertDontSee('Pick from connected account');
});

test('renders repo picker when git accounts linked', function () {
    $user = ownerWithOrg();

    $browser = new class extends SourceControlRepositoryBrowser
    {
        public function __construct() {}

        public function accountsForUser($user): array
        {
            return [['id' => 'acct-1', 'provider' => 'github', 'label' => 'Github - acme']];
        }

        public function repositoriesForAccount($account): array
        {
            return [
                ['url' => 'https://github.com/acme/web', 'label' => 'acme/web', 'branch' => 'main'],
            ];
        }
    };
    app()->instance(SourceControlRepositoryBrowser::class, $browser);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->assertSee('Pick from connected account')
        ->assertSee('Enter manually')
        ->assertSee('Github - acme');
});

test('picker selection populates repo and branch', function () {
    $user = ownerWithOrg();

    $account = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => '12345',
        'label' => 'github:acme',
        'nickname' => 'acme',
        'access_token' => encrypt('t'),
    ]);

    $browser = new class($account->id) extends SourceControlRepositoryBrowser
    {
        public function __construct(public string $accountId) {}

        public function accountsForUser($user): array
        {
            return [['id' => $this->accountId, 'provider' => 'github', 'label' => 'Github - acme']];
        }

        public function repositoriesForAccount($account): array
        {
            return [
                ['url' => 'https://github.com/acme/marketing.git', 'label' => 'acme/marketing', 'branch' => 'develop'],
            ];
        }
    };
    app()->instance(SourceControlRepositoryBrowser::class, $browser);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('repository_selection', 'https://github.com/acme/marketing.git')
        ->assertSet('repo', 'acme/marketing')
        ->assertSet('branch', 'develop');
});

test('auto detects when a complete manual repo is entered', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('repo_source', 'manual')
        ->set('repo', '11ty/eleventy-base-blog')
        ->assertSet('detectedPlan.framework', 'eleventy')
        ->assertSet('form.output_dir', '_site');
});

test('does not auto detect for incomplete manual repo slug', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('repo_source', 'manual')
        ->set('repo', '11ty')
        ->set('branch', 'main')
        ->assertSet('detectedPlan', []);
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
