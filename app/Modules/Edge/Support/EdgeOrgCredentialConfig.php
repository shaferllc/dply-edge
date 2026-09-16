<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\ProviderCredential;

/**
 * Edge infra metadata stored on org Cloudflare provider credentials.
 *
 * Required API token scopes (Account):
 * - Workers Scripts → Edit
 * - Workers KV Storage → Edit
 * - Workers R2 Storage → Edit
 *
 * R2 S3 access keys are created in the Cloudflare dashboard and saved here
 * via `dply:edge:bootstrap-org --r2-access-key=... --r2-secret=...`.
 */
final class EdgeOrgCredentialConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function read(ProviderCredential $credential): array
    {
        $creds = $credential->credentials;
        $edge = $creds['edge'] ?? [];

        return is_array($edge) ? $edge : [];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function merge(ProviderCredential $credential, array $values): ProviderCredential
    {
        $creds = $credential->credentials;
        $existing = self::read($credential);

        $credential->credentials = array_merge($creds, [
            'edge' => array_merge($existing, $values),
        ]);
        $credential->save();

        return $credential->refresh();
    }

    public static function isBootstrapped(ProviderCredential $credential): bool
    {
        try {
            return EdgeDeliveryContext::fromProviderCredential($credential)->isBootstrapped();
        } catch (\Throwable) {
            return false;
        }
    }
}
