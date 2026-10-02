<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\DplyDatabase;
use App\Models\Site;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use App\Modules\Providers\Valkey\ValkeyRegions;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Deletes the backups of databases that no longer exist. The gateway deletes
 * a database's backups with it, but the R2 bucket lock
 * (dply:databases:lock-backups) refuses the ones from its last few days, so
 * those are left behind. Daily, this removes them once they are unlocked.
 *
 * A prefix is only deleted when all three say the database is gone: no app or
 * DplyDatabase row names it, no regional gateway knows the tenant (a 404, not
 * an error), and its newest object is older than the lock.
 *
 *   php artisan dply:databases:sweep-backups [--dry-run]
 */
class SweepDatabaseBackupsCommand extends Command
{
    protected $signature = 'dply:databases:sweep-backups {--dry-run : List what would be deleted}';

    protected $description = 'Delete leftover backups of deleted dply databases once their bucket lock has expired.';

    public function handle(): int
    {
        $r2 = (array) config('edge.r2');
        if (trim((string) ($r2['bucket'] ?? '')) === '') {
            $this->error('edge.r2.bucket is not set.');

            return self::FAILURE;
        }
        $s3 = app()->makeWith(S3Client::class, ['args' => ['version' => 'latest', 'region' => $r2['region'] ?: 'auto', 'endpoint' => $r2['endpoint'], 'use_path_style_endpoint' => true, 'credentials' => ['key' => $r2['key'], 'secret' => $r2['secret']]]]);
        $bucket = (string) $r2['bucket'];

        $live = [];
        Site::query()->whereNotNull('meta->edge->database->remote_id')->each(function (Site $site) use (&$live): void {
            $live[(string) ($site->edgeMeta()['database']['remote_id'] ?? '')] = true;
        });
        foreach (DplyDatabase::query()->pluck('remote_id') as $id) {
            $live[(string) $id] = true;
        }

        $swept = 0;
        foreach (LockDatabaseBackupsCommand::PREFIXES as $prefix) {
            foreach ($this->tenantPrefixes($s3, $bucket, $prefix) as $tenantPrefix) {
                $id = substr($tenantPrefix, strlen('tenants/'), -1);
                if (isset($live[$id]) || ! $this->goneFromGateways($id)) {
                    continue;
                }
                $objects = $this->objects($s3, $bucket, $tenantPrefix);
                $newest = max(array_map(static fn (array $o): int => $o['at'], $objects) ?: [0]);
                if ($newest > now()->subDays(LockDatabaseBackupsCommand::LOCK_DAYS + 1)->getTimestamp()) {
                    $this->line("{$id}: still locked (newest object ".date('Y-m-d H:i', $newest).')');

                    continue;
                }
                $this->line(($this->option('dry-run') ? 'would delete ' : 'deleting ').count($objects)." objects of {$id}");
                if (! $this->option('dry-run')) {
                    foreach (array_chunk(array_column($objects, 'key'), 1000) as $chunk) {
                        $s3->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => array_map(static fn (string $k): array => ['Key' => $k], $chunk), 'Quiet' => true]]);
                    }
                }
                $swept++;
            }
        }
        $this->info("{$swept} deleted database(s) swept.");

        return self::SUCCESS;
    }

    /** @return list<string> "tenants/pg-…/" */
    private function tenantPrefixes(S3Client $s3, string $bucket, string $prefix): array
    {
        $out = [];
        $token = null;
        do {
            $page = $s3->listObjectsV2(array_filter(['Bucket' => $bucket, 'Prefix' => $prefix, 'Delimiter' => '/', 'ContinuationToken' => $token]));
            foreach ($page['CommonPrefixes'] ?? [] as $common) {
                $out[] = (string) $common['Prefix'];
            }
            $token = $page['IsTruncated'] ? $page['NextContinuationToken'] : null;
        } while ($token !== null);

        return $out;
    }

    /** @return list<array{key: string, at: int}> */
    private function objects(S3Client $s3, string $bucket, string $prefix): array
    {
        $out = [];
        $token = null;
        do {
            $page = $s3->listObjectsV2(array_filter(['Bucket' => $bucket, 'Prefix' => $prefix, 'ContinuationToken' => $token]));
            foreach ($page['Contents'] ?? [] as $object) {
                $out[] = ['key' => (string) $object['Key'], 'at' => $object['LastModified']->getTimestamp()];
            }
            $token = $page['IsTruncated'] ? $page['NextContinuationToken'] : null;
        } while ($token !== null);

        return $out;
    }

    /**
     * Every configured gateway answers 404 for the tenant, and at least one
     * was asked. Any other answer, or no gateway to ask, keeps it.
     */
    private function goneFromGateways(string $id): bool
    {
        $asked = 0;
        foreach (array_keys(ValkeyRegions::all()) as $region) {
            if (! ValkeyRegions::configured($region)) {
                continue;
            }
            $asked++;
            try {
                ValkeyGatewayClient::fromConfig($region)->get($id);

                return false;
            } catch (RequestException $e) {
                if ($e->response->status() !== 404) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return $asked > 0;
    }
}
