<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\DplyDatabase;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeBuildRunner;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Support\DplyRuntime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Proves a dply Postgres database's backups restore (dply:databases:verify-backups,
 * weekly). On a builder, in a throwaway container: wal-g fetches the latest
 * full backup, Postgres replays every archived change after it and promotes,
 * and the restored databases and tables are counted. The result goes on the
 * database's backup status (backup.verify) and a failure notifies.
 *
 * Read-only against the backups: archive_mode=off, and only backup-fetch and
 * wal-fetch run, so the promoted copy's new timeline never reaches the
 * tenant's prefix (a later real restore would follow it). The container and
 * its data are gone when it exits.
 */
final class VerifyDatabaseBackupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 2400;

    public int $tries = 1;

    /** Same wal-g as the database image (packages/valkey-gateway/dbagent/Dockerfile.postgres). */
    private const WALG_VERSION = 'v3.0.9';

    private const WALG_SHA256 = 'd6795c663894d836ba20840bffe8970aed81bbe25d3151bd4951f6f9c362def9';

    public function __construct(
        public string $remoteId,
        public string $label,
        public ?string $siteId = null,
        public ?string $databaseId = null,
    ) {
        $this->onQueue(DplyRuntime::BUILDER_QUEUE);
    }

    public function handle(): void
    {
        $started = now();
        try {
            $result = $this->restore();
            $verify = ['ok' => true, 'at' => now()->toIso8601String(), 'seconds' => (int) $started->diffInSeconds(now(), true)] + $result;
        } catch (Throwable $e) {
            $verify = ['ok' => false, 'at' => now()->toIso8601String(), 'error' => mb_substr($e->getMessage(), 0, 500)];
        }
        $this->record($verify);
    }

    /** @return array{databases: int, tables: int, restored_to: ?string} */
    private function restore(): array
    {
        $r2 = (array) config('edge.r2');
        foreach (['bucket', 'endpoint', 'key', 'secret'] as $required) {
            if (trim((string) ($r2[$required] ?? '')) === '') {
                throw new RuntimeException("R2 {$required} is not configured on this host.");
            }
        }
        $walg = $this->walg();
        $network = trim((string) config('edge.build.sandbox.network', ''));
        $command = [
            'docker', 'run', '--rm', '--name', 'dply-verify-'.substr(md5($this->remoteId.microtime()), 0, 12),
            ...($network !== '' ? ['--network', $network] : []),
            // The pinned wal-g is the amd64 build.
            '--platform', 'linux/amd64',
            '-v', $walg.':/usr/local/bin/wal-g:ro',
            // postgres:17-bookworm has no CA bundle: without the host's, wal-g's
            // TLS to R2 fails and its SDK retries silently until it times out.
            '-v', '/etc/ssl/certs:/etc/ssl/certs:ro',
            '-e', 'WALG_S3_PREFIX=s3://'.$r2['bucket'].'/tenants/'.$this->remoteId.'/pg',
            '-e', 'AWS_ENDPOINT='.$r2['endpoint'],
            '-e', 'AWS_REGION='.($r2['region'] ?: 'auto'),
            '-e', 'AWS_S3_FORCE_PATH_STYLE=true',
            '-e', 'AWS_ACCESS_KEY_ID='.$r2['key'],
            '-e', 'AWS_SECRET_ACCESS_KEY='.$r2['secret'],
            'postgres:17-bookworm', 'bash', '-c', self::script(),
        ];
        $run = Process::timeout($this->timeout - 120)->run($command);
        $output = $run->output()."\n".$run->errorOutput();
        if (preg_match('/^DPLY-VERIFY (\{.*\})$/m', $output, $m) !== 1) {
            throw new RuntimeException('The restore did not finish: '.trim(mb_substr(implode("\n", array_slice(preg_split('/\R/', trim($output)) ?: [], -4)), 0, 400)));
        }
        $result = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);

        return [
            'databases' => (int) ($result['databases'] ?? 0),
            'tables' => (int) ($result['tables'] ?? 0),
            'restored_to' => is_string($result['restored_to'] ?? null) && $result['restored_to'] !== '' ? $result['restored_to'] : null,
        ];
    }

    /**
     * The restore, inside postgres:17-bookworm. Local trust auth on a socket
     * in /tmp only (no TCP), and archiving off so nothing is ever uploaded.
     */
    public static function script(): string
    {
        return <<<'SH'
set -eu
D=/tmp/pg
mkdir -p "$D" && chown postgres "$D" && chmod 700 "$D"
gosu postgres wal-g backup-fetch "$D" LATEST
touch "$D/recovery.signal" && chown postgres "$D/recovery.signal"
printf 'local all all trust\n' > /tmp/hba.conf && chown postgres /tmp/hba.conf
gosu postgres pg_ctl -D "$D" -w -t 1800 -l /tmp/pg.log -o "-c archive_mode=off -c listen_addresses='' -c unix_socket_directories=/tmp -c hba_file=/tmp/hba.conf -c shared_preload_libraries='' -c restore_command='wal-g wal-fetch %f %p' -c recovery_target_action=promote" start
for i in $(seq 1 1800); do
  [ "$(gosu postgres psql -h /tmp -U dply_admin -d postgres -qAtc 'SELECT pg_is_in_recovery()' 2>/dev/null)" = "f" ] && break
  sleep 1
done
q() { gosu postgres psql -h /tmp -U dply_admin -qAt "$@"; }
dbs=0; tables=0
for db in $(q -d postgres -c "SELECT datname FROM pg_database WHERE NOT datistemplate AND datname <> 'postgres'"); do
  dbs=$((dbs + 1))
  tables=$((tables + $(q -d "$db" -c "SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema')")))
done
to=$(grep -o 'last completed transaction was at log time [0-9:. +-]*' /tmp/pg.log | tail -1 | sed 's/.*log time //')
echo "DPLY-VERIFY {\"databases\":$dbs,\"tables\":$tables,\"restored_to\":\"$to\"}"
gosu postgres pg_ctl -D "$D" -m immediate stop >/dev/null || true
SH;
    }

    /** The pinned wal-g binary, downloaded once into the builder's work root (shared with dind). */
    private function walg(): string
    {
        $path = rtrim(EdgeBuildRunner::buildRoot(), '/').'/tools/wal-g-'.self::WALG_VERSION;
        if (is_file($path) && hash_file('sha256', $path) === self::WALG_SHA256) {
            return $path;
        }
        File::ensureDirectoryExists(dirname($path));
        $body = Http::timeout(120)->get('https://github.com/wal-g/wal-g/releases/download/'.self::WALG_VERSION.'/wal-g-pg-22.04-amd64')->throw()->body();
        if (hash('sha256', $body) !== self::WALG_SHA256) {
            throw new RuntimeException('The downloaded wal-g did not match its pinned checksum.');
        }
        File::put($path, $body);
        chmod($path, 0755);

        return $path;
    }

    /** @param  array<string, mixed>  $verify */
    private function record(array $verify): void
    {
        $site = $this->siteId !== null ? Site::query()->find($this->siteId) : null;
        if ($this->databaseId !== null) {
            $database = DplyDatabase::query()->find($this->databaseId);
            if ($database !== null) {
                $state = (array) ($database->state ?? []);
                $state['backup'] = array_merge((array) ($state['backup'] ?? []), ['verify' => $verify]);
                $database->forceFill(['state' => $state])->save();
                $site ??= $database->sites()->first();
            }
        } elseif ($site !== null) {
            $database = (array) ($site->edgeMeta()['database'] ?? []);
            $database['backup'] = array_merge((array) ($database['backup'] ?? []), ['verify' => $verify]);
            $site->mergeEdgeMeta(['database' => $database]);
            $site->save();
        }

        if (! $verify['ok'] && $site !== null) {
            try {
                app(NotificationPublisher::class)->publish(
                    eventKey: 'site.errors.operation_failed',
                    subject: $site,
                    title: __('Database backup restore check failed for :name', ['name' => $this->label]),
                    body: __('dply restores every database from its backups once a week to prove they work. This one did not restore: :error', ['error' => $verify['error']]),
                    url: route('sites.show', ['site' => $site->id]),
                );
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
