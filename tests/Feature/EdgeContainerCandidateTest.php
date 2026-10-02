<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeContainerCandidateTest;

use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.fake.enabled' => true, 'edge.fake.allowed_environments' => ['testing'], // host map in the cache
        'edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok',
    ]);
    Sleep::fake();
    Process::fake();
    $org = Organization::factory()->create();
    $this->site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['runtime_mode' => 'container', 'build' => ['framework' => 'laravel'], 'routing' => ['hostname' => 'shop-ab12cd.on-dply.live'], 'live_url' => 'https://shop-ab12cd.on-dply.live']],
    ]);
    EdgeDeployment::query()->create(['site_id' => $this->site->id, 'organization_id' => $org->id, 'status' => EdgeDeployment::STATUS_LIVE]);
    $this->deployment = EdgeDeployment::query()->create(['site_id' => $this->site->id, 'organization_id' => $org->id, 'status' => EdgeDeployment::STATUS_BUILDING]);
    $this->script = EdgeContainerDeployer::candidateScript($this->site);

    // The real project the candidate copies.
    $this->work = sys_get_temp_dir().'/dply-candidate-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->work.'/container-worker/src');
    File::put($this->work.'/container-worker/wrangler.jsonc', json_encode([
        'name' => EdgeContainerDeployer::scriptName($this->site),
        'containers' => [['class_name' => 'App', 'max_instances' => 3]],
        'queues' => ['producers' => [['binding' => 'JOBS', 'queue' => 'jobs']], 'consumers' => [['queue' => 'jobs']]],
        'triggers' => ['crons' => ['* * * * *']],
    ]));
    File::put($this->work.'/container-worker/package.json', json_encode(['name' => EdgeContainerDeployer::scriptName($this->site)]));
    File::put($this->work.'/container-worker/secrets.json', '{}');
});

afterEach(fn () => File::deleteDirectory($this->work));

/** Cloudflare says the candidate's container is up; the candidate answers $status. */
function fakeCloudflare(string $script, int $status, array $release = ['exit' => 0, 'output' => '  2026_09_29_000000_create_cache_table ....... DONE']): void
{
    Http::fake(function (Request $r) use ($script, $status, $release) {
        return match (true) {
            str_ends_with($r->url(), '/containers/applications') && $r->method() === 'GET' => Http::response(['success' => true, 'result' => [['id' => 'cand-app', 'name' => $script.'-app']]]),
            str_ends_with($r->url(), '/containers/applications/cand-app') && $r->method() === 'GET' => Http::response(['success' => true, 'result' => ['version' => 1, 'health' => ['instances' => ['healthy' => 1, 'starting' => 0, 'failed' => 0]]]]),
            str_contains($r->url(), '/containers/applications/cand-app/rollouts') => Http::response(['success' => true, 'result' => [['status' => 'completed', 'progress' => ['percentage' => 100]]]]),
            str_ends_with($r->url(), '/_dply/command') => Http::response($release, ($release['exit'] ?? 0) === 0 ? 200 : 500),
            str_ends_with($r->url(), '/_dply/schedule') => Http::response(['output' => '[2026-09-29] production.ERROR: SQLSTATE[42P01]: relation "cache" does not exist']),
            str_starts_with($r->url(), 'https://shop-ab12cd--next.on-dply.live') => Http::response($status >= 500 ? '<title>500 — Server Error</title>' : 'ok', $status),
            $r->method() === 'DELETE' => Http::response(['success' => true, 'result' => null]),
            default => Http::response(['success' => true, 'result' => []]),
        };
    });
}

function runCandidate(object $test): array
{
    $lines = [];
    $logger = function (string $line) use (&$lines): void {
        $lines[] = $line;
    };
    $site = $test->site;
    $deployment = $test->deployment;
    $work = $test->work;
    $handedOff = false;
    try {
        (function () use ($site, $deployment, $work, $logger, &$handedOff): void {
            $this->deployCandidate($site, $deployment, $work.'/container-worker', $work, 'ns', $logger, 60, true, $handedOff);
        })->call(new EdgeContainerDeployer);
    } finally {
        $test->handedOff = $handedOff;
    }

    return $lines;
}

function endHandoff(object $test, bool $productionOk): string
{
    $lines = [];
    $logger = function (string $line) use (&$lines): void {
        $lines[] = $line;
    };
    $site = $test->site;
    $deployment = $test->deployment;
    (function () use ($site, $deployment, $productionOk, $logger): void {
        $this->endHandoff($site, $deployment, 'ns', $productionOk, $logger);
    })->call(new EdgeContainerDeployer);

    return implode('', $lines);
}

/** The script a hostname routes to in the fake host map. */
function routedTo(string $hostname): ?string
{
    return Cache::get('edge:fake:host-map', [])[$hostname]['ssr_worker_script'] ?? null;
}

test('the candidate is its own script, without production’s queue consumer or cron trigger', function () {
    File::copyDirectory($this->work.'/container-worker', $this->work.'/copy');
    EdgeContainerDeployer::asCandidate($this->work.'/copy', $this->script);
    $config = json_decode(File::get($this->work.'/copy/wrangler.jsonc'), true);

    expect($config['name'])->toBe($this->script)
        ->and($config)->not->toHaveKey('triggers')
        ->and($config['queues'])->toBe(['producers' => [['binding' => 'JOBS', 'queue' => 'jobs']]])
        // Production's ceiling: visitors are routed to the copy while production updates.
        ->and($config['containers'][0]['max_instances'])->toBe(3)
        ->and(EdgeContainerDeployer::candidateHost($this->site))->toBe('shop-ab12cd--next.on-dply.live')
        ->and(EdgeContainerDeployer::checksCandidate($this->site))->toBeTrue();
});

test('a healthy candidate migrates, passes, and takes the visitors while production updates', function () {
    fakeCloudflare($this->script, 200);

    $log = implode('', runCandidate($this));

    expect($log)->toContain('Checking the new version on its own')
        ->toContain('Running migrations')
        ->toContain('create_cache_table')
        ->toContain('Sending visitors to it while production updates.');
    Process::assertRan(fn ($process) => in_array($this->work.'/container-next', (array) $process->command, true));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://shop-ab12cd--next.on-dply.live/_dply/command' && $r['command'] === 'release');
    // Kept running: it is serving production's hostname now.
    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    expect($this->handedOff)->toBeTrue()
        ->and(routedTo('shop-ab12cd.on-dply.live'))->toBe($this->script)
        ->and(File::exists($this->work.'/container-next'))->toBeFalse(); // its secrets.json copy is gone
});

test('when production is up again the visitors go back to it, then the copy is removed', function () {
    fakeCloudflare($this->script, 200);
    runCandidate($this);

    \Illuminate\Support\Facades\Queue::fake();
    $log = endHandoff($this, productionOk: true);

    expect($log)->toContain('Sending visitors back to it.')
        ->and(routedTo('shop-ab12cd.on-dply.live'))->toBe(EdgeContainerDeployer::scriptName($this->site));
    // The deploy doesn't wait for the copy to drain: a delayed job takes it down.
    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    $job = null;
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Modules\Edge\Jobs\RemoveEdgeCheckCopyJob::class, function ($pushed) use (&$job): bool {
        $job = $pushed;

        return $pushed->delay !== null;
    });

    $job->handle(new EdgeContainerDeployer);
    expect(Cache::get('edge:fake:host-map', []))->not->toHaveKey('shop-ab12cd--live.on-dply.live');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/scripts/'.$this->script));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/containers/applications/cand-app'));
});

test('a newer deploy in flight keeps the check copy it now owns', function () {
    fakeCloudflare($this->script, 200);
    $older = \App\Models\EdgeDeployment::query()->create(['site_id' => $this->site->id, 'organization_id' => $this->site->organization_id, 'status' => \App\Models\EdgeDeployment::STATUS_SUPERSEDED]);

    (new \App\Modules\Edge\Jobs\RemoveEdgeCheckCopyJob((string) $this->site->id, (string) $older->id))->handle(new EdgeContainerDeployer);

    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
});

test('when production fails to come up the last live deployment gets its routes back, then the copy is removed', function () {
    fakeCloudflare($this->script, 200);
    runCandidate($this);

    $log = endHandoff($this, productionOk: false);

    expect($log)->toContain('Sending visitors back to the last live deployment.')
        ->and(routedTo('shop-ab12cd.on-dply.live'))->not->toBe($this->script);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/scripts/'.$this->script));
});

test('a candidate that answers 500 stops the deploy with its error, and production is never touched', function () {
    fakeCloudflare($this->script, 500);

    $lines = [];
    expect(function () use (&$lines) {
        $lines = runCandidate($this);
    })->toThrow(RuntimeException::class, 'Production still runs the previous version.');
    expect($this->handedOff)->toBeFalse();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://shop-ab12cd--next.on-dply.live/_dply/schedule');
    Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'https://shop-ab12cd.on-dply.live'));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/scripts/'.$this->script));
});

test('failed migrations stop the deploy before the check', function () {
    fakeCloudflare($this->script, 200, ['exit' => 1, 'error' => 'SQLSTATE[42P07]: Duplicate table: relation "users" already exists']);

    expect(fn () => runCandidate($this))->toThrow(RuntimeException::class, 'migrations failed: SQLSTATE[42P07]');
    // The root was asked once, by the address wait: no health check after the failure. The copy was still removed.
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->url() === 'https://shop-ab12cd--next.on-dply.live')->count())->toBe(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/scripts/'.$this->script));
});

test('the check copy hands production the image it pushed, as a full registry reference', function () {
    $digest = '  8dd2ae9a: digest: sha256:'.str_repeat('a', 64).' size: 7844';
    expect(EdgeContainerDeployer::pushedImage('dply-ctr-x-next-app', "push\n{$digest}\n"))
        ->toBe('registry.cloudflare.com/acct/dply-ctr-x-next-app:8dd2ae9a')
        // Nothing pushed (wrangler skipped it): production builds as before.
        ->and(EdgeContainerDeployer::pushedImage('dply-ctr-x-next-app', 'Image already exists remotely, skipping push'))->toBeNull();

    File::put($this->work.'/container-worker/wrangler.jsonc', json_encode(['containers' => [['class_name' => 'App', 'image' => '/w/Dockerfile.dply']]]));
    expect(EdgeContainerDeployer::setContainerImage($this->work.'/container-worker', 'registry.cloudflare.com/acct/r:t'))->toBe('/w/Dockerfile.dply')
        ->and(json_decode(File::get($this->work.'/container-worker/wrangler.jsonc'), true)['containers'][0]['image'])->toBe('registry.cloudflare.com/acct/r:t');

    // Through the candidate: the push line in wrangler's output becomes the image.
    Process::fake(['*' => Process::result(output: "Pushing\n{$digest}\n")]);
    fakeCloudflare($this->script, 200);
    $image = null;
    $handedOff = false;
    $site = $this->site;
    $deployment = $this->deployment;
    $work = $this->work;
    (function () use ($site, $deployment, $work, &$handedOff, &$image): void {
        $this->deployCandidate($site, $deployment, $work.'/container-worker', $work, 'ns', static function (): void {}, 60, false, $handedOff, $image);
    })->call(new EdgeContainerDeployer);
    expect($image)->toBe('registry.cloudflare.com/acct/'.$this->script.'-app:8dd2ae9a');
});

test('the rollout wait can stop at enough healthy instances, not all of them', function () {
    $percent = 100;
    Http::fake(function (Request $r) use (&$percent) {
        return match (true) {
            str_ends_with($r->url(), '/containers/applications') => Http::response(['success' => true, 'result' => [['id' => 'a1', 'name' => 'app-x']]]),
            str_ends_with($r->url(), '/containers/applications/a1/rollouts') => Http::response(['success' => true, 'result' => [['status' => 'progressing', 'progress' => ['percentage' => $percent]]]]),
            default => Http::response(['success' => true, 'result' => ['version' => 2, 'health' => ['instances' => ['healthy' => 2, 'starting' => 3, 'failed' => 0]]]]),
        };
    });
    $await = fn (int $timeout) => app(\App\Modules\Edge\Services\Containers\EdgeContainerRollout::class)->await($this->site, static function (): void {}, timeoutSeconds: $timeout, pollSeconds: 0, application: 'app-x', readyAt: 2);

    expect($await(10)['ok'])->toBeTrue();

    // Mid-rollout, healthy instances may still be the old image: keep waiting.
    $percent = 40;
    expect($await(1)['ok'])->toBeFalse();
});
