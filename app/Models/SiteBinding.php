<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      A persisted resource binding for a site: a managed attachment (database,
 *                      redis, queue, object storage, scheduler, workers, publication) that
 *                      contributes connection variables to the deploy environment.
 *                      The connection vars live in {@see $injected_env} (encrypted) and are merged
 *                      into the deployment environment at deploy time only — they are intentionally
 *                      kept out of the editable Variables list so the binding stays the source of
 *                      truth for them.
 * @property array<string, mixed>|null $config
 * @property array<string, mixed> $injected_env
 * @property string|null $last_error
 * @property string $mode
 * @property string|null $name
 * @property ?string $site_id
 * @property string $status
 * @property ?string $target_id
 * @property string|null $target_type
 * @property string $type
 * @property-read ?Site $site
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiteBinding extends Model
{
    use HasUlids;

    public const TYPES = [
        'database',
        'scheduler',
        'workers',
        'publication',
        'redis',
        'queue',
        'storage',
        'cache',
        'session',
        'logging',
        'mail',
        'broadcasting',
        'error_tracking',
        'ai',
        'captcha',
        'sms',
        'search',
        'payments',
        'oauth',
        'connected_app',
    ];

    /**
     * Types a site can hold MORE THAN ONE of — each a distinct instance keyed
     * by `name`, injecting its own (namespaced) env so they don't collide. The
     * primary instance keeps the framework's bare keys (DB_HOST, FILESYSTEM_DISK
     * …); additional named instances inject a prefixed set (DB_<NAME>_*,
     * AWS_<DISK>_* …) plus a config snippet to register the named connection.
     * Every other type collapses to one row per site via the (site_id, type)
     * natural key. Grows as each type's env-namespacing is wired in.
     */
    public const MULTI_INSTANCE_TYPES = [
        'storage',
        'database',
        'redis',
        // Provider-keyed integrations: each provider owns an independent key
        // namespace (no shared selector key), so several DIFFERENT providers
        // coexist on one site without collision — the instance IS the provider.
        // (mail/broadcasting/search/payments are excluded: they share a selector
        // key — MAIL_MAILER / BROADCAST_CONNECTION / SCOUT_DRIVER / CASHIER_* —
        // so they need real per-instance namespacing first.)
        'ai',
        'oauth',
        'sms',
        'captcha',
        // payments: Stripe (STRIPE_*/CASHIER_*) and Paddle (PADDLE_*) share no
        // env keys, so the two providers coexist; the instance is the provider.
        'payments',
        // Mail: ONE primary (default) mailer — any provider or a failover chain —
        // owns MAIL_MAILER + the bare keys. Named secondaries are SMTP/log only
        // (inline-configurable per mailer), injecting MAIL_<NAME>_* + a
        // config/mail.php snippet. API providers (Mailgun/SES/…) read global
        // config/services.php creds, so they can't be a second instance.
        'mail',
        'connected_app',
    ];

    public static function isMultiInstance(string $type): bool
    {
        return in_array($type, self::MULTI_INSTANCE_TYPES, true);
    }

    public const STATUS_CONFIGURED = 'configured';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ERROR = 'error';

    protected $table = 'site_bindings';

    protected $fillable = [
        'site_id',
        'type',
        'mode',
        'status',
        'name',
        'target_type',
        'target_id',
        'injected_env',
        'config',
        'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'injected_env' => 'encrypted:array',
            'config' => 'array',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Connection variables this binding contributes at deploy time.
     *
     * @return array<string, string>
     */
    public function connectionEnv(): array
    {
        $env = $this->injected_env;

        $clean = [];
        foreach ($env as $key => $value) {
            if ($key !== '') {
                $clean[$key] = (string) $value;
            }
        }

        return $clean;
    }

    public function wasProvisionedByDply(): bool
    {
        return $this->mode === 'provision_new';
    }

    /**
     * Dedicated VM this binding is waiting on (database box or Redis-only
     * cache host). Null for managed/on-server placements.
     */
    public function provisionServerId(): ?string
    {
        $config = is_array($this->config) ? $this->config : [];
        $placement = $config['placement'] ?? null;

        $id = match ($placement) {
            'cache_vm' => $config['cache_vm_server_id'] ?? null,
            'dedicated_vm', 'docker_vm' => $config['db_vm_server_id'] ?? null,
            default => $config['cache_vm_server_id'] ?? $config['db_vm_server_id'] ?? null,
        };

        return filled($id) ? (string) $id : null;
    }

    public function isProvisioning(): bool
    {
        return $this->status === self::STATUS_PROVISIONING;
    }

    public function isErrored(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * Operator-facing failure text: the binding's own last_error plus, when
     * this row owns a dedicated VM, the provider / setup error from that box.
     */
    public function displayError(?Server $provisionServer = null): ?string
    {
        $parts = [];
        if (filled($this->last_error)) {
            $parts[] = trim((string) $this->last_error);
        }

        $config = is_array($this->config) ? $this->config : [];
        if (filled($config['last_error'] ?? null)) {
            $fromConfig = trim((string) $config['last_error']);
            if ($fromConfig !== '' && ! in_array($fromConfig, $parts, true)) {
                $parts[] = $fromConfig;
            }
        }

        if ($provisionServer instanceof Server) {
            $meta = is_array($provisionServer->meta) ? $provisionServer->meta : [];
            $provisionError = is_array($meta['provision_error'] ?? null) ? $meta['provision_error'] : [];
            $serverMessage = trim((string) ($provisionError['message'] ?? ''));
            if ($serverMessage !== '' && ! collect($parts)->contains(
                static fn (string $part): bool => str_contains($part, $serverMessage)
            )) {
                $parts[] = $serverMessage;
            }
        }

        return $parts === [] ? null : implode(' — ', $parts);
    }

    /**
     * Whether the detach confirm dialog should offer to delete the underlying
     * resource (database cluster, on-box database, dedicated DB VM, bucket…).
     */
    public function canOfferDeleteOnDetach(): bool
    {
        return $this->deleteOnDetachLabel() !== null;
    }

    public function deleteOnDetachLabel(): ?string
    {
        if ($this->otherBindingConsumers() > 0) {
            return null;
        }

        return match ($this->type) {
            'database' => match ($this->target_type) {
                'server_database' => match ($this->config['placement'] ?? '') {
                    'dedicated_vm' => __('Also destroy the dedicated database server'),
                    'docker_vm' => __('Also destroy the dedicated Docker database server'),
                    'docker' => __('Also remove the Docker container and its volume'),
                    default => __('Also drop this database on the server'),
                },
                'cloud_database' => $this->wasProvisionedByDply()
                    ? __('Also delete the managed database cluster')
                    : null,
                default => null,
            },
            'redis' => match ($this->target_type) {
                'cloud_database' => $this->wasProvisionedByDply()
                    ? __('Also delete the managed Valkey cluster')
                    : null,
                'server_cache_service' => ($this->wasProvisionedByDply() && ($this->config['placement'] ?? '') === 'cache_vm')
                    ? __('Also destroy the dedicated Redis server')
                    : null,
                default => null,
            },
            'storage' => $this->wasProvisionedByDply()
                ? __('Also delete the bucket and its contents')
                : null,
            default => null,
        };
    }

    public function deleteOnDetachHint(): string
    {
        return match ($this->type) {
            'database' => match ($this->target_type) {
                'server_database' => match ($this->config['placement'] ?? '') {
                    'dedicated_vm' => __('Destroys the VM dply provisioned for this database and removes the database row. Cannot be undone.'),
                    'docker_vm' => __('Stops the container, removes its volume, destroys the Docker host VM, and removes the database row. Cannot be undone.'),
                    'docker' => __('Stops and removes the Docker container and its data volume. Cannot be undone.'),
                    default => __('Runs DROP DATABASE on the server and removes the Dply row. Cannot be undone.'),
                },
                'cloud_database' => __('Tears down the managed cluster at the provider and removes the Dply record. Cannot be undone.'),
                default => '',
            },
            'redis' => match ($this->target_type) {
                'cloud_database' => __('Tears down the managed cluster at the provider and removes the Dply record. Cannot be undone.'),
                'server_cache_service' => __('Destroys the Redis server dply provisioned for this binding. Cannot be undone.'),
                default => '',
            },
            'storage' => __('Empties and deletes the bucket dply provisioned for this disk. Cannot be undone.'),
            default => '',
        };
    }

    /**
     * Other sites that bind the same target resource (shared databases, etc.).
     */
    public function otherBindingConsumers(): int
    {
        if (! filled($this->target_type) || ! filled($this->target_id) || ! filled($this->site_id)) {
            return 0;
        }

        return (int) SiteBinding::query()
            ->where('target_type', $this->target_type)
            ->where('target_id', $this->target_id)
            ->where('site_id', '!=', $this->site_id)
            ->distinct()
            ->count('site_id');
    }
}
