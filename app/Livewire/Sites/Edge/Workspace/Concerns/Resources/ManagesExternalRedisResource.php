<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace\Concerns\Resources;

use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeSiteEnvVar;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeValkey;
use App\Support\Http\PublicOutboundUrl;
use App\Support\Http\UnsafeOutboundUrlException;
use Livewire\Attributes\Computed;

/**
 * Resources sheet: A Redis the operator pasted (not a dply Valkey). Mixed into
 * {@see Resources}; the open connection
 * is $this->openResourceConnection() (set by openResource()).
 */
trait ManagesExternalRedisResource
{
    /** A new redis:// or rediss:// address, typed in the sheet. Never filled from the stored one. */
    public string $externalRedisUrl = '';

    /** @var array{ok: bool, message: string}|null */
    public ?array $externalRedisTest = null;

    /**
     * Env the app gets from this Redis, password masked. Empty while asleep.
     *
     * @return list<array{key: string, value: string, from: string}>
     */
    #[Computed]
    public function externalRedisEnv(): array
    {
        return EdgeContainerConnections::redisInjectionPreview($this->site);
    }

    /** Point the app at a different address. Same storage as a pasted Redis on create. */
    public function replaceExternalRedisUrl(): void
    {
        $this->authorize('update', $this->site);
        $this->resetErrorBag('externalRedis');
        if ($this->externalRedisConnection() === null) {
            return;
        }
        $url = trim($this->externalRedisUrl);
        if (strlen($url) > 2048 || preg_match('#^rediss?://\S+$#', $url) !== 1 || (parse_url($url)['host'] ?? '') === '') {
            $this->addError('externalRedis', __('Paste a redis:// or rediss:// address.'));

            return;
        }
        $this->writeRedisEnv($url);
        $this->externalRedisUrl = '';
        $this->externalRedisTest = null;
        unset($this->externalRedisEnv);
        $this->toastSuccess(__('Saved. The app uses the new address on the next deploy.'));
    }

    /**
     * AUTH and PING the stored address from here. The host must resolve to a
     * public address; the socket is pinned to it so DNS cannot move it inward.
     */
    public function testExternalRedis(): void
    {
        $this->authorize('update', $this->site);
        $this->externalRedisTest = null;
        if ($this->externalRedisConnection() === null) {
            return;
        }
        $url = (string) $this->site->edgeEnvVars()
            ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->where('key', 'REDIS_URL')
            ->first()?->value;
        $parts = parse_url($url) ?: [];
        if (! isset($parts['host'])) {
            $this->externalRedisTest = ['ok' => false, 'message' => __('No Redis address is saved on this app.')];

            return;
        }
        $tls = ($parts['scheme'] ?? '') === 'rediss';
        $port = (int) ($parts['port'] ?? 6379);

        try {
            $target = PublicOutboundUrl::parse('https://'.$parts['host'].':'.$port);
        } catch (UnsafeOutboundUrlException $e) {
            $this->externalRedisTest = ['ok' => false, 'message' => $e->getMessage()];

            return;
        }

        $this->externalRedisTest = self::externalRedisPing(
            $target->host,
            str_contains($target->pinIp, ':') ? '['.$target->pinIp.']' : $target->pinIp,
            $port,
            $tls,
            rawurldecode((string) ($parts['user'] ?? '')),
            rawurldecode((string) ($parts['pass'] ?? '')),
        );
    }

    /** @return array{kind: string, name: string, host: string, target: string, asleep: bool, plan: string, read_regions: int}|null */
    private function externalRedisConnection(): ?array
    {
        $connection = $this->openResourceConnection();

        return $connection !== null && $connection['kind'] === 'redis' && ! EdgeValkey::isTarget($connection['target']) ? $connection : null;
    }

    /**
     * One AUTH (when there is a password) and one PING over RESP.
     *
     * @return array{ok: bool, message: string}
     */
    private static function externalRedisPing(string $host, string $ip, int $port, bool $tls, string $user, string $password): array
    {
        $context = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true]]);
        $start = hrtime(true);
        $socket = @stream_socket_client(($tls ? 'tls' : 'tcp')."://{$ip}:{$port}", $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return ['ok' => false, 'message' => __('Could not connect to :host:port. :error', ['host' => $host, 'port' => $port, 'error' => $error])];
        }
        stream_set_timeout($socket, 5);
        $send = static function (string ...$args) use ($socket): string {
            $payload = '*'.count($args)."\r\n";
            foreach ($args as $arg) {
                $payload .= '$'.strlen($arg)."\r\n".$arg."\r\n";
            }
            fwrite($socket, $payload);

            return rtrim((string) fgets($socket), "\r\n");
        };

        try {
            if ($password !== '') {
                $auth = $user !== '' && $user !== 'default' ? $send('AUTH', $user, $password) : $send('AUTH', $password);
                if (! str_starts_with($auth, '+')) {
                    return ['ok' => false, 'message' => __('Connected, but the password was refused: :reply', ['reply' => ltrim($auth, '-') ?: __('no reply')])];
                }
            }
            $pong = $send('PING');
            if ($pong !== '+PONG') {
                return ['ok' => false, 'message' => __('Connected, but PING answered :reply', ['reply' => ltrim($pong, '-') ?: __('nothing')])];
            }
        } finally {
            fclose($socket);
        }

        return ['ok' => true, 'message' => __('Connected and answered PING in :ms ms.', ['ms' => round((hrtime(true) - $start) / 1e6, 1)])];
    }
}
