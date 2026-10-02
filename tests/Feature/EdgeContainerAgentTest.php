<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeContainerAgentTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerAgent;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\Containers\EdgeContainerDockerfile;
use App\Modules\Edge\Services\Containers\EdgeDockerfileCommand;
use App\Modules\Edge\Services\Containers\EdgeReleaseBundle;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->binary = tempnam(sys_get_temp_dir(), 'agent');
    File::put($this->binary, 'ELF');
    config(['edge.build.containers.agent_binary' => $this->binary]);
    $org = Organization::factory()->create();
    $this->site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['runtime_mode' => 'container', 'live_url' => 'https://shop.on-dply.live']],
    ]);
    $this->checkout = sys_get_temp_dir().'/dply-agent-test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->checkout);
    File::put($this->checkout.'/package.json', json_encode(['name' => 'shop', 'scripts' => ['start' => 'node server.js']]));
});

afterEach(fn () => File::deleteDirectory($this->checkout));

test('a generated image gets the agent as entrypoint, the same CMD after it, and the binary in its context', function () {
    $image = EdgeContainerDockerfile::prepare($this->checkout);
    File::put($this->checkout.'/.dockerignore', "*\n!package.json\n");
    $cmd = EdgeContainerAgent::lastCmd(File::get($image['path']));

    expect(EdgeContainerAgent::wrap($this->site, $this->checkout, $image))->toBeNull();

    $lines = array_slice(explode("\n", rtrim(File::get($image['path']))), -3);
    expect($lines)->toBe([
        'COPY --chmod=0755 .dply-agent /usr/local/bin/dply-agent',
        'ENTRYPOINT ["/usr/local/bin/dply-agent", "--"]',
        $cmd,
    ])->and(File::get($this->checkout.'/.dply-agent'))->toBe('ELF')
        ->and(File::get($this->checkout.'/.dockerignore'))->toEndWith("!.dply-agent\n");
});

test('anything in the way deploys the image unchanged and says why', function (callable $arrange, string $why) {
    $image = EdgeContainerDockerfile::prepare($this->checkout);
    $image = $arrange($this, $image);
    $before = File::get($image['path']);

    expect(EdgeContainerAgent::wrap($this->site->fresh(), $this->checkout, $image))->toContain($why)
        ->and(File::get($image['path']))->toBe($before)
        ->and(is_file($this->checkout.'/.dply-agent'))->toBeFalse();
})->with([
    'flag off' => [function ($test, $image) {
        Feature::for($test->site->organization)->deactivate(EdgeContainerConnections::flag('agent'));

        return $image;
    }, 'not turned on'],
    'own Dockerfile from a private image' => [function ($test, $image) {
        Http::fake(['*' => Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://auth.example.test/token",service="example"']), 'auth.example.test/*' => Http::response('', 401)]);
        File::put($image['path'], "FROM registry.example.test/private/base:1\nCOPY . /app\n");

        return ['generated' => false] + $image;
    }, 'could not tell what this Dockerfile runs'],
    'port clash' => [fn ($test, $image) => ['port' => EdgeContainerAgent::PORT] + $image, 'agent\'s port'],
    'no binary' => [function ($test, $image) {
        config(['edge.build.containers.agent_binary' => '/nope/dply-agent']);

        return $image;
    }, 'no agent binary'],
    'turned off for the app' => [function ($test, $image) {
        $test->site->mergeEdgeMeta(['container_agent' => false]);
        $test->site->save();

        return $image;
    }, 'turned off for this app'],
]);

test('a release bundle keeps the agent in the runtime image, entrypoint before the CMD', function () {
    $dockerfile = implode("\n", [
        'FROM php:8.4-fpm AS app',
        'WORKDIR /app',
        'COPY composer.json composer.lock ./',
        'RUN composer install',
        'COPY . .',
        'ENV APP_ENV=production',
        'CMD ["sh", "-c", "exec php-fpm"]',
        ...EdgeContainerAgent::lines('CMD ["sh", "-c", "exec php-fpm"]'),
    ]);

    $runtime = explode("\n", rtrim(EdgeReleaseBundle::split($dockerfile)['runtime']));

    $entry = array_search('ENTRYPOINT ["/usr/local/bin/dply-agent", "--"]', $runtime, true);
    expect($runtime)->toContain('COPY --chmod=0755 .dply-agent /usr/local/bin/dply-agent')
        ->and($entry)->not->toBeFalse()
        ->and(str_starts_with(end($runtime), 'CMD '))->toBeTrue()
        ->and($entry)->toBeLessThan(count($runtime) - 1);
});

test('the worker checks the token before any agent request and reaches the agent port', function () {
    $dir = sys_get_temp_dir().'/dply-agent-worker-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $this->site, '/x/Dockerfile.dply', 8080, []);
    $worker = File::get($dir.'/src/index.js');
    File::deleteDirectory($dir);

    $tokenCheck = strpos($worker, "if (request.headers.get('x-dply-queue-token') !== env.DPLY_QUEUE_TOKEN)");
    $route = strpos($worker, "if (url.pathname.startsWith('/_dply/agent/'))");
    expect($tokenCheck)->not->toBeFalse()
        ->and($route)->toBeGreaterThan($tokenCheck)
        ->and($worker)->toContain('const AGENT_PORT = '.EdgeContainerAgent::PORT)
        ->and($worker)->toContain('this.container.getTcpPort(AGENT_PORT)')
        ->and($worker)->toContain("request.headers.get('x-dply-agent') === '1'");
});

test('exec streams each line to the caller and returns the result', function () {
    Http::fake(['shop.on-dply.live/_dply/agent/exec*' => Http::response("{\"out\":\"hello\\n\"}\n{\"err\":\"warn\\n\"}\n{\"exit\":0,\"seconds\":0.2,\"truncated\":false,\"timed_out\":false}\n")]);
    $seen = [];

    $result = EdgeContainerAgent::exec($this->site, 'echo hello', function (array $line) use (&$seen): void {
        $seen[] = $line;
    }, 'jobs');

    expect($result['exit'])->toBe(0)
        ->and($seen[0])->toBe(['out' => "hello\n"])
        ->and($seen[1])->toBe(['err' => "warn\n"]);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/_dply/agent/exec?target=jobs')
        && $r->hasHeader('x-dply-queue-token', EdgeContainerDeployer::queueToken($this->site))
        && $r['command'] === 'echo hello');
});

test('without wake a sleeping container answers asleep and nothing runs', function () {
    Http::fake(['shop.on-dply.live/*' => Http::response(['asleep' => true], 409)]);

    expect(EdgeContainerAgent::exec($this->site, 'ps', fn () => null, wake: false))->toBe(['asleep' => true]);
    Http::assertSent(fn ($r) => $r->hasHeader('x-dply-no-wake', '1'));
});

test('an app\'s own Dockerfile runs its own command under the agent, read from the Dockerfile', function () {
    File::put($this->checkout.'/Dockerfile', "ARG NODE=22\nFROM node:\${NODE}-slim AS build\nRUN npm ci\nFROM node:\${NODE}-slim\nCOPY --from=build /app /app\nENTRYPOINT [\"docker-entrypoint.sh\"]\nCMD [\"node\", \"server.js\"]\n");
    $image = EdgeContainerDockerfile::prepare($this->checkout);
    Http::fake();

    expect(EdgeContainerAgent::wrap($this->site, $this->checkout, $image))->toBeNull();

    expect(array_slice(explode("\n", rtrim(File::get($image['path']))), -2))->toBe([
        'ENTRYPOINT ["/usr/local/bin/dply-agent","--","docker-entrypoint.sh"]',
        'CMD ["node","server.js"]',
    ]);
    Http::assertNothingSent(); // the final stage set ENTRYPOINT: no registry lookup
});

test('Docker\'s ENTRYPOINT and CMD rules', function (string $dockerfile, array $expected) {
    expect(EdgeDockerfileCommand::resolve($dockerfile))->toBe($expected);
})->with([
    'ENTRYPOINT clears an inherited CMD' => ["FROM scratch AS a\nCMD [\"x\"]\nFROM a\nENTRYPOINT [\"run\"]\n", ['entrypoint' => ['run'], 'cmd' => []]],
    'CMD earlier in the same stage survives' => ["FROM scratch\nCMD [\"x\"]\nENTRYPOINT [\"run\"]\n", ['entrypoint' => ['run'], 'cmd' => ['x']]],
    'a shell-form ENTRYPOINT ignores CMD' => ["FROM scratch\nENTRYPOINT exec php-fpm\nCMD [\"x\"]\n", ['entrypoint' => ['/bin/sh', '-c', 'exec php-fpm'], 'cmd' => []]],
    'a stage inherits an earlier one' => ["FROM scratch AS base\nENTRYPOINT [\"tini\", \"--\"]\nCMD [\"app\"]\nFROM base\nRUN true\n", ['entrypoint' => ['tini', '--'], 'cmd' => ['app']]],
    'shell-form CMD' => ["FROM scratch\nENTRYPOINT [\"e\"]\nCMD npm \\\n  start\n", ['entrypoint' => ['e'], 'cmd' => ['/bin/sh', '-c', 'npm    start']]],
]);

test('the base image\'s entrypoint comes from its registry when the Dockerfile leaves it', function () {
    Http::fake([
        'auth.docker.io/token*' => Http::response(['token' => 'anon']),
        'registry-1.docker.io/v2/library/php/manifests/8.4-fpm' => fn ($r) => $r->hasHeader('Authorization')
            ? Http::response(['manifests' => [['digest' => 'sha256:arm', 'platform' => ['os' => 'linux', 'architecture' => 'arm64']], ['digest' => 'sha256:amd', 'platform' => ['os' => 'linux', 'architecture' => 'amd64']]]])
            : Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://auth.docker.io/token",service="registry.docker.io",scope="repository:library/php:pull"']),
        'registry-1.docker.io/v2/library/php/manifests/sha256:amd' => Http::response(['config' => ['digest' => 'sha256:cfg']]),
        'registry-1.docker.io/v2/library/php/blobs/sha256:cfg' => Http::response(['config' => ['Entrypoint' => ['docker-php-entrypoint'], 'Cmd' => ['php-fpm']]]),
    ]);

    expect(EdgeDockerfileCommand::resolve("FROM php:8.4-fpm\nCOPY . /var/www\n"))
        ->toBe(['entrypoint' => ['docker-php-entrypoint'], 'cmd' => ['php-fpm']]);
});

test('image references parse like Docker\'s', function (string $ref, array $parts) {
    expect(EdgeDockerfileCommand::parseRef($ref))->toBe($parts);
})->with([
    ['node', ['registry-1.docker.io', 'library/node', 'latest']],
    ['bitnami/redis:7', ['registry-1.docker.io', 'bitnami/redis', '7']],
    ['ghcr.io/acme/app:1.2', ['ghcr.io', 'acme/app', '1.2']],
    ['localhost:5000/app@sha256:abc', ['localhost:5000', 'app', 'sha256:abc']],
]);
