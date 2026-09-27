<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace\Concerns\Resources;

use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;

/**
 * Resources sheet: Database pool (Hyperdrive). Mixed into
 * {@see Resources}; the open connection
 * is $this->openResourceConnection() (set by openResource()).
 */
trait ManagesPoolResource
{
    /** Add a resource → Database pool: 'app' (this app's dply database) or 'url'. */
    public string $poolSource = 'app';

    /** A pasted postgres:// or mysql:// address. Sent to the pool, never stored here. */
    public string $poolOriginUrl = '';

    /** @var array{host: string, database: string, user: string, scheme: string, caching: bool}|null */
    public ?array $poolInfo = null;

    public ?string $poolError = null;

    /** 'postgres' or 'mysql' when this app has a dply database a pool can point at, else ''. */
    public function poolAppEngine(): string
    {
        return (string) ($this->poolAppDatabase()['engine'] ?? '');
    }

    /** @return array<string, mixed>|null */
    private function poolAppDatabase(): ?array
    {
        $record = $this->dplyDatabaseRecord();

        return $record !== null && in_array($record['engine'] ?? '', ['postgres', 'mysql'], true) ? $record : null;
    }

    /**
     * The origin for a new pool. Throws with a message for the builder.
     *
     * @return array{origin: array{host: string, port: int, database: string, user: string, password: string, scheme: string}}
     */
    protected function poolProvisionOptions(): array
    {
        // Without a dply database the builder only offers the pasted address.
        $record = $this->poolAppDatabase();
        if ($this->poolSource === 'app' && $record !== null) {
            $password = $this->databasePassword();
            if ($password === '') {
                throw new \InvalidArgumentException(__('This app\'s database password is not in its env, so a pool cannot sign in. Paste an address instead.'));
            }
            $engine = (string) $record['engine'];

            return ['origin' => [
                'host' => (string) $record['host'],
                'port' => (int) EdgeDplyDatabase::ENGINES[$engine][1],
                'database' => 'app',
                'user' => 'app',
                'password' => $password,
                'scheme' => $engine,
            ]];
        }

        $url = trim($this->poolOriginUrl);
        $this->poolOriginUrl = '';
        $parts = strlen($url) <= 2048 ? parse_url($url) : false;
        $scheme = match (is_array($parts) ? ($parts['scheme'] ?? '') : '') {
            'postgres', 'postgresql' => 'postgres',
            'mysql' => 'mysql',
            default => '',
        };
        $database = is_array($parts) ? rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/')) : '';
        if ($scheme === '' || ($parts['host'] ?? '') === '' || ($parts['user'] ?? '') === '' || ($parts['pass'] ?? '') === '' || $database === '') {
            throw new \InvalidArgumentException(__('Paste a postgres:// or mysql:// address with a user, password, host, and database.'));
        }

        return ['origin' => [
            'host' => (string) $parts['host'],
            'port' => (int) ($parts['port'] ?? ($scheme === 'mysql' ? 3306 : 5432)),
            'database' => $database,
            'user' => rawurldecode((string) $parts['user']),
            'password' => rawurldecode((string) $parts['pass']),
            'scheme' => $scheme,
        ]];
    }

    /** Overview for the open pool. Only pools this organization created are read. */
    public function loadPool(): void
    {
        $this->authorize('view', $this->site);
        $this->reset('poolInfo', 'poolError');
        $connection = $this->openResourceConnection();
        if ($connection === null || $connection['kind'] !== 'database_pool' || $this->site->organization === null) {
            return;
        }
        $notOurs = __('This pool was not created by this organization, so it is not read from here. Delete this resource and create one to see it.');
        // Same shape check as EdgeContainerConnections::owns, before the id reaches an API path.
        if (preg_match('/^[a-f0-9]{32}$/', $connection['target']) !== 1) {
            $this->poolError = $notOurs;

            return;
        }
        try {
            $config = EdgeCloudflareClient::fromConfig()->getHyperdriveConfig($connection['target']);
        } catch (\Throwable $e) {
            $this->poolError = $e->getMessage();

            return;
        }
        if (! str_starts_with((string) ($config['name'] ?? ''), EdgeContainerConnections::ownedPrefix($this->site->organization))) {
            $this->poolError = $notOurs;

            return;
        }
        $host = (string) ($config['origin']['host'] ?? '');
        $this->poolInfo = [
            // The first label is the database's own id; the rest says where it is.
            'host' => $host !== '' ? (string) preg_replace('/^[^.]+/', '••••', $host) : '',
            'database' => (string) ($config['origin']['database'] ?? ''),
            'user' => (string) ($config['origin']['user'] ?? ''),
            'scheme' => (string) ($config['origin']['scheme'] ?? ''),
            'caching' => ! (bool) ($config['caching']['disabled'] ?? false),
        ];
    }
}
