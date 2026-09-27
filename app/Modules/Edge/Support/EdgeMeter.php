<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgePlatformUsage;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeMeteredUsageCost;
use App\Modules\Billing\Services\EdgeOrganizationUsageReader;
use App\Notifications\MeteredServicesCapNotice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Workers AI, Browser Rendering and Vectorize run on dply's Cloudflare
 * account and have no per-script analytics, so every call goes through a
 * proxy in dply-generated JS (JS below): the Worker entry wrapper for
 * SSR/middleware apps, the container Worker for container apps. The proxy
 * asks dply whether the call may run (kill switch, paid plan, the org's
 * monthly cap) and reports what it used to /hooks/edge/{site}/meter
 * (EdgeMeterIngestController), signed with a per-app key like forms.
 *
 * A signed ingest rather than Analytics Engine: the proxy needs a verdict
 * back for the cap and kill switch anyway, and this makes one round trip do
 * metering, gating and alerts, with no analytics lag.
 */
final class EdgeMeter
{
    public const SERVICES = ['ai', 'browser', 'vectors'];

    /** A Worker app's raw binding is renamed so only the wrapper's proxy is env.NAME. */
    public const RAW_PREFIX = 'DPLY_RAW_';

    /**
     * Workers AI neurons per million input / output tokens, from
     * https://developers.cloudflare.com/workers-ai/platform/pricing/ (2026-09-27).
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const NEURONS_PER_MILLION_TOKENS = [
        '@cf/meta/llama-3.2-1b-instruct' => [2457, 18252],
        '@cf/meta/llama-3.2-3b-instruct' => [4625, 30475],
        '@cf/meta/llama-3.1-8b-instruct-fp8-fast' => [4119, 34868],
        '@cf/meta/llama-3.2-11b-vision-instruct' => [4410, 61493],
        '@cf/meta/llama-3.1-70b-instruct-fp8-fast' => [26668, 204805],
        '@cf/meta/llama-3.3-70b-instruct-fp8-fast' => [26668, 204805],
        '@cf/deepseek-ai/deepseek-r1-distill-qwen-32b' => [45170, 443756],
        '@cf/deepseek-ai/deepseek-v4-flash-0731' => [40000, 120000],
        '@cf/deepseek-ai/deepseek-v4-pro-0813' => [120000, 360000],
        '@cf/mistral/mistral-7b-instruct-v0.1' => [10000, 17300],
        '@cf/mistralai/mistral-small-3.1-24b-instruct' => [31876, 50488],
        '@cf/meta/llama-3.1-8b-instruct' => [25608, 75147],
        '@cf/meta/llama-3.1-8b-instruct-fp8' => [13778, 26128],
        '@cf/meta/llama-3.1-8b-instruct-awq' => [11161, 24215],
        '@cf/meta/llama-3-8b-instruct' => [25608, 75147],
        '@cf/meta/llama-3-8b-instruct-awq' => [11161, 24215],
        '@cf/meta/llama-2-7b-chat-fp16' => [50505, 606061],
        '@cf/meta/llama-guard-3-8b' => [44003, 2730],
        '@cf/meta/llama-4-scout-17b-16e-instruct' => [24545, 77273],
        '@cf/google/gemma-3-12b-it' => [31371, 50560],
        '@cf/qwen/qwq-32b' => [60000, 90909],
        '@cf/qwen/qwen2.5-coder-32b-instruct' => [60000, 90909],
        '@cf/qwen/qwen3-30b-a3b-fp8' => [4625, 30475],
        '@cf/qwen/qwen3.8-27b' => [40909, 290909],
        '@cf/openai/gpt-oss-120b' => [31818, 68182],
        '@cf/openai/gpt-oss-20b' => [18182, 27273],
        '@cf/aisingapore/gemma-sea-lion-v4-27b-it' => [31876, 50488],
        '@cf/ibm-granite/granite-4.0-h-micro' => [1542, 10158],
        '@cf/zai-org/glm-4.7-flash' => [5500, 36400],
        '@cf/zai-org/glm-5.2' => [127273, 400000],
        '@cf/zai-org/glm-5.3' => [127273, 400000],
        '@cf/zai-org/glm-5.3-flash' => [13636, 45455],
        '@cf/nvidia/nemotron-3-120b-a12b' => [45455, 136364],
        '@cf/moonshotai/kimi-k2.5' => [54545, 272727],
        '@cf/moonshotai/kimi-k2.6' => [86364, 363636],
        '@cf/moonshotai/kimi-k2.7-code' => [86364, 363636],
        '@cf/google/gemma-4-26b-a4b-it' => [9091, 27273],
        '@cf/baai/bge-small-en-v1.5' => [1841, 0],
        '@cf/baai/bge-base-en-v1.5' => [6058, 0],
        '@cf/baai/bge-large-en-v1.5' => [18582, 0],
        '@cf/baai/bge-m3' => [1075, 0],
        '@cf/pfnet/plamo-embedding-1b' => [1689, 0],
        '@cf/qwen/qwen3-embedding-0.6b' => [1075, 0],
    ];

    /**
     * ponytail: a model not in the table (image, audio, new LLMs) is priced at
     * a high LLM rate on estimated tokens, with a per-call floor. Add its row
     * when one is used enough to matter.
     */
    public const UNKNOWN_MODEL = [50000, 300000];

    public const UNKNOWN_MODEL_MIN_NEURONS = 100;

    /** Neurons for one AI call. */
    public static function neurons(string $model, int $in, int $out): float
    {
        [$perIn, $perOut] = self::NEURONS_PER_MILLION_TOKENS[$model] ?? self::UNKNOWN_MODEL;
        $neurons = ($in * $perIn + $out * $perOut) / 1_000_000;

        return isset(self::NEURONS_PER_MILLION_TOKENS[$model]) ? $neurons : max((float) self::UNKNOWN_MODEL_MIN_NEURONS, $neurons);
    }

    public static function key(Site $site): string
    {
        return hash_hmac('sha256', 'edge-meter:'.$site->id, (string) config('app.key'));
    }

    public static function url(Site $site): string
    {
        $base = rtrim((string) (config('edge.log_ingest.base_url') ?: config('dply.public_app_url') ?: config('app.url')), '/');

        return $base.'/hooks/edge/'.$site->id.'/meter';
    }

    /**
     * The metered bindings a Worker app gets, env name => service.
     *
     * @return array<string, string>
     */
    public static function workerNames(Site $site): array
    {
        if (! EdgeContainerConnections::paidFeatures($site->organization)) {
            return [];
        }
        $names = [];
        foreach (EdgeContainerConnections::for($site) as $connection) {
            if (! $connection['asleep'] && in_array($connection['kind'], ['ai', 'vectors'], true)) {
                $names[$connection['name']] = $connection['kind'];
            }
        }
        if (EdgeContainerConnections::browserEnabled($site)) {
            $names['BROWSER'] = 'browser';
        }

        return $names;
    }

    /**
     * The meter's own env, for a Worker upload or a container's secrets.
     *
     * @return array{DPLY_METER_URL: string, DPLY_METER_KEY: string}
     */
    public static function env(Site $site): array
    {
        return ['DPLY_METER_URL' => self::url($site), 'DPLY_METER_KEY' => self::key($site)];
    }

    public static function enabled(string $service): bool
    {
        return (bool) config('edge.metered_services.enabled.'.$service, true);
    }

    /** The org's own cap in cents: its setting, else the default. 0 = off. */
    public static function capCents(Organization $organization): int
    {
        return max(0, (int) ($organization->metered_cap_cents ?? config('edge.metered_services.default_cap_cents', 2500)));
    }

    /** The cap that is enforced: the org's, never above the platform ceiling. */
    public static function effectiveCapCents(Organization $organization): int
    {
        $ceiling = max(0, (int) config('edge.metered_services.ceiling_cents', 100000));
        $cap = self::capCents($organization);

        return $cap === 0 ? $ceiling : min($cap, $ceiling);
    }

    /**
     * This billing period's usage, customer cents. Cached briefly so a gate
     * check is a cache read; record() refreshes it.
     *
     * @return array{cents: int, services: array{ai: int, browser: int, vectors: int}}
     */
    public static function spent(Organization $organization, bool $fresh = false): array
    {
        $key = 'edge-meter:spent:'.$organization->id;
        if (! $fresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }
        [$from, $to] = app(EdgeOrganizationUsageReader::class)->currentWindow($organization);
        $usage = app(EdgeMeteredUsageCost::class)->forOrganization($organization, $from, $to);
        $spent = ['cents' => $usage['cents'], 'services' => $usage['services']];
        Cache::put($key, $spent, 15);

        return $spent;
    }

    /**
     * Why this service may not run for the org now, or null.
     *
     * @return array{status: int, message: string}|null
     */
    public static function refusal(?Organization $organization, string $service): ?array
    {
        $label = ['ai' => 'AI', 'browser' => 'Browser rendering', 'vectors' => 'Vector search'][$service] ?? $service;
        if (! self::enabled($service)) {
            return ['status' => 503, 'message' => __(':service is turned off on dply for now. Try again later.', ['service' => $label])];
        }
        if ($organization === null || ! EdgeContainerConnections::paidFeatures($organization)) {
            return ['status' => 403, 'message' => __(':service needs a paid plan. It is not included in the trial.', ['service' => $label])];
        }
        $cap = self::effectiveCapCents($organization);
        if (self::spent($organization)['cents'] >= $cap) {
            return ['status' => 429, 'message' => __('This organization reached its $:cap monthly limit for AI, browser rendering and vector search. An owner can raise it on the billing page.', ['cap' => number_format($cap / 100, 2)])];
        }

        return null;
    }

    /**
     * What the proxy is told: the services it must refuse, with why.
     *
     * @return array{ok: true, deny: object} deny: service => {status, message}
     */
    public static function verdict(?Organization $organization): array
    {
        $deny = [];
        foreach (self::SERVICES as $service) {
            if (($refused = self::refusal($organization, $service)) !== null) {
                $deny[$service] = $refused;
            }
        }

        return ['ok' => true, 'deny' => (object) $deny];
    }

    /**
     * Add one report to the site's day row, atomically: concurrent reports
     * from many isolates must not lose counts.
     *
     * @param  array{ai_neurons?: float, browser_ms?: int, vector_query_dims?: int}  $usage
     */
    public static function record(Site $site, array $usage): void
    {
        $columns = [
            'ai_neurons' => max(0.0, (float) ($usage['ai_neurons'] ?? 0)),
            'browser_ms' => max(0, (int) ($usage['browser_ms'] ?? 0)),
            'vector_query_dims' => max(0, (int) ($usage['vector_query_dims'] ?? 0)),
        ];
        if (array_sum($columns) <= 0) {
            return;
        }
        EdgePlatformUsage::query()->upsert(
            [['id' => (string) Str::ulid(), 'organization_id' => $site->organization_id, 'site_id' => $site->id, 'resource' => 'meter:'.$site->id, 'date' => now()->utc()->toDateString()] + $columns],
            ['resource', 'date'],
            array_map(static fn (string $column) => DB::raw("edge_platform_usage.{$column} + excluded.{$column}"), array_combine(array_keys($columns), array_keys($columns))) + ['updated_at' => now()],
        );

        $organization = $site->organization;
        if ($organization !== null) {
            self::alert($organization, self::spent($organization, fresh: true)['cents']);
        }
    }

    /**
     * Email owners once each at 80% and 100% of the enforced cap, per
     * billing period. The UPDATE's WHERE is the dedupe, so two reports
     * crossing a threshold together send one email.
     */
    public static function alert(Organization $organization, int $spentCents): ?int
    {
        $cap = self::effectiveCapCents($organization);
        if ($cap <= 0) {
            return null;
        }
        $reached = collect([100, 80])->first(fn (int $pct): bool => $spentCents * 100 >= $cap * $pct);
        if ($reached === null) {
            return null;
        }
        [$from] = app(EdgeOrganizationUsageReader::class)->currentWindow($organization);
        $period = $from->toDateString();
        $claimed = Organization::query()->whereKey($organization->id)
            ->where(fn ($q) => $q->whereNull('metered_cap_alerts')
                ->orWhereRaw("(metered_cap_alerts->>'period') IS DISTINCT FROM ?", [$period])
                ->orWhereRaw("COALESCE((metered_cap_alerts->>'pct')::int, 0) < ?", [$reached]))
            ->update(['metered_cap_alerts' => json_encode(['period' => $period, 'pct' => $reached])]);
        if ($claimed !== 1) {
            return null;
        }
        $owners = $organization->users()->wherePivot('role', 'owner')->get();
        if ($owners->isNotEmpty()) {
            Notification::send($owners, new MeteredServicesCapNotice($organization, $reached, $spentCents, $cap));
        }

        return $reached;
    }

    /**
     * The proxy, shared by the Worker entry wrapper and the container Worker.
     * dplyMetered(kind, binding, env, ctx, name) wraps a raw binding; env
     * needs DPLY_METER_URL and DPLY_METER_KEY. A refused AI or vector call
     * throws an Error with .status and .dplyMeter; a refused browser fetch
     * answers with that status. A verdict is reused for 30 seconds; if dply
     * cannot be reached the last one holds for 5 minutes, and with none the
     * call is refused (fail closed: the account is dply's).
     */
    public const JS = <<<'JS'
// dply meter (EdgeMeter). AI, browser and vector search calls are checked
// against dply (kill switch, plan, the organization's monthly limit) and
// reported per app.
const dplyMeterState = { verdict: null, at: 0, dims: new Map() };
const dplyEncoder = new TextEncoder();

async function dplyMeterPost(env, usage) {
  const body = JSON.stringify({ at: new Date().toISOString(), usage });
  const key = await crypto.subtle.importKey('raw', dplyEncoder.encode(env.DPLY_METER_KEY || ''), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const mac = new Uint8Array(await crypto.subtle.sign('HMAC', key, dplyEncoder.encode(body)));
  const signature = Array.from(mac, (b) => b.toString(16).padStart(2, '0')).join('');
  const response = await fetch(env.DPLY_METER_URL, { method: 'POST', headers: { 'content-type': 'application/json', 'x-dply-meter-signature': signature }, body });
  if (!response.ok) throw new Error('dply meter answered ' + response.status);
  const verdict = await response.json();
  dplyMeterState.verdict = verdict;
  dplyMeterState.at = Date.now();
  return verdict;
}

async function dplyMeterGate(env, service) {
  const age = Date.now() - dplyMeterState.at;
  let verdict = dplyMeterState.verdict && age < 30000 ? dplyMeterState.verdict : null;
  if (!verdict) {
    try { verdict = await dplyMeterPost(env, {}); } catch { verdict = dplyMeterState.verdict && age < 300000 ? dplyMeterState.verdict : null; }
  }
  if (!verdict) return { status: 503, message: 'dply could not check this organization\'s usage limit. Try again shortly.' };
  return (verdict.deny && verdict.deny[service]) || null;
}

function dplyMeterReport(env, ctx, usage) {
  const sent = dplyMeterPost(env, usage).catch(() => {});
  if (ctx && typeof ctx.waitUntil === 'function') ctx.waitUntil(sent);
  return sent;
}

function dplyRefused(denied) {
  return Object.assign(new Error(denied.message), { status: denied.status, dplyMeter: true });
}

function dplyTokens(value) {
  if (value == null) return 0;
  if (typeof value === 'string') return Math.ceil(value.length / 4);
  if (value instanceof ArrayBuffer || ArrayBuffer.isView(value) || value instanceof ReadableStream) return 0;
  try {
    // Pixel and audio arrays are not tokens.
    return Math.ceil(JSON.stringify(value, (k, v) => (Array.isArray(v) && v.length > 256 && typeof v[0] === 'number' ? undefined : v)).length / 4);
  } catch { return 0; }
}

function dplyAiUsage(model, usage, inTokens, text) {
  return usage && Number.isFinite(usage.prompt_tokens)
    ? { model, in: usage.prompt_tokens, out: Number(usage.completion_tokens) || 0 }
    : { model, in: inTokens, out: dplyTokens(text), est: true };
}

// Methods in `own` replace the binding's; `only` (a list) refuses every
// other method, so nothing unmetered can spend on dply's account.
function dplyProxy(target, own, only) {
  return new Proxy(target, {
    get(t, prop) {
      if (Object.prototype.hasOwnProperty.call(own, prop)) return own[prop];
      const value = t[prop];
      if (typeof value !== 'function') return value;
      if (only && typeof prop === 'string' && !only.includes(prop)) {
        return () => { throw dplyRefused({ status: 403, message: prop + '() is not available on dply. Use run().' }); };
      }
      return value.bind(t);
    },
  });
}

function dplyAi(binding, env, ctx) {
  return dplyProxy(binding, {
    async run(model, input, options) {
      const denied = await dplyMeterGate(env, 'ai');
      if (denied) throw dplyRefused(denied);
      const output = await binding.run(model, input, options);
      const inTokens = dplyTokens(input);
      if (output instanceof ReadableStream) return dplyAiStream(output, env, ctx, String(model), inTokens);
      dplyMeterReport(env, ctx, { ai: [dplyAiUsage(String(model), output && output.usage, inTokens, output && typeof output.response === 'string' ? output.response : '')] });
      return output;
    },
  }, ['models']);
}

// A streamed answer is server-sent events: read the text and any usage off
// the lines as they pass, report when the stream ends.
function dplyAiStream(stream, env, ctx, model, inTokens) {
  const decoder = new TextDecoder();
  let buffer = '';
  let text = '';
  let usage = null;
  const scan = (line) => {
    if (!line.startsWith('data:')) return;
    try {
      const event = JSON.parse(line.slice(5));
      if (typeof event.response === 'string') text += event.response;
      if (event.usage) usage = event.usage;
    } catch {}
  };
  return stream.pipeThrough(new TransformStream({
    transform(chunk, controller) {
      controller.enqueue(chunk);
      buffer += typeof chunk === 'string' ? chunk : decoder.decode(chunk, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop();
      for (const line of lines) scan(line.trim());
    },
    flush() {
      scan(buffer.trim());
      dplyMeterReport(env, ctx, { ai: [dplyAiUsage(model, usage, inTokens, text)] });
    },
  }));
}

function dplyVectors(binding, env, ctx, name) {
  const counted = (method) => async (...args) => {
    const denied = await dplyMeterGate(env, 'vectors');
    if (denied) throw dplyRefused(denied);
    const result = await binding[method](...args);
    let dims = method === 'query' && args[0] && args[0].length ? args[0].length : dplyMeterState.dims.get(name);
    if (!dims) {
      try { const info = await binding.describe(); dims = Number(info.dimensions || (info.config && info.config.dimensions)) || 0; } catch { dims = 0; }
      dplyMeterState.dims.set(name, dims);
    }
    dplyMeterReport(env, ctx, { vector_dims: dims });
    return result;
  };
  return dplyProxy(binding, { query: counted('query'), queryById: counted('queryById') });
}

// Browser time: the X-Browser-Ms-Used header when Cloudflare sends one,
// else from connecting the browser session to closing it.
function dplyBrowser(binding, env, ctx) {
  return dplyProxy(binding, {
    async fetch(input, init) {
      const denied = await dplyMeterGate(env, 'browser');
      if (denied) return new Response(denied.message, { status: denied.status });
      const started = Date.now();
      const response = await binding.fetch(input, init);
      const used = Number(response.headers.get('x-browser-ms-used') || 0);
      if (used > 0) dplyMeterReport(env, ctx, { browser_ms: used });
      else if (response.webSocket) response.webSocket.addEventListener('close', () => dplyMeterReport(env, ctx, { browser_ms: Date.now() - started }));
      return response;
    },
  });
}

function dplyMetered(kind, binding, env, ctx, name) {
  if (!binding) return binding;
  if (kind === 'ai') return dplyAi(binding, env, ctx);
  if (kind === 'vectors') return dplyVectors(binding, env, ctx, name);
  if (kind === 'browser') return dplyBrowser(binding, env, ctx);
  return binding;
}

function dplyRefusal(error) {
  if (error && error.dplyMeter) return Response.json({ error: error.message }, { status: error.status });
  throw error;
}
JS;
}
