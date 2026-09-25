<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\EdgeSiteEnvVar;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeValkey;
use Illuminate\Console\Command;
use Redis;

/**
 * One-time move off Upstash Redis (removed 2026-09-24, ruling r-72p0gkdn9dqwxqha).
 * For each app still on an Upstash database (a UUID target), start a dply
 * Valkey, copy every key with its expiry (DUMP / PTTL / RESTORE over the
 * app's own REDIS_URL), then point the app at the Valkey. The Upstash
 * database is left in place; delete it in the Upstash console once the app
 * has been redeployed and checked.
 *
 * ponytail: copies while the app may still be writing; a write between the
 * copy and the next deploy stays on Upstash. Run it, redeploy, then delete.
 */
class MoveRedisToValkeyCommand extends Command
{
    protected $signature = 'dply:edge:move-redis-to-valkey
                            {--site= : Only this site id}
                            {--class=flex_250m : Valkey size for the new database}
                            {--dry-run : List the apps without changing anything}';

    protected $description = 'Copy apps from Upstash Redis to dply Valkey and switch REDIS_URL.';

    private const UPSTASH_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function handle(): int
    {
        $class = (string) $this->option('class');
        if (! isset(EdgeValkey::CLASSES[$class])) {
            $this->error('Unknown class. Use one of: '.implode(', ', array_keys(EdgeValkey::CLASSES)));

            return self::FAILURE;
        }

        $sites = Site::query()->whereNotNull('edge_backend')
            ->when($this->option('site'), fn ($q, $id) => $q->whereKey($id))
            ->get();
        $moved = 0;
        foreach ($sites as $site) {
            $rows = EdgeContainerConnections::for($site);
            foreach ($rows as $index => $connection) {
                if ($connection['kind'] !== 'redis' || preg_match(self::UPSTASH_ID, $connection['target']) !== 1) {
                    continue;
                }
                $source = $this->env($site, 'REDIS_URL');
                if ($source === '') {
                    $this->warn("{$site->name}: no REDIS_URL, skipped.");

                    continue;
                }
                if ($this->option('dry-run')) {
                    $this->line("{$site->name}: would move {$connection['name']} to {$class}.");

                    continue;
                }

                $valkey = EdgeValkey::provision($site, EdgeContainerConnections::resourceLabel($connection['host']), $class, EdgeValkey::DEFAULT_SLEEP);
                try {
                    $copied = $this->copy($source, $valkey['url']);
                } catch (\Throwable $e) {
                    EdgeValkey::destroy($valkey['target']);
                    $this->error("{$site->name}: copy failed, nothing changed: {$e->getMessage()}");

                    continue;
                }

                $rows[$index]['target'] = $valkey['target'];
                $rows[$index]['plan'] = $class;
                $site->mergeEdgeMeta([
                    'connections' => $rows,
                    'valkey_sleep' => [$valkey['target'] => EdgeValkey::sleepAfter($class, EdgeValkey::DEFAULT_SLEEP)] + (array) ($site->edgeMeta()['valkey_sleep'] ?? []),
                ]);
                $site->save();
                $parts = parse_url($valkey['url']) ?: [];
                $this->putEnv($site, 'REDIS_URL', $valkey['url']);
                $this->putEnv($site, 'REDIS_USERNAME', 'default');
                $this->putEnv($site, 'REDIS_PASSWORD', rawurldecode((string) ($parts['pass'] ?? '')));
                $moved++;
                $this->info("{$site->name}: copied {$copied} key(s). Redeploy, check it, then delete Upstash database {$connection['target']}.");
            }
        }
        $this->info("Moved {$moved} app(s).");

        return self::SUCCESS;
    }

    private function copy(string $fromUrl, string $toUrl): int
    {
        $from = $this->connect($fromUrl);
        $to = $this->connect($toUrl);
        $copied = 0;
        $cursor = null;
        do {
            $keys = $from->scan($cursor, '*', 500);
            foreach ($keys ?: [] as $key) {
                $payload = $from->dump($key);
                if ($payload === false) {
                    continue; // expired between SCAN and DUMP
                }
                $ttl = (int) $from->pttl($key);
                $to->restore($key, max(0, $ttl), $payload, ['REPLACE']);
                $copied++;
            }
        } while ($cursor > 0);

        return $copied;
    }

    private function connect(string $url): Redis
    {
        $parts = parse_url($url) ?: [];
        $host = (string) ($parts['host'] ?? '');
        $tls = ($parts['scheme'] ?? '') === 'rediss';
        $redis = new Redis;
        $redis->connect(
            ($tls ? 'tls://' : '').$host,
            (int) ($parts['port'] ?? 6379),
            10,
            null,
            0,
            0,
            $tls ? ['stream' => ['peer_name' => $host]] : [],
        );
        $redis->auth([rawurldecode((string) ($parts['user'] ?? 'default')), rawurldecode((string) ($parts['pass'] ?? ''))]);
        $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

        return $redis;
    }

    private function env(Site $site, string $key): string
    {
        return (string) ($site->edgeEnvVars()->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)->where('key', $key)->first()?->value ?? '');
    }

    private function putEnv(Site $site, string $key, string $value): void
    {
        $row = $site->edgeEnvVars()->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)->where('key', $key)->first()
            ?? new EdgeSiteEnvVar(['site_id' => $site->id, 'key' => $key, 'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION]);
        $row->value = $value;
        $row->save();
    }
}
