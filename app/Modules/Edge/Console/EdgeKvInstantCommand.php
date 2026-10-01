<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\EdgeDeployment;
use App\Models\EdgeRealtimeApp;
use App\Models\Site;
use App\Modules\Billing\Services\StarterTrafficGate;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Services\EdgeKvInstant;
use App\Modules\Edge\Services\EdgeQueueConsumers;
use App\Modules\Edge\Services\Messages\EdgeMessages;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * KV Instant (Cloudflare private beta) setup. Bare: what is configured.
 * --create: the namespaces, in instant mode, with the env lines to set.
 * --sync: write everything that lives in them, from the database.
 */
class EdgeKvInstantCommand extends Command
{
    protected $signature = 'dply:edge:kv-instant
        {--create : Create the gates, routes, realtime and messages namespaces in instant mode (needs the beta)}
        {--sync : Write every pause flag, queue route, hostname pointer, realtime record and Messages account to them}';

    protected $description = 'Set up and fill the KV Instant namespaces (gates, routes, realtime, messages).';

    public function handle(): int
    {
        $this->line('kv_instant.enabled: '.(EdgeKvInstant::enabled() ? 'yes' : 'no').', dual_write: '.(config('edge.kv_instant.dual_write') ? 'yes' : 'no'));
        $this->line('gates:    '.(EdgeKvInstant::gatesNamespace() ?? '(unset)'));
        $this->line('routes:   '.(trim((string) config('edge.cloudflare.routes_kv_namespace_id')) ?: '(unset)').(EdgeKvInstant::routesNamespace() === null ? ' (not used: needs kv_instant.enabled)' : ''));
        $this->line('realtime: '.(trim((string) config('edge.realtime.kv_namespace_id')) ?: '(unset)').(EdgeRealtimeApps::instantKv() ? ' (instant)' : ' (classic)'));

        if ($this->option('create')) {
            $client = EdgeCloudflareClient::fromConfig();
            $env = [
                'DPLY_EDGE_CF_GATES_KV_NAMESPACE_ID' => 'dply-edge-gates',
                'DPLY_EDGE_CF_ROUTES_KV_NAMESPACE_ID' => 'dply-edge-routes',
                'EDGE_REALTIME_KV_NAMESPACE_ID' => 'dply-realtime-apps-instant',
            ];
            $this->newLine();
            foreach ($env as $variable => $title) {
                $this->line($variable.'='.$client->ensureKvNamespace($title, 'instant'));
            }
            $messages = $client->ensureKvNamespace('dply-messages-accounts-instant', 'instant');
            $this->line('DPLY_KV_INSTANT=true');
            $this->line('EDGE_REALTIME_KV_INSTANT=true');
            $this->newLine();
            $this->line("packages/messages-worker/wrangler.toml: ACCOUNTS id = \"{$messages}\"");
            $this->line('packages/realtime-worker/wrangler.toml (env apps): APPS id = the EDGE_REALTIME_KV_NAMESPACE_ID above');
            $this->line('Then: deploy the platform Worker, the realtime and messages Workers, and run --sync.');
        }

        if ($this->option('sync')) {
            $this->sync();
        }

        return self::SUCCESS;
    }

    private function sync(): void
    {
        $failed = 0;
        $live = fn (Site $site): ?EdgeDeployment => EdgeDeployment::query()->where('site_id', $site->id)->where('status', EdgeDeployment::STATUS_LIVE)->latest('id')->first();

        // Pause flags: every one, not just changes.
        app(StarterTrafficGate::class)->syncAll(force: true);
        $this->line('Pause flags written.');

        // Queue routes and hostname pointers: republish every live platform site.
        $sites = 0;
        foreach (Site::query()->whereNotNull('edge_backend')->cursor() as $site) {
            $deployment = $live($site);
            if ($deployment === null) {
                continue;
            }
            try {
                $context = app(EdgeDeliveryContextResolver::class)->forSite($site);
                if (! $context->isPlatform()) {
                    continue;
                }
                app(EdgeHostMapPublisher::class)->publish($site, $deployment, $context);
                app(EdgeQueueConsumers::class)->sync($site, $deployment, $context);
                $sites++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn("{$site->id}: {$e->getMessage()}");
            }
        }
        $this->line("Republished {$sites} site(s).");

        $apps = 0;
        foreach (EdgeRealtimeApp::query()->cursor() as $app) {
            try {
                app(EdgeRealtimeApps::class)->sync($app);
                $apps++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn("realtime {$app->id}: {$e->getMessage()}");
            }
        }
        $this->line("Realtime: {$apps} app(s).");

        if (EdgeMessages::configured()) {
            $done = app(EdgeMessages::class)->resyncAll();
            $this->line("Messages: {$done['accounts']} account(s), {$done['tokens']} token(s).");
        }

        $this->line(EdgeKvInstant::enabled() || EdgeRealtimeApps::instantKv()
            ? 'Instant writes are queued, one per second per namespace: they finish on the queue.'
            : 'Written.');
        if ($failed > 0) {
            $this->warn("{$failed} failed (see above).");
        }
    }
}
