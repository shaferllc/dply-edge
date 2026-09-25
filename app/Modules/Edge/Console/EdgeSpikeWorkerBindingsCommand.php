<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Console\Command;

/**
 * T-016 spike. Uploads throwaway scripts into the site's dispatch namespace
 * to learn what Workers for Platforms accepts, then deletes them:
 *
 *   1. dply-state-spike with EdgeState + a sqlite migration
 *   2. a deploy-shaped script bound to it by script_name
 *   3. a script that exports a WorkflowEntrypoint and binds it
 *   4. the dply-entry.js wrapper re-exporting a user entry
 *
 * ponytail: throwaway — delete once docs/EDGE_PLATFORM_STATUS.md has the answers.
 */
class EdgeSpikeWorkerBindingsCommand extends Command
{
    protected $signature = 'dply:edge:spike-worker-bindings {site : Site id whose dispatch namespace to use} {--keep : Leave the scripts in place}';

    protected $description = 'Probe Workers for Platforms support for cross-script Durable Objects, Workflows and the entry wrapper.';

    public function handle(EdgeDeliveryContextResolver $contexts): int
    {
        $site = Site::query()->findOrFail($this->argument('site'));
        $context = $contexts->forSite($site);
        if (! $context->supportsSsr()) {
            $this->error('This site has no dispatch namespace configured.');

            return self::FAILURE;
        }
        $client = new EdgeCloudflareClient($context->accountId, $context->apiToken);
        $namespace = $context->dispatchNamespaceName;
        $meta = ['compatibility_date' => $context->ssrCompatibilityDate, 'compatibility_flags' => $context->ssrCompatibilityFlags];

        $state = <<<'JS'
import { DurableObject } from 'cloudflare:workers';
export class EdgeState extends DurableObject {
  async fetch() { const n = Number(await this.ctx.storage.get('n') ?? 0) + 1; await this.ctx.storage.put('n', n); return new Response(String(n)); }
}
export default { fetch: () => new Response('state host') };
JS;
        $user = "export default { fetch: (req, env) => new Response('user ' + typeof env.STATE) };\nexport class UserThing {}\n";
        $wrapper = <<<'JS'
import app from './worker.js';
export * from './worker.js';
export default { fetch: (req, env, ctx) => app.fetch(req, env, ctx) };
JS;
        $flow = <<<'JS'
import { WorkflowEntrypoint } from 'cloudflare:workers';
export class SpikeFlow extends WorkflowEntrypoint { async run(event, step) { return step.do('one', async () => 1); } }
export default { fetch: () => new Response('flow') };
JS;

        $probes = [
            'dply-spike-state' => ['entry' => 'state.js', 'modules' => ['state.js' => $state], 'bindings' => [
                ['type' => 'durable_object_namespace', 'name' => 'STATE', 'class_name' => 'EdgeState'],
            ], 'migrations' => ['new_tag' => 'v1', 'new_sqlite_classes' => ['EdgeState']]],
            'dply-spike-deploy' => ['entry' => 'dply-entry.js', 'modules' => ['dply-entry.js' => $wrapper, 'worker.js' => $user], 'bindings' => [
                ['type' => 'durable_object_namespace', 'name' => 'STATE', 'class_name' => 'EdgeState', 'script_name' => 'dply-spike-state', 'dispatch_namespace' => $namespace],
            ]],
            'dply-spike-flow' => ['entry' => 'flow.js', 'modules' => ['flow.js' => $flow], 'bindings' => [
                ['type' => 'workflow', 'name' => 'FLOW', 'workflow_name' => 'dply-spike-flow', 'class_name' => 'SpikeFlow'],
            ]],
        ];

        $failed = false;
        foreach ($probes as $script => $probe) {
            $extras = $meta + (isset($probe['migrations']) ? ['migrations' => $probe['migrations']] : []);
            try {
                $client->uploadDispatchScript($namespace, $script, $probe['entry'], $probe['modules'], $probe['bindings'], $extras);
                $this->info("OK    {$script}");
            } catch (\Throwable $e) {
                $failed = true;
                $this->error("FAIL  {$script}: ".$e->getMessage());
            }
        }

        if (! $this->option('keep')) {
            foreach (array_reverse(array_keys($probes)) as $script) {
                try {
                    $client->deleteDispatchScript($namespace, $script);
                } catch (\Throwable $e) {
                    $this->warn("Could not delete {$script}: ".$e->getMessage());
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
