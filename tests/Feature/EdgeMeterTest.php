<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeMeterTest;

use App\Models\EdgePlatformUsage;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Services\EdgeMeteredUsageCost;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\EdgePlatformUsageCollector;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeMeter;
use App\Modules\Edge\Support\EdgeWorkerEntryWrapper;
use App\Notifications\MeteredServicesCapNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['dply.edge.usage_billing.margin_percent' => 0, 'edge.skip_card_check' => false]);
    Cache::flush();
});

/** A paid org's live app with AI and vector search attached. */
function meteredSite(): Site
{
    $org = Organization::factory()->create(['comped_until' => now()->addYear()]);
    $site = Site::factory()->create(['organization_id' => $org->id, 'edge_backend' => 'dply_edge']);
    $site->mergeEdgeMeta(['connections' => [
        ['kind' => 'ai', 'name' => 'AI', 'host' => 'dply.app.ai.internal', 'target' => ''],
        ['kind' => 'vectors', 'name' => 'DOCS', 'host' => 'dply.app.docs.internal', 'target' => 'dply-'.strtolower((string) $org->id).'-docs'],
    ]]);
    $site->save();

    return $site->refresh();
}

/** @param  array<string, mixed>  $usage */
function report(Site $site, array $usage, ?string $key = null)
{
    $body = json_encode(['at' => now()->toIso8601String(), 'usage' => $usage]);

    return test()->call('POST', '/hooks/edge/'.$site->id.'/meter', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_DPLY_METER_SIGNATURE' => hash_hmac('sha256', $body, $key ?? EdgeMeter::key($site)),
    ], $body);
}

test('neurons come from the published per-model rates, unknown models from a high rate with a floor', function () {
    // 1M in + 1M out on Llama 3.1 8B: 25,608 + 75,147 neurons.
    expect(EdgeMeter::neurons('@cf/meta/llama-3.1-8b-instruct', 1_000_000, 1_000_000))->toBe(100_755.0)
        ->and(EdgeMeter::neurons('@cf/baai/bge-base-en-v1.5', 1_000_000, 0))->toBe(6_058.0)
        ->and(EdgeMeter::neurons('@cf/someone/new-model', 10, 10))->toBe(100.0)
        ->and(EdgeMeter::neurons('@cf/someone/new-model', 1_000_000, 0))->toBe(50_000.0);
});

test('cost: neurons, browser hours and Vectorize (queried + stored) × dims at list price', function () {
    // 1M neurons = $11; 10 browser-hours = $0.90; 100M queried dims + 20M stored:
    // (100M + 20M)/1M × $0.01 = $1.20 queried, 20M/100M × $0.05 = $0.01 stored.
    $mc = EdgeMeteredUsageCost::millicents(['ai_neurons' => 1_000_000, 'browser_ms' => 36_000_000, 'vector_query_dims' => 100_000_000, 'vector_stored_dims' => 20_000_000]);

    expect(round($mc['ai']))->toBe(1_100_000.0)
        ->and(round($mc['browser']))->toBe(90_000.0)
        ->and(round($mc['vectors']))->toBe(121_000.0);

    config(['dply.edge.usage_billing.margin_percent' => 30]);
    expect(round(EdgeMeteredUsageCost::millicents(['ai_neurons' => 1_000])['ai'] * 1.3))->toBe(1_430.0);
});

test('a signed report is attributed to its app and org, and lands on the bill', function () {
    $site = meteredSite();

    report($site, ['ai' => [['model' => '@cf/meta/llama-3.1-8b-instruct', 'in' => 1_000_000, 'out' => 1_000_000]], 'browser_ms' => 3_600_000, 'vector_dims' => 768])
        ->assertOk()->assertJson(['ok' => true]);
    report($site, ['vector_dims' => 768])->assertOk();

    $row = EdgePlatformUsage::query()->where('resource', 'meter:'.$site->id)->sole();
    expect($row->organization_id)->toBe($site->organization_id)
        ->and($row->site_id)->toBe($site->id)
        ->and($row->ai_neurons)->toBe(100_755.0)
        ->and($row->browser_ms)->toBe(3_600_000)
        ->and($row->vector_query_dims)->toBe(1_536);

    $org = $site->organization;
    $org->forceFill(['comped_until' => null])->save();
    $state = app(OrganizationBillingStateComputer::class)->computeForPeriod($org, now()->startOfMonth(), now()->endOfMonth(), 'pro');
    // $1.108305 AI + $0.09 browser + ~$0.00 vectors.
    expect($state->usageLines()['ai'] ?? null)->toBe(120);
});

test('a bad signature, a stale body or another app\'s key is refused', function () {
    $site = meteredSite();
    $other = meteredSite();

    report($site, ['browser_ms' => 1000], 'nope')->assertStatus(401);
    report($site, ['browser_ms' => 1000], EdgeMeter::key($other))->assertStatus(401);

    $body = json_encode(['at' => now()->subHour()->toIso8601String(), 'usage' => ['browser_ms' => 1000]]);
    $this->call('POST', '/hooks/edge/'.$site->id.'/meter', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DPLY_METER_SIGNATURE' => hash_hmac('sha256', $body, EdgeMeter::key($site))], $body)->assertStatus(422);

    expect(EdgePlatformUsage::query()->exists())->toBeFalse();
});

test('over the cap every service is refused with 429 and a message', function () {
    $site = meteredSite();
    $site->organization->forceFill(['metered_cap_cents' => 100])->save();

    report($site, [])->assertOk()->assertExactJson(['ok' => true, 'deny' => []]);
    // 12 browser-hours = $1.08, past the $1 cap.
    $response = report($site, ['browser_ms' => 12 * 3_600_000]);

    $response->assertOk();
    foreach (EdgeMeter::SERVICES as $service) {
        expect($response->json("deny.{$service}.status"))->toBe(429)
            ->and($response->json("deny.{$service}.message"))->toContain('$1.00 monthly limit');
    }
});

test('a cap of 0 turns the org cap off, but the platform ceiling still applies', function () {
    $site = meteredSite();
    $org = $site->organization;
    config(['edge.metered_services.ceiling_cents' => 100]);
    $org->forceFill(['metered_cap_cents' => 0])->save();

    expect(EdgeMeter::effectiveCapCents($org))->toBe(100);
    report($site, ['browser_ms' => 10 * 3_600_000])->assertOk()->assertJsonPath('deny', []); // $0.90
    report($site, ['browser_ms' => 2 * 3_600_000])->assertOk()->assertJsonPath('deny.browser.status', 429); // $1.08
});

test('the kill switch refuses one service everywhere', function () {
    $site = meteredSite();
    config(['edge.metered_services.enabled.browser' => false]);

    $response = report($site, []);

    expect($response->json('deny.browser.status'))->toBe(503)
        ->and($response->json('deny.ai'))->toBeNull()
        ->and(EdgeMeter::refusal($site->organization, 'browser')['message'])->toContain('turned off');
});

test('a trial org is refused: AI, browser and vector search are paid-only', function () {
    $site = meteredSite();
    $site->organization->forceFill(['comped_until' => null, 'trial_ends_at' => now()->addDays(3)])->save();

    expect(EdgeMeter::refusal($site->organization->refresh(), 'ai')['status'])->toBe(403);
});

test('owners are emailed once at 80% and once at 100% of the cap', function () {
    Notification::fake();
    $site = meteredSite();
    $org = $site->organization;
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $org->forceFill(['metered_cap_cents' => 100])->save();

    report($site, ['browser_ms' => 9 * 3_600_000])->assertOk(); // $0.81: 81%
    report($site, ['browser_ms' => 1])->assertOk();
    Notification::assertSentToTimes($owner, MeteredServicesCapNotice::class, 1);

    report($site, ['browser_ms' => 3_600_000])->assertOk(); // $0.90
    report($site, ['browser_ms' => 2 * 3_600_000])->assertOk(); // $1.08: 100%
    report($site, ['browser_ms' => 3_600_000])->assertOk();
    Notification::assertSentToTimes($owner, MeteredServicesCapNotice::class, 2);
    expect($org->refresh()->metered_cap_alerts['pct'])->toBe(100);
});

test('a Worker app gets the raw bindings renamed, the meter env, and the proxy in its entry', function () {
    $site = meteredSite();

    $names = array_column(EdgeContainerConnections::workerBindings($site), 'name');
    expect($names)->toContain('DPLY_RAW_AI', 'DPLY_RAW_DOCS', 'DPLY_METER_URL', 'DPLY_METER_KEY')
        ->not->toContain('AI', 'DOCS');

    [, $modules] = EdgeWorkerEntryWrapper::wrap($site, 'worker.js', ['worker.js' => '']);
    expect($modules['dply-entry.js'])->toContain('const METERED = {"AI":"ai","DOCS":"vectors"};')
        ->toContain("import * as dplyWorkers from 'cloudflare:workers';")
        ->toContain('function dplyMetered(');
});

test('the proxy JS meters, refuses, fails closed and passes the rest through (node)', function () {
    $node = trim((string) shell_exec('command -v node'));
    if ($node === '') {
        $this->markTestSkipped('node is not installed');
    }
    $harness = EdgeMeter::JS.<<<'JS'

const assert = (ok, what) => { if (!ok) { console.error('FAIL: ' + what); process.exit(1); } };
const posts = [];
let answer = { ok: true, deny: {} };
globalThis.fetch = async (url, init) => {
  if (answer === 'down') throw new Error('down');
  posts.push({ url, signature: init.headers['x-dply-meter-signature'], body: JSON.parse(init.body) });
  return new Response(JSON.stringify(answer));
};
const env = { DPLY_METER_URL: 'https://dply.test/hooks/edge/s/meter', DPLY_METER_KEY: 'k' };
const waits = [];
const ctx = { waitUntil: (p) => waits.push(p) };
const settle = async () => { await Promise.all(waits.splice(0)); };

const raw = { run: async (model, input) => (input.stream
  ? new Response('data: {"response":"Hel"}\n\ndata: {"response":"lo"}\n\ndata: {"response":"","usage":{"prompt_tokens":9,"completion_tokens":2}}\n\ndata: [DONE]\n\n').body
  : model === 'est' ? { response: 'abcdefgh' } : { response: 'hi', usage: { prompt_tokens: 7, completion_tokens: 3 } }),
  models: () => 'passed through' };
const ai = dplyMetered('ai', raw, env, ctx, 'AI');

assert((await ai.run('@cf/x', { prompt: 'hi' })).response === 'hi', 'AI answer');
await settle();
assert(posts.length === 2 && JSON.stringify(posts[0].body.usage) === '{}', 'first call checks the verdict');
assert(/^[0-9a-f]{64}$/.test(posts[1].signature), 'reports are signed');
assert(JSON.stringify(posts[1].body.usage.ai) === '[{"model":"@cf/x","in":7,"out":3}]', 'usage from the response');
assert(ai.models() === 'passed through', 'models() passes through');
let gatewayCalled = false;
raw.gateway = () => { gatewayCalled = true; };
let refusedGateway = null;
try { dplyMetered('ai', raw, env, ctx, 'AI').gateway('x'); } catch (e) { refusedGateway = e; }
assert(refusedGateway && refusedGateway.status === 403 && !gatewayCalled, 'unmetered AI methods are refused');

await ai.run('est', { prompt: 'abcd' });
await settle();
const est = posts.at(-1).body.usage.ai[0];
assert(est.est === true && est.out === 2 && est.in > 0, 'estimated when there is no usage');

const stream = await ai.run('@cf/s', { prompt: 'x', stream: true });
const text = await new Response(stream).text();
await settle();
assert(text.includes('[DONE]'), 'stream passes through');
assert(JSON.stringify(posts.at(-1).body.usage.ai) === '[{"model":"@cf/s","in":9,"out":2}]', 'usage from the stream');

const vectors = dplyMetered('vectors', { query: async () => ({ matches: [] }), queryById: async () => ({ matches: [] }), describe: async () => ({ dimensions: 768 }) }, env, ctx, 'DOCS');
await vectors.query(new Array(384).fill(0), { topK: 3 });
await settle();
assert(posts.at(-1).body.usage.vector_dims === 384, 'query dims from the vector');
await vectors.queryById('a');
await settle();
assert(posts.at(-1).body.usage.vector_dims === 768, 'queryById dims from describe');

const browser = dplyMetered('browser', { fetch: async () => new Response('ok', { headers: { 'x-browser-ms-used': '1500' } }) }, env, ctx, 'BROWSER');
assert((await browser.fetch('https://x/v1/acquire')).status === 200, 'browser fetch');
await settle();
assert(posts.at(-1).body.usage.browser_ms === 1500, 'browser time from the header');

// Over the cap: refused without calling Cloudflare.
answer = { ok: true, deny: { ai: { status: 429, message: 'limit reached' }, browser: { status: 429, message: 'limit reached' } } };
dplyMeterState.at = 0;
let called = false;
const guarded = dplyMetered('ai', { run: async () => { called = true; } }, env, ctx, 'AI');
const error = await guarded.run('@cf/x', {}).catch((e) => e);
assert(error.status === 429 && error.message === 'limit reached' && error.dplyMeter && !called, 'refused over the cap');
assert(dplyRefusal(error).status === 429, 'container path answers 429');
assert((await browser.fetch('https://x/v1/acquire')).status === 429, 'browser refused');

// dply unreachable: a recent verdict holds, none at all fails closed.
answer = 'down';
dplyMeterState.verdict = { ok: true, deny: {} };
dplyMeterState.at = Date.now() - 60000;
await guarded.run('@cf/x', {});
assert(called, 'recent verdict holds while dply is down');
dplyMeterState.verdict = null;
dplyMeterState.at = 0;
const closed = await guarded.run('@cf/x', {}).catch((e) => e);
assert(closed.status === 503, 'no verdict: fail closed');
console.log('ok');
JS;
    $file = sys_get_temp_dir().'/dply-meter-'.uniqid().'.mjs';
    file_put_contents($file, $harness);
    $result = Process::run([$node, $file]);
    @unlink($file);

    expect(trim($result->output().$result->errorOutput()))->toBe('ok');
});

test('the generated container Worker and Worker entry parse, with AI, vectors and browser metered', function () {
    $node = trim((string) shell_exec('command -v node'));
    if ($node === '') {
        $this->markTestSkipped('node is not installed');
    }
    $site = meteredSite();
    $site->mergeEdgeMeta(['browser' => true]);
    $dir = sys_get_temp_dir().'/dply-meter-ctr-'.bin2hex(random_bytes(4));

    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $worker = File::get($dir.'/src/index.js');
    [, $modules] = EdgeWorkerEntryWrapper::wrap($site, 'worker.js', ['worker.js' => '']);
    file_put_contents($dir.'/entry.mjs', $modules['dply-entry.js']);
    rename($dir.'/src/index.js', $dir.'/src/index.mjs');
    $checks = [Process::run([$node, '--check', $dir.'/src/index.mjs']), Process::run([$node, '--check', $dir.'/entry.mjs'])];
    File::deleteDirectory($dir);

    expect($worker)->toContain('dplyMetered(c.kind, env[c.name], env, ctx, c.name)')
        ->toContain("puppeteer.launch(dplyMetered('browser', env.BROWSER, env, ctx, 'BROWSER'))")
        ->not->toContain('__DPLY');
    foreach ($checks as $check) {
        expect($check->errorOutput())->toBe('');
    }
});

test('the collector records what each org\'s vector index stores', function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok']);
    $site = meteredSite();
    $index = 'dply-'.strtolower((string) $site->organization_id).'-docs';
    Http::fake(function (Request $request) use ($index) {
        return match (true) {
            str_ends_with($request->url(), '/info') => Http::response(['success' => true, 'result' => ['vectorCount' => 1000, 'dimensions' => 768]]),
            str_contains($request->url(), '/vectorize/v2/indexes/') => Http::response(['success' => true, 'result' => ['name' => $index]]),
            str_contains($request->url(), '/vectorize/v2/indexes') => Http::response(['success' => true, 'result' => [['name' => $index], ['name' => 'someone-elses']]]),
            default => Http::response(['data' => ['viewer' => ['accounts' => [[]]]]]),
        };
    });

    $result = app(EdgePlatformUsageCollector::class)->collectForDate(now());

    expect($result['failed'])->toBe([])
        ->and(EdgePlatformUsage::query()->where('resource', 'vectorize:'.$index)->sole())
        ->organization_id->toBe($site->organization_id)
        ->vector_stored_dims->toBe(768_000);
});
