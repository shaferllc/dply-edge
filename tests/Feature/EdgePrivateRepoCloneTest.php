<?php

declare(strict_types=1);

namespace Tests\Feature\EdgePrivateRepoCloneTest;

use App\Jobs\DetectRepositoryRuntimeJob;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use App\Modules\Edge\Services\EdgeBuildRunner;
use App\Modules\Edge\Services\EdgeRepoCloner;
use App\Modules\Edge\Services\RuntimeDetection\GitCloner;
use App\Modules\SourceControl\Services\GitCloneAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

uses(RefreshDatabase::class);

const TOKEN = 'gho_supersecrettoken123';

function privateSite(User $user, string $provider = 'github', string $token = TOKEN, array $account = []): Site
{
    $social = SocialAccount::create(array_merge([
        'user_id' => $user->id,
        'provider' => $provider,
        'provider_id' => $provider.'-1',
        'access_token' => $token,
    ], $account));
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'edge_backend' => 'dply_edge',
        'meta' => [
            'repository' => ['git_source_control_account_id' => $social->id],
            'edge' => ['source' => ['repo' => 'acme/private', 'branch' => 'main']],
        ],
    ]);
}

function basicAuthToken(array $env): string
{
    return explode(':', (string) base64_decode(substr($env['GIT_CONFIG_VALUE_0'], strlen('Authorization: Basic '))), 2)[1];
}

test('a site clones with its linked account as a host-scoped git header', function () {
    $site = privateSite(User::factory()->create());

    $env = app(GitCloneAuth::class)->envForSite($site, 'https://github.com/acme/private.git');

    expect($env['GIT_CONFIG_KEY_0'])->toBe('http.https://github.com/.extraheader')
        ->and(basicAuthToken($env))->toBe(TOKEN)
        ->and($env['GIT_TERMINAL_PROMPT'])->toBe('0')
        // Another host never gets this account's token.
        ->and(app(GitCloneAuth::class)->envForSite($site, 'https://gitlab.com/acme/private.git'))->toBe([]);
});

test('the build runner clones with the site credentials and the token never reaches argv or the log', function () {
    config(['edge.fake.enabled' => false, 'edge.build.git_cache_enabled' => false]);
    // git echoes nothing secret, but if it ever did the log must still be clean.
    Process::fake(fn (PendingProcess $p) => array_slice((array) $p->command, 0, 2) === ['git', 'clone']
        ? Process::result(errorOutput: 'Cloning into src... remote said '.TOKEN)
        : Process::result());
    $site = privateSite(User::factory()->create());
    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $site->organization_id,
        'status' => EdgeDeployment::STATUS_BUILDING,
        'git_branch' => 'main',
        'storage_prefix' => 'edge/test/'.$site->id,
    ]);

    try {
        app(EdgeBuildRunner::class)->build($deployment, 'https://github.com/acme/private.git', 'main', 'npm run build', 'dist');
    } catch (\Throwable) {
        // The fake stops being a real build after the clone; only the clone matters here.
    }

    Process::assertRan(fn (PendingProcess $p): bool => str_contains(implode(' ', (array) $p->command), 'git clone')
        && basicAuthToken($p->environment) === TOKEN);
    Process::assertDidntRun(fn (PendingProcess $p): bool => str_contains(implode(' ', (array) $p->command), TOKEN));

    $log = (string) File::get(rtrim(EdgeBuildRunner::buildRoot(), '/').'/dply-edge-build-'.$deployment->id.'/build.log');
    expect($log)->toContain('remote said ***')->not->toContain(TOKEN);
    File::deleteDirectory(rtrim(EdgeBuildRunner::buildRoot(), '/').'/dply-edge-build-'.$deployment->id);
});

test('the mirror cache keeps the plain URL as its remote and fetches with the header', function () {
    $cacheDir = sys_get_temp_dir().'/dply-git-cache-test-'.bin2hex(random_bytes(4));
    config(['edge.build.git_cache_enabled' => true, 'edge.build.git_cache_dir' => $cacheDir]);
    Process::fake();
    $env = app(GitCloneAuth::class)->envFor(SocialAccount::make(['provider' => 'github', 'access_token' => TOKEN]), 'https://github.com/acme/private.git');

    app(EdgeRepoCloner::class)->clone('https://github.com/acme/private.git', 'main', sys_get_temp_dir().'/dply-clone-test-'.bin2hex(random_bytes(4)), gitEnv: $env);

    Process::assertRan(fn (PendingProcess $p): bool => array_slice((array) $p->command, 0, 3) === ['git', 'clone', '--mirror']
        && in_array('https://github.com/acme/private.git', (array) $p->command, true)
        && basicAuthToken($p->environment) === TOKEN);
    Process::assertDidntRun(fn (PendingProcess $p): bool => str_contains(implode(' ', (array) $p->command), TOKEN));
    File::deleteDirectory($cacheDir);
});

test('an auth failure says to reconnect the account, without the token', function () {
    config(['edge.build.git_cache_enabled' => false]);
    Process::fake(['*' => Process::result(errorOutput: "fatal: Authentication failed for 'https://github.com/acme/private.git/' ".TOKEN, exitCode: 128)]);
    $env = app(GitCloneAuth::class)->envFor(SocialAccount::make(['provider' => 'github', 'access_token' => TOKEN]), 'https://github.com/acme/private.git');

    try {
        app(EdgeRepoCloner::class)->clone('https://github.com/acme/private.git', 'main', sys_get_temp_dir().'/dply-clone-test-'.bin2hex(random_bytes(4)), gitEnv: $env);
        $this->fail('clone should have failed');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Reconnect it under Source control')->not->toContain(TOKEN);
    }
});

test('an expired GitLab token is refreshed before the clone and saved', function () {
    config(['services.gitlab.client_id' => 'cid', 'services.gitlab.client_secret' => 'secret']);
    Http::fake(['gitlab.com/oauth/token' => Http::response(['access_token' => 'glpat-fresh-access', 'refresh_token' => 'new-refresh', 'expires_in' => 7200])]);
    $site = privateSite(User::factory()->create(), 'gitlab', 'glpat-stale-access', ['refresh_token' => 'old-refresh', 'expires_at' => now()->subMinute()]);

    $env = app(GitCloneAuth::class)->envForSite($site, 'https://gitlab.com/acme/private.git');

    expect(basicAuthToken($env))->toBe('glpat-fresh-access');
    $account = SocialAccount::query()->where('provider', 'gitlab')->firstOrFail();
    expect($account->accessToken())->toBe('glpat-fresh-access')
        ->and($account->refresh_token)->toBe('new-refresh')
        ->and($account->expires_at->isFuture())->toBeTrue();
    Http::assertSent(fn ($request) => $request['refresh_token'] === 'old-refresh' && $request['grant_type'] === 'refresh_token');
});

test('create-page detection clones a private repo with the chosen account, without queueing the token', function () {
    $user = User::factory()->create();
    $account = SocialAccount::create(['user_id' => $user->id, 'provider' => 'gitlab', 'provider_id' => 'gl-1', 'access_token' => 'glpat-detect-token']);
    $cloner = new class implements GitCloner
    {
        public array $env = [];

        public function shallowClone(string $url, string $branch, string $destination, array $env = []): void
        {
            $this->env = $env;
            File::ensureDirectoryExists($destination);
            File::put($destination.'/index.html', '<h1>hi</h1>');
        }
    };
    app()->instance(GitCloner::class, $cloner);

    $job = new DetectRepositoryRuntimeJob('detect-key', 'https://gitlab.com/acme/private.git', 'main', $user->id, $account->id);
    expect(serialize($job))->not->toContain('glpat-detect-token');
    app()->call([$job, 'handle']);

    expect(basicAuthToken($cloner->env))->toBe('glpat-detect-token')
        ->and(Cache::get('detect-key')['state'])->toBe('done');
});

test('with real git, the checkout config never holds the token', function () {
    config(['edge.build.git_cache_enabled' => false]);
    $root = sys_get_temp_dir().'/dply-real-clone-'.bin2hex(random_bytes(4));
    $run = fn (array $cmd, ?string $cwd = null) => \Symfony\Component\Process\Process::fromShellCommandline(implode(' ', array_map('escapeshellarg', $cmd)), $cwd)->mustRun();
    $run(['git', 'init', '-q', '-b', 'main', $root.'/work']);
    File::put($root.'/work/index.html', '<h1>hi</h1>');
    $run(['git', 'add', '.'], $root.'/work');
    $run(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'site'], $root.'/work');
    $env = ['GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'http.https://github.com/.extraheader', 'GIT_CONFIG_VALUE_0' => 'Authorization: Basic '.base64_encode('x-access-token:'.TOKEN)];

    app(EdgeRepoCloner::class)->clone('file://'.$root.'/work', 'main', $root.'/checkout', gitEnv: $env);

    expect(File::exists($root.'/checkout/index.html'))->toBeTrue()
        ->and(File::get($root.'/checkout/.git/config'))->not->toContain(TOKEN)->not->toContain(base64_encode('x-access-token:'.TOKEN));
    File::deleteDirectory($root);
});

test('a preview clones with the account pinned on its parent app', function () {
    $user = User::factory()->create();
    SocialAccount::create(['user_id' => $user->id, 'provider' => 'github', 'provider_id' => 'gh-personal', 'access_token' => 'gho_personal_account_token']);
    $parent = privateSite($user, token: 'gho_work_account_pinned');
    $preview = Site::factory()->create([
        'organization_id' => $parent->organization_id,
        'server_id' => $parent->server_id,
        'user_id' => $user->id,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['preview_parent_site_id' => $parent->id]],
    ]);

    expect(basicAuthToken(app(GitCloneAuth::class)->envForSite($preview, 'https://github.com/acme/private.git')))->toBe('gho_work_account_pinned');
});
