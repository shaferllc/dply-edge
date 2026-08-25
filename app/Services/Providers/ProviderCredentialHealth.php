<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Models\ProviderCredential;
use App\Modules\Providers\Cloudflare\CloudflareDnsService;
use App\Modules\Providers\Cloudflare\CloudflareEdgeCredentialValidator;
use App\Modules\Providers\Services\AwsEc2Service;
use App\Modules\Providers\Services\AzureComputeService;
use App\Modules\Providers\Services\DigitalOceanService;
use App\Modules\Providers\Services\GcpDnsService;
use App\Modules\Providers\Services\HetznerService;
use App\Modules\Providers\Services\LinodeService;
use App\Modules\Providers\Services\OracleComputeService;
use App\Modules\Providers\Services\OvhService;
use App\Modules\Providers\Services\UpCloudService;
use App\Modules\Providers\Services\VultrService;
use App\Modules\Edge\Support\EdgeOrgCredentialConfig;
use App\Support\Providers\ProviderAuthFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Validates a stored cloud/DNS/import token against its provider and stamps
 * last_validated_at / validation_error. Same idea as Git token health: catch
 * revoked keys before the next droplet create fails.
 *
 * Network failures are not recorded — an unreachable API says nothing about
 * the token. Only a definitive provider rejection is marked unhealthy.
 */
class ProviderCredentialHealth
{
    /** Skip a live probe when a check ran this recently (point-of-use reuse). */
    public const FRESH_FOR_SECONDS = 120;

    public function supports(string $provider): bool
    {
        return in_array($provider, [
            'digitalocean', 'cloudflare', 'hetzner', 'linode', 'vultr',
            'upcloud', 'aws', 'gcp', 'azure', 'oracle', 'ploi', 'forge', 'ovh',
        ], true);
    }

    /**
     * Validate and stamp. True = healthy, false = provider rejected it,
     * null = could not run (unsupported or transport failure).
     */
    public function refresh(ProviderCredential $credential, bool $force = false): ?bool
    {
        if (! $this->supports($credential->provider)) {
            return null;
        }

        if (! $force && $this->isFresh($credential)) {
            return blank($credential->validation_error);
        }

        try {
            $this->probe($credential);
        } catch (Throwable $e) {
            if ($this->isTransportFailure($e)) {
                return null;
            }

            $credential->forceFill([
                'last_validated_at' => now(),
                'validation_error' => Str::limit(trim($e->getMessage()), 500) ?: __('The provider rejected this credential.'),
            ])->save();

            return false;
        }

        $this->markHealthy($credential);

        return true;
    }

    public function markHealthy(ProviderCredential $credential): void
    {
        $credential->forceFill([
            'last_validated_at' => now(),
            'validation_error' => null,
        ])->save();
    }

    public function markUnhealthy(ProviderCredential $credential, string $message): void
    {
        $credential->forceFill([
            'last_validated_at' => now(),
            'validation_error' => Str::limit(trim($message), 500),
        ])->save();
    }

    private function isFresh(ProviderCredential $credential): bool
    {
        return $credential->last_validated_at?->gt(now()->subSeconds(self::FRESH_FOR_SECONDS)) ?? false;
    }

    private function probe(ProviderCredential $credential): void
    {
        match ($credential->provider) {
            'digitalocean' => (new DigitalOceanService($credential))->validateToken(),
            'cloudflare' => EdgeOrgCredentialConfig::isBootstrapped($credential)
                ? (new CloudflareEdgeCredentialValidator)->validate($credential)
                : (new CloudflareDnsService($credential))->verifyToken(),
            'hetzner' => (new HetznerService($credential))->validateToken(),
            'linode' => (new LinodeService($credential))->validateToken(),
            'vultr' => (new VultrService($credential))->validateToken(),
            'upcloud' => (new UpCloudService($credential))->validateToken(),
            'ovh' => (new OvhService($credential))->validateToken(),
            'aws' => (new AwsEc2Service($credential))->validateCredentials(),
            'gcp' => (new GcpDnsService($credential))->validateCredentials(),
            'azure' => (new AzureComputeService($credential))->validateCredentials(),
            'oracle' => (new OracleComputeService($credential))->validateCredentials(),
            default => throw new \RuntimeException(__('Unknown provider.')),
        };
    }

    private function isTransportFailure(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if (ProviderAuthFailure::detected($e->getMessage())) {
            return false;
        }

        $message = strtolower(trim($e->getMessage()));

        return str_contains($message, 'timed out')
            || str_contains($message, 'could not resolve')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'cURL error');
    }
}
