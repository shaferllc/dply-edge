<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;

/**
 * One stable script per Worker site, dply-state-{site}, holding EdgeState.
 * Every deployment script binds it with script_name + dispatch_namespace, so
 * State data outlives deploys (per-deploy scripts would each get a new, empty
 * namespace). Proven by the T-016 spike.
 *
 * Uploaded once, with the v1 sqlite migration. meta.edge.state_script records
 * that so later deploys skip the upload.
 */
class EdgeStateScript
{
    public const CLASS_NAME = 'EdgeState';

    private const TAG = 'v1';

    public static function nameFor(Site $site): string
    {
        return 'dply-state-'.strtolower((string) $site->id);
    }

    public static function needed(Site $site): bool
    {
        foreach (EdgeContainerConnections::for($site) as $connection) {
            if ($connection['kind'] === 'durable_object' && ! $connection['asleep']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upload the script if this site needs it and does not have it yet in
     * this account and namespace. Returns the script name, or '' when the
     * site has no State.
     *
     * A script moved to a new account or namespace starts empty there; the
     * old one is left alone, since it still holds the data.
     */
    public function ensure(Site $site, EdgeCloudflareClient $client, string $accountId, string $namespace, string $compatibilityDate): string
    {
        if (! self::needed($site)) {
            return '';
        }
        $name = self::nameFor($site);
        $where = ['name' => $name, 'tag' => self::TAG, 'account_id' => $accountId, 'namespace' => $namespace];
        $recorded = $site->edgeMeta()['state_script'] ?? null;
        if (is_array($recorded) && array_intersect_key($recorded, $where) == $where) {
            return $name;
        }

        $meta = [
            'compatibility_date' => $compatibilityDate,
            'tags' => ['dply-edge', 'site:'.(string) $site->id, 'dply-state'],
        ];
        $upload = fn (array $extras) => $client->uploadDispatchScript(
            namespace: $namespace,
            scriptName: $name,
            entryModulePath: 'state.js',
            modules: ['state.js' => self::SOURCE],
            metaExtras: $meta + $extras,
        );
        try {
            $upload(['migrations' => ['new_tag' => self::TAG, 'new_sqlite_classes' => [self::CLASS_NAME]]]);
        } catch (\Throwable $e) {
            // The script is already there at v1 (meta was lost or reset).
            // Re-uploading without a migration keeps its data.
            if (stripos($e->getMessage(), 'migration') === false && stripos($e->getMessage(), 'tag') === false) {
                throw $e;
            }
            $upload([]);
        }
        $site->mergeEdgeMeta(['state_script' => $where]);
        $site->save();

        return $name;
    }

    public function delete(Site $site, EdgeCloudflareClient $client, string $namespace): void
    {
        $recorded = $site->edgeMeta()['state_script'] ?? null;
        if (! is_array($recorded)) {
            return;
        }
        $client->deleteDispatchScript((string) ($recorded['namespace'] ?? $namespace), self::nameFor($site));
    }

    /** Same contract as the container's EdgeState: GET/PUT/DELETE /key, POST /incr/key, GET / lists keys. */
    private const SOURCE = <<<'JS'
import { DurableObject } from 'cloudflare:workers';

export class EdgeState extends DurableObject {
  async fetch(request) {
    const path = decodeURIComponent(new URL(request.url).pathname.replace(/^\//, ''));
    if (request.method === 'GET' && path === '') {
      const listed = await this.ctx.storage.list({ limit: 100 });
      return Response.json({ keys: [...listed.keys()] });
    }
    if (path === '') return new Response('Name a key.', { status: 400 });
    if (request.method === 'POST' && path.startsWith('incr/')) {
      const key = path.slice(5);
      const current = Number(await this.ctx.storage.get(key) ?? 0);
      const next = Number.isFinite(current) ? current + 1 : 1;
      await this.ctx.storage.put(key, String(next));
      return new Response(String(next), { headers: { 'content-type': 'text/plain; charset=utf-8' } });
    }
    if (request.method === 'GET') {
      const value = await this.ctx.storage.get(path);
      return new Response(value == null ? null : String(value), { status: value == null ? 404 : 200, headers: { 'content-type': 'text/plain; charset=utf-8' } });
    }
    if (request.method === 'PUT') {
      await this.ctx.storage.put(path, await request.text());
      return new Response(null, { status: 204 });
    }
    if (request.method === 'DELETE') {
      await this.ctx.storage.delete(path);
      return new Response(null, { status: 204 });
    }
    return new Response('This connection does not accept that request.', { status: 405 });
  }
}

export default { fetch: () => new Response('Not found', { status: 404 }) };
JS;
}
