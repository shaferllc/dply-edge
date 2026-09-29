<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\DplyDatabase;
use App\Models\EdgeSiteEnvVar;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Edge\Support\EdgeTrialLimits;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * An app's dply databases: create, attach an existing one, detach, delete,
 * and choose which is primary.
 *
 * The table (DplyDatabase + dply_database_site) is the source of truth. The
 * primary's connection is the standard env (DB_*, DATABASE_URL, MONGODB_URI)
 * and it is mirrored at meta.edge.database, which resize, backups, stats, the
 * sampler, queue workers and pools read. Another attached database gets the
 * same env behind its name (ANALYTICS_DB_HOST, ANALYTICS_DATABASE_URL …).
 * A database that was an app's primary before this table existed is adopted
 * the first time its app is read (adopt()), keeping its gateway id.
 */
final class DplyDatabases
{
    /** Keys on the primary's mirror that belong to the database, not the app: they move with it. */
    public const STATE_KEYS = ['history', 'memory', 'backup', 'usage_counter', 'storage_at', 'readonly', 'restore', 'transfer', 'resize_dismissed', 'resize_scheduled', 'resize_notified'];

    /**
     * The app's databases, primary first, each with ->attachment (env_name, primary).
     *
     * @return Collection<int, DplyDatabase>
     */
    public static function for(Site $site): Collection
    {
        self::adopt($site);

        return DplyDatabase::query()
            ->join('dply_database_site', 'dply_database_site.dply_database_id', '=', 'dply_databases.id')
            ->where('dply_database_site.site_id', $site->id)
            ->orderByDesc('dply_database_site.primary')
            ->orderBy('dply_database_site.id')
            ->get(['dply_databases.*', 'dply_database_site.env_name as attached_env_name', 'dply_database_site.primary as attached_primary']);
    }

    /** The organization's databases another app can attach (not already on $site). */
    public static function attachable(Site $site): Collection
    {
        $attached = self::for($site)->pluck('id')->all();

        return DplyDatabase::query()->where('organization_id', $site->organization_id)->whereNotIn('id', $attached)->orderBy('name')->get();
    }

    /**
     * Start a new database for the app. It becomes the primary when the app
     * has none; otherwise it is attached under $envName (default: its name).
     */
    public static function create(Site $site, string $engine, string $name, string $size, int $suspend, int $disk, ?string $envName = null): DplyDatabase
    {
        if (! in_array($engine, EdgeAppDatabase::DPLY_ENGINES, true)) {
            throw new RuntimeException('Pick Postgres, MySQL or MongoDB.');
        }
        $name = self::cleanName($name);
        if (DplyDatabase::query()->where('organization_id', $site->organization_id)->where('name', $name)->exists()) {
            throw new RuntimeException("Your organization already has a database named {$name}.");
        }
        if (! EdgeDplyDatabase::enabled()) {
            throw new RuntimeException('Databases cannot be started from here yet.');
        }
        EdgeAppDatabase::requireCard($site);

        $size = EdgeDplyDatabase::size($size);
        $disk = EdgeDplyDatabase::disk($disk);
        [$size, $suspend] = EdgeTrialLimits::database($site, $size, EdgeAppDatabase::postgresSuspend($suspend));
        if (EdgeDplyDatabase::alwaysOn($size)) {
            $suspend = -1;
        }

        $id = (string) Str::ulid();
        $created = EdgeDplyDatabase::provision($site, $size, $suspend, $disk, $engine, id: EdgeDplyDatabase::ENGINES[$engine][0].'-'.strtolower($id));
        $database = DplyDatabase::query()->forceCreate([
            'id' => $id,
            'organization_id' => $site->organization_id,
            'name' => $name,
            'engine' => $engine,
            'remote_id' => $created['id'],
            'region' => $created['region'],
            'host' => $created['host'],
            'size' => $size,
            'suspend' => $suspend,
            'disk_gb' => $disk,
            'password' => $created['password'],
            // Storage is billed per hour from here (EdgeValkeyUsageCollector).
            'state' => ['storage_at' => now()->timestamp],
            'created_by' => auth()->id(),
        ]);
        self::attach($site, $database, $envName);

        return $database;
    }

    /**
     * Attach a database the organization already has. The first one an app
     * gets is its primary; later ones get their own env prefix.
     */
    public static function attach(Site $site, DplyDatabase $database, ?string $envName = null, bool $primary = false): void
    {
        if ($database->organization_id !== $site->organization_id) {
            throw new RuntimeException('That database belongs to another organization.');
        }
        $attached = self::for($site);
        if ($attached->contains('id', $database->id)) {
            throw new RuntimeException("{$database->name} is already attached to this app.");
        }
        $primary = $primary || ! $attached->contains(fn (DplyDatabase $d) => (bool) $d->attached_primary);
        $envName = self::envName($envName ?? $database->name);
        if (! $primary && ($envName === 'DB' || $attached->contains(fn (DplyDatabase $d) => ! $d->attached_primary && $d->attached_env_name === $envName))) {
            throw new RuntimeException("Another database on this app already uses {$envName}_. Pick another name.");
        }

        $database->sites()->attach($site->id, ['env_name' => $envName, 'primary' => false]);
        if ($primary) {
            self::makePrimary($site, $database);
        } else {
            self::writeEnv($site, $database, $envName);
        }
    }

    /**
     * Make $database the app's primary: it takes DB_* / DATABASE_URL and the
     * meta.edge.database mirror; the old primary moves to its own prefix.
     */
    public static function makePrimary(Site $site, DplyDatabase $database): void
    {
        $current = self::for($site)->first(fn (DplyDatabase $d) => (bool) $d->attached_primary);
        if ($current?->id === $database->id) {
            return;
        }
        if ($current !== null) {
            self::keepState($site, $current);
            EdgeAppDatabase::forgetCredentials($site);
            $envName = self::freeEnvName($site, $current->attached_env_name ?: $current->name, $current->id);
            $current->sites()->updateExistingPivot($site->id, ['primary' => false, 'env_name' => $envName]);
            self::writeEnv($site, $current, $envName);
        }

        $attachment = self::for($site)->firstWhere('id', $database->id);
        if ($attachment !== null && ! $attachment->attached_primary) {
            self::forgetEnv($site, (string) $attachment->attached_env_name);
        }
        $database->sites()->updateExistingPivot($site->id, ['primary' => true]);
        EdgeAppDatabase::storeCredentials($site, $database->engine, self::credentials($database));
        self::mirror($site, $database);
    }

    /**
     * New size, sleep time or a bigger disk for any of the organization's
     * databases. Memory applies on the next wake; a disk only grows. Apps
     * that have it as their primary get the new values in their mirror.
     */
    public static function update(DplyDatabase $database, Site $site, string $size, int $suspend, int $disk): void
    {
        $disk = EdgeDplyDatabase::disk($disk);
        if ($disk < $database->disk_gb) {
            throw new RuntimeException("A database disk only grows. Pick {$database->disk_gb} GB or more.");
        }
        EdgeAppDatabase::requireCard($site);
        $size = EdgeDplyDatabase::size($size, $database->size);
        [$size, $suspend] = EdgeTrialLimits::database($site, $size, EdgeAppDatabase::postgresSuspend($suspend));
        if (EdgeDplyDatabase::alwaysOn($size)) {
            $suspend = -1;
        }
        EdgeDplyDatabase::update($database->remote_id, $database->password, $size, $suspend, $disk, $database->engine, $database->region);
        $database->forceFill(['size' => $size, 'suspend' => $suspend, 'disk_gb' => $disk])->save();
        foreach ($database->sites()->wherePivot('primary', true)->get() as $app) {
            $record = (array) ($app->edgeMeta()['database'] ?? []);
            $app->mergeEdgeMeta(['database' => ['size' => $size, 'suspend' => $suspend, 'disk_gb' => $disk, 'plan' => $suspend === -1 ? 'awake' : 'sleep'] + $record]);
            $app->save();
        }
    }

    /**
     * Take the database off this app. It keeps running in the organization.
     * A primary hands over to the next attached database, else the app is
     * back on SQLite in its container.
     */
    public static function detach(Site $site, DplyDatabase $database): void
    {
        $attachment = self::for($site)->firstWhere('id', $database->id);
        if ($attachment === null) {
            return;
        }
        if ($attachment->attached_primary) {
            self::keepState($site, $database);
            EdgeAppDatabase::forgetCredentials($site);
        } else {
            self::forgetEnv($site, (string) $attachment->attached_env_name);
        }
        $database->sites()->detach($site->id);

        if ($attachment->attached_primary) {
            $next = self::for($site)->first();
            if ($next !== null) {
                self::makePrimary($site, $next);
            } else {
                $site->mergeEdgeMeta(['database' => ['engine' => 'sql', 'name' => 'production']]);
                $site->save();
            }
        }
    }

    /**
     * Destroy the database and its data. Refused while another app still
     * uses it; from its one app it is detached first.
     */
    public static function delete(DplyDatabase $database, ?Site $from = null): void
    {
        $sites = $database->sites()->get();
        if ($sites->count() > 1 || ($sites->count() === 1 && $from !== null && $sites->first()->id !== $from->id)) {
            throw new RuntimeException("{$database->name} is still attached to ".$sites->pluck('name')->implode(', ').'. Detach it there first.');
        }
        foreach ($sites as $site) {
            self::detach($site, $database);
        }
        EdgeDplyDatabase::destroy($database->remote_id, $database->region);
        $database->delete();
    }

    /**
     * Rows for a primary that predates this table: the site's own database,
     * found by its gateway id, with the password its env holds.
     */
    public static function adopt(Site $site): void
    {
        $record = (array) ($site->edgeMeta()['database'] ?? []);
        $remoteId = (string) ($record['remote_id'] ?? '');
        if (! EdgeAppDatabase::isDply($record) || $remoteId === '' || $site->organization_id === null) {
            return;
        }
        // Only a database with no row yet: an existing row's attachments are
        // the truth (a detach in progress still has the old mirror).
        if (DplyDatabase::query()->where('remote_id', $remoteId)->exists()) {
            return;
        }

        $database = DplyDatabase::query()->forceCreate([
            'id' => (string) Str::ulid(),
            'organization_id' => $site->organization_id,
            'name' => self::uniqueName($site->organization_id, Str::slug((string) $site->name) ?: 'app'),
            'engine' => (string) ($record['engine'] ?? 'postgres'),
            'remote_id' => $remoteId,
            'region' => EdgeDplyDatabase::regionOf($record),
            'host' => (string) ($record['host'] ?? ''),
            'size' => (string) ($record['size'] ?? '0.25'),
            'suspend' => (int) ($record['suspend'] ?? 300),
            'disk_gb' => (int) ($record['disk_gb'] ?? EdgeDplyDatabase::DEFAULT_DISK),
            'password' => self::passwordFromEnv($site, (string) ($record['engine'] ?? 'postgres')),
            'state' => null,
        ]);

        $database->sites()->attach($site->id, ['env_name' => self::envName($database->name), 'primary' => true]);
    }

    /** @return array{host: string, port: string, database: string, username: string, password: string} */
    public static function credentials(DplyDatabase $database): array
    {
        return ['host' => $database->host, 'port' => EdgeDplyDatabase::ENGINES[$database->engine][1], 'database' => 'app', 'username' => 'app', 'password' => $database->password];
    }

    /** ANALYTICS, from "analytics" or "Analytics DB". */
    public static function envName(string $name): string
    {
        $env = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_'));
        if ($env === '' || ctype_digit($env[0])) {
            $env = 'DB_'.$env;
        }

        return substr($env, 0, 30);
    }

    /** Record the database's size and settings as the app's meta.edge.database (what the rest of dply reads). */
    private static function mirror(Site $site, DplyDatabase $database): void
    {
        $site->mergeEdgeMeta(['database' => array_filter([
            'engine' => $database->engine,
            'provider' => 'dply',
            'name' => $database->name,
            'status' => 'ready',
            'remote_id' => $database->remote_id,
            'host' => $database->host,
            'region' => $database->region,
            'plan' => $database->suspend === -1 ? 'awake' : 'sleep',
            'size' => $database->size,
            'suspend' => $database->suspend,
            'disk_gb' => $database->disk_gb,
        ], static fn ($v) => $v !== null) + (array) ($database->state ?? [])]);
        $site->save();
    }

    /** Before the mirror changes hands: its settings and panel state go back to the row. */
    private static function keepState(Site $site, DplyDatabase $database): void
    {
        $record = (array) ($site->edgeMeta()['database'] ?? []);
        if (($record['remote_id'] ?? null) !== $database->remote_id) {
            return;
        }
        $database->forceFill([
            'size' => (string) ($record['size'] ?? $database->size),
            'suspend' => (int) ($record['suspend'] ?? $database->suspend),
            'disk_gb' => (int) ($record['disk_gb'] ?? $database->disk_gb),
            'state' => array_intersect_key($record, array_flip(self::STATE_KEYS)) ?: null,
        ])->save();
    }

    private static function writeEnv(Site $site, DplyDatabase $database, string $envName): void
    {
        foreach (EdgeAppDatabase::credentialPairs($database->engine, self::credentials($database), $envName) as $key => $value) {
            EdgeAppDatabase::writeEnv($site, $key, $value);
        }
    }

    private static function forgetEnv(Site $site, string $envName): void
    {
        if ($envName === '') {
            return;
        }
        $site->edgeEnvVars()->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->whereIn('key', array_map(static fn (string $k) => $envName.'_'.$k, EdgeAppDatabase::MANAGED_KEYS))
            ->delete();
    }

    private static function freeEnvName(Site $site, string $name, string $except): string
    {
        $base = self::envName($name);
        $taken = self::for($site)->reject(fn (DplyDatabase $d) => $d->id === $except)->pluck('attached_env_name')->all();
        $env = $base;
        for ($i = 2; in_array($env, $taken, true) || $env === 'DB'; $i++) {
            $env = $base.'_'.$i;
        }

        return $env;
    }

    private static function cleanName(string $name): string
    {
        $name = Str::slug(trim($name));
        if ($name === '' || strlen($name) > 40) {
            throw new RuntimeException('Name the database: letters, numbers and dashes, up to 40.');
        }

        return $name;
    }

    private static function uniqueName(string $organizationId, string $base): string
    {
        $base = substr($base, 0, 36);
        $name = $base;
        for ($i = 2; DplyDatabase::query()->where('organization_id', $organizationId)->where('name', $name)->exists(); $i++) {
            $name = $base.'-'.$i;
        }

        return $name;
    }

    private static function passwordFromEnv(Site $site, string $engine): string
    {
        $env = fn (string $key): string => (string) ($site->edgeEnvVars()->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)->where('key', $key)->first()?->value ?? '');

        return $engine === 'mongodb'
            ? rawurldecode((string) (parse_url($env('MONGODB_URI'), PHP_URL_PASS) ?? ''))
            : $env('DB_PASSWORD');
    }
}
