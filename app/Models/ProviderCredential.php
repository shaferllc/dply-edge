<?php

namespace App\Models;

use App\Enums\ServerProvider;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Factories\ProviderCredentialFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Http;

/**
 * @property string $id
 * @property array<string, mixed> $credentials
 * @property ?string $name
 * @property ?string $organization_id
 * @property string $provider
 * @property ?string $user_id
 * @property \Illuminate\Support\Carbon|null $last_validated_at
 * @property ?string $validation_error
 * @property-read ?User $user
 * @property-read ?Organization $organization
 * @property-read Collection<int, Server> $servers
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ProviderCredential extends Model
{
    /** @use HasFactory<ProviderCredentialFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'organization_id',
        'provider',
        'name',
        'credentials',
        'last_validated_at',
        'validation_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'last_validated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'provider_credential_id');
    }

    public function isUnhealthy(): bool
    {
        return filled($this->validation_error);
    }

    public static function newestForOrganization(?string $organizationId, string $provider): ?self
    {
        if (! filled($organizationId) || trim($provider) === '') {
            return null;
        }

        return static::query()
            ->where('organization_id', $organizationId)
            ->where('provider', $provider)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Newest credential that still authenticates. Does not fall back to a
     * rejected row — callers that must not POST a known-bad token use this.
     */
    public static function newestHealthyForOrganization(?string $organizationId, string $provider): ?self
    {
        if (! filled($organizationId) || trim($provider) === '') {
            return null;
        }

        return static::query()
            ->where('organization_id', $organizationId)
            ->where('provider', $provider)
            ->whereNull('validation_error')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Newest credential that still authenticates. Falls back to the newest
     * row (even if rejected) so a picker has something to show.
     */
    public static function preferredHealthyForOrganization(?string $organizationId, string $provider): ?self
    {
        return static::newestHealthyForOrganization($organizationId, $provider)
            ?? static::newestForOrganization($organizationId, $provider);
    }

    public static function preferredForServer(Server $server): ?self
    {
        return static::newestForOrganization((string) $server->organization_id, $server->provider->value)
            ?? $server->providerCredential;
    }

    public function getApiToken(): ?string
    {
        if ($this->provider === 'digitalocean') {
            $this->refreshDigitalOceanOAuthTokenIfExpired();
        }

        $creds = $this->credentials ?? [];

        return $creds['api_token'] ?? $creds['access_token'] ?? $creds['token'] ?? null;
    }

    /**
     * Refresh DigitalOcean OAuth access token when near expiry (keeps Bearer API calls working).
     */
    public function refreshDigitalOceanOAuthTokenIfExpired(): void
    {
        if ($this->provider !== 'digitalocean') {
            return;
        }

        $creds = $this->credentials ?? [];
        if (($creds['auth'] ?? '') !== 'oauth') {
            return;
        }

        $expiresAtRaw = $creds['expires_at'] ?? null;
        $expiresAt = is_string($expiresAtRaw) ? Carbon::parse($expiresAtRaw) : null;
        $refreshToken = $creds['refresh_token'] ?? null;

        if ($expiresAt instanceof CarbonInterface && now()->addSeconds(120)->lt($expiresAt)) {
            return;
        }

        if (! is_string($refreshToken) || $refreshToken === '') {
            return;
        }

        $clientId = config('services.digitalocean_oauth.client_id');
        $clientSecret = config('services.digitalocean_oauth.client_secret');
        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            return;
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post('https://cloud.digitalocean.com/v1/oauth/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        if (! $response->successful()) {
            return;
        }

        $access = $response->json('access_token');
        if (! is_string($access) || $access === '') {
            return;
        }

        $newRefresh = $response->json('refresh_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        $this->credentials = array_merge($creds, [
            'access_token' => $access,
            'refresh_token' => is_string($newRefresh) && $newRefresh !== '' ? $newRefresh : $refreshToken,
            'expires_at' => now()->addSeconds(max(60, $expiresIn))->toIso8601String(),
        ]);
        $this->save();
    }

    /**
     * Provider keys that can manage DNS for sites (may differ from where servers are hosted).
     * Delegates to {@see ServerProvider::dnsProviderKeys()} so the canonical capability
     * taxonomy lives on the enum.
     *
     * @return list<string>
     */
    public static function dnsAutomationProviderKeys(): array
    {
        return ServerProvider::dnsProviderKeys();
    }

    public function supportsDnsAutomation(): bool
    {
        return ServerProvider::tryFrom($this->provider)?->supportsDns() ?? false;
    }

    public function supportsCompute(): bool
    {
        return ServerProvider::tryFrom($this->provider)?->supportsCompute() ?? false;
    }

    /**
     * Capability tags for badge rendering on credential rows.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        return ServerProvider::tryFrom($this->provider)?->capabilities() ?? [];
    }

    public function dnsProviderLabel(): string
    {
        $enum = ServerProvider::tryFrom($this->provider);

        return $enum?->label() ?? $this->provider;
    }
}
