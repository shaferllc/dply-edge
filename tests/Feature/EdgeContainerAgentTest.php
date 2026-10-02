<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeContainerAgentTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerAgent;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\Containers\EdgeContainerDockerfile;
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
    $before = File::get($image['path']);
    $image = $arrange($this, $image);

    expect(EdgeContainerAgent::wrap($this->site->fresh(), $this->checkout, $image))->toContain($why)
        ->and(File::get($image['path']))->toBe($before)
        ->and(is_file($this->checkout.'/.dply-agent'))->toBeFalse();
})->with([
    'flag off' => [function ($test, $image) {
        Feature::for($test->site->organization)->deactivate(EdgeContainerConnections::flag('agent'));

        return $image;
    }, 'not turned on'],
    'own Dockerfile' => [fn ($test, $image) => ['generated' => false] + $image, 'its own Dockerfile'],
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
