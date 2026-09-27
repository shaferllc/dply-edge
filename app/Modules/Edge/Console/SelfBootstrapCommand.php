<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\Site;
use App\Modules\Edge\Console\Concerns\RunsWithEnvFile;
use App\Modules\Edge\Services\SelfHosting\SelfDeployState;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Edge\Support\EdgeValkey;
use App\Modules\Providers\Valkey\ValkeyRegions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * First start of a self-hosted dply on a fresh, empty database (ruling
 * r-nra2jn3k5jek65hz). dply's own site, database and Valkey must exist before
 * dply can run, so this does it from the operator's machine, without dply:
 *
 *   1. resources  choose the self site's id; create its dply Postgres and
 *                 Valkey through the gateway API (named after that id, as the
 *                 Resources tab would); write their credentials into the env
 *                 file; keep ids (no secrets) in the R2 state
 *   2. schema     migrate the empty database (from this machine: loading the
 *                 schema dump needs `psql`, which the app image lacks), then
 *                 dply:self:register --bootstrap: owner, organization, the
 *                 site with that id, and the two resources adopted
 *
 * Then dply:self:deploy. Idempotent: resources already in the R2 state are
 * never created again (that would rotate their passwords).
 */
class SelfBootstrapCommand extends Command
{
    use RunsWithEnvFile;

    protected $signature = 'dply:self:bootstrap
        {--env-file= : The container env file; database and Valkey credentials are written into it}
        {--owner= : Owner email (created if missing; add it to PLATFORM_ADMIN_EMAILS)}
        {--owner-name=dply admin}
        {--database-size=0.5 : dply Postgres size (compute units); stays on}
        {--disk=20 : dply Postgres disk (GB)}
        {--valkey-class=flex_1g : dply Valkey class}
        {--phase= : internal: resources | schema}
        {--dry-run : Print the steps; create nothing}';

    protected $description = 'Start a self-hosted dply from nothing: its database, Valkey, schema and own site';

    public function handle(SelfDeployState $state): int
    {
        $envFile = trim((string) $this->option('env-file'));
        if ($envFile === '' || trim((string) $this->option('owner')) === '') {
            $this->error('Pass --env-file=<container env file> and --owner=<email>.');

            return self::FAILURE;
        }

        if (! $this->isEnvFileChild()) {
            $required = ['APP_KEY', 'DPLY_EDGE_R2_BUCKET', 'DPLY_EDGE_CF_ACCOUNT_ID', 'DPLY_EDGE_CF_API_TOKEN', 'DPLY_VALKEY_API_URL', 'DPLY_VALKEY_TOKEN'];
            foreach (['resources', 'schema'] as $phase) {
                $this->info("== {$phase}");
                // A fresh child per phase: the second one reads the database
                // credentials the first wrote into the file.
                $code = $this->reexecWithEnvFile($envFile, $required, ['phase' => $phase]);
                if ($code !== self::SUCCESS) {
                    return $code;
                }
            }
            $this->info('Bootstrapped. Next: php artisan dply:self:deploy --env-file='.$envFile);

            return self::SUCCESS;
        }

        return match ((string) $this->option('phase')) {
            'resources' => $this->resources($state, $envFile),
            'schema' => $this->schema(),
            default => self::FAILURE,
        };
    }

    private function resources(SelfDeployState $state, string $envFile): int
    {
        $dryRun = (bool) $this->option('dry-run');
        try {
            $saved = $state->load();
        } catch (Throwable $e) {
            $this->error('Could not read the R2 state: '.$e->getMessage());

            return self::FAILURE;
        }
        $siteId = (string) config('edge.self.site_id') ?: $saved['site_id'] ?: strtolower((string) Str::ulid());
        $this->line("Self site id: {$siteId} (Worker dply-ctr-{$siteId}).");
        $site = (new Site)->forceFill(['id' => $siteId]);
        $region = ValkeyRegions::default();
        $boot = $saved['bootstrap'];

        if (is_array($boot['database'] ?? null)) {
            $this->line("dply Postgres: {$boot['database']['remote_id']} already created.");
        } else {
            $this->line('→ Create dply Postgres '.EdgeDplyDatabase::tenantId($site).' ('.$this->option('database-size').' CU, stays on, '.$this->option('disk').' GB) and write DB_* into '.$envFile);
            if (! $dryRun) {
                $db = EdgeDplyDatabase::provision($site, (string) $this->option('database-size'), -1, (int) $this->option('disk'), 'postgres', $region);
                self::writeEnvFile($envFile, [
                    'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $db['host'], 'DB_PORT' => $db['port'], 'DB_DATABASE' => $db['database'],
                    'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'DB_SSLMODE' => 'require',
                ]);
                $boot['database'] = ['remote_id' => $db['id'], 'host' => $db['host'], 'region' => $db['region'],
                    'size' => (string) $this->option('database-size'), 'disk_gb' => EdgeDplyDatabase::disk((int) $this->option('disk'))];
                $this->saveBoot($state, $saved, $siteId, $boot);
            }
        }

        if (is_array($boot['valkey'] ?? null)) {
            $this->line("dply Valkey: {$boot['valkey']['target']} already created.");
        } else {
            $this->line('→ Create dply Valkey '.strtolower($siteId).'-cache ('.$this->option('valkey-class').') and write REDIS_* into '.$envFile);
            if (! $dryRun) {
                $valkey = EdgeValkey::provision($site, 'cache', (string) $this->option('valkey-class'), EdgeValkey::DEFAULT_SLEEP, $region);
                $parts = parse_url($valkey['url']) ?: [];
                self::writeEnvFile($envFile, [
                    'REDIS_URL' => $valkey['url'], 'REDIS_USERNAME' => rawurldecode((string) ($parts['user'] ?? 'default')),
                    'REDIS_PASSWORD' => rawurldecode((string) ($parts['pass'] ?? '')),
                ]);
                $boot['valkey'] = ['target' => $valkey['target']];
                $this->saveBoot($state, $saved, $siteId, $boot);
            }
        }

        return self::SUCCESS;
    }

    private function schema(): int
    {
        if ($this->option('dry-run')) {
            $this->line('→ Wait for the new database, then: php artisan migrate --force (empty database: loads database/schema/pgsql-schema.sql with psql)');
            $this->line('→ php artisan dply:self:register --bootstrap --owner='.$this->option('owner').' (owner, organization, self site, adopt the database and Valkey)');

            return self::SUCCESS;
        }

        // The gateway starts the database pod on first connect.
        for ($try = 1; ; $try++) {
            try {
                DB::connection()->select('select 1');
                break;
            } catch (Throwable $e) {
                if ($try >= 36) {
                    $this->error('The new database never answered: '.$e->getMessage());

                    return self::FAILURE;
                }
                DB::purge();
                sleep(5);
            }
        }

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('dply:self:register', ['--bootstrap' => true, '--owner' => $this->option('owner'), '--owner-name' => $this->option('owner-name')]);
    }

    /**
     * @param  array<string, mixed>  $saved
     * @param  array<string, mixed>  $boot
     */
    private function saveBoot(SelfDeployState $state, array &$saved, string $siteId, array $boot): void
    {
        $saved['site_id'] = $siteId;
        $saved['bootstrap'] = $boot;
        $state->save($saved);
    }

    /**
     * Set KEY='value' lines in an env file, keeping every other line.
     *
     * @param  array<string, string>  $values
     */
    public static function writeEnvFile(string $path, array $values): void
    {
        $lines = is_file($path) ? preg_split('/\R/', rtrim((string) file_get_contents($path), "\n")) ?: [] : [];
        foreach ($values as $key => $value) {
            if (str_contains($value, "'") || str_contains($value, "\n")) {
                throw new RuntimeException("{$key} cannot be written safely to the env file.");
            }
            $line = $key."='".$value."'";
            $found = false;
            foreach ($lines as $i => $existing) {
                if (preg_match('/^\s*(export\s+)?'.preg_quote($key, '/').'\s*=/', $existing) === 1) {
                    $lines[$i] = $line;
                    $found = true;
                }
            }
            if (! $found) {
                $lines[] = $line;
            }
        }
        file_put_contents($path, implode("\n", $lines)."\n");
        @chmod($path, 0600);
    }
}
