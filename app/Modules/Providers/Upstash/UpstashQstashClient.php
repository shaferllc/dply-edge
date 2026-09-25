<?php

declare(strict_types=1);

namespace App\Modules\Providers\Upstash;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Platform account for HTTP delivery. One account serves every app; the
 * worker publishes with the token, and the app never sees it.
 *
 * Called from EdgeContainerConnections. API: GET /qstash/users,
 * POST /qstash/set-plan/{id}.
 * User request: "ok then lets build that out and we need to charge for it".
 */
final class UpstashQstashClient
{
    public function __construct(
        private readonly string $email,
        private readonly string $apiKey,
    ) {}

    public static function configured(): bool
    {
        return (string) config('edge.upstash.email') !== ''
            && (string) config('edge.upstash.api_key') !== ''
            && (string) config('edge.upstash.qstash_token') !== '';
    }

    public static function fromConfig(): self
    {
        $email = (string) config('edge.upstash.email');
        $apiKey = (string) config('edge.upstash.api_key');
        if ($email === '' || $apiKey === '' || (string) config('edge.upstash.qstash_token') === '') {
            throw new \RuntimeException('HTTP delivery cannot be started from here yet.');
        }

        return new self($email, $apiKey);
    }

    /**
     * Put the account on pay as you go and return its id.
     */
    public function ensurePaid(): string
    {
        $users = $this->http()->get('/qstash/users')->throw()->json();
        $user = is_array($users) ? ($users[0] ?? null) : null;
        if (! is_array($user) || (string) ($user['id'] ?? '') === '') {
            throw new \RuntimeException('HTTP delivery cannot be started from here yet.');
        }
        $id = (string) $user['id'];
        if ((string) ($user['type'] ?? '') !== 'paid' && (string) ($user['reserved_type'] ?? '') === '') {
            $this->http()->post('/qstash/set-plan/'.$id, ['plan_name' => 'paid'])->throw();
        }

        return $id;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl('https://api.upstash.com/v2')
            ->withBasicAuth($this->email, $this->apiKey)
            ->acceptJson()
            ->asJson();
    }
}
