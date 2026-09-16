<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\ExternalSecretStore;
use App\Models\OrgSecretKey;
use App\Models\Site;
use App\Models\SiteSecretResidency;
use App\Modules\Secrets\Services\External\SecretStoreDriverFactory;
use App\Modules\Secrets\Services\OrgSecretKeyManager;
use RuntimeException;

/**
 * Resolves a site's non-resident secrets — the env vars whose real value is NOT
 * stored as plaintext-under-APP_KEY in dply's database — into their actual
 * values, just-in-time, at the single env-materialization chokepoint
 * ({@see SiteEnvPusher::push()}).
 *
 * The loose `.env` carries only `${dply:secret:<id>}` placeholders for these
 * keys (see {@see SiteSecretResidency::placeholder()}); this resolver swaps each
 * placeholder for the resolved value before the file is rendered and pushed.
 *
 * No-op for the overwhelming common case: a site with no residency rows has no
 * placeholders, so {@see resolve()} returns the map untouched. The per-mode
 * resolution backends land in later PRs (escrow → PR1/PR2, external → PR3/PR4);
 * the match arms below are the seams they fill.
 */
class SecretResidencyResolver
{
    private const PLACEHOLDER_MARKER = '${dply:secret:';

    public function __construct(
        private readonly OrgSecretKeyManager $orgKeys,
        private readonly SecretStoreDriverFactory $stores,
    ) {}

    /**
     * @param  array<string, mixed>  $vars  the merged env map (loose + bindings)
     * @param  string|null  $ephemeralIdentity  a customer-held age identity supplied
     *                                          for THIS push only and never persisted (Tier 2b). Null for every other tier.
     * @return array<string, string>
     */
    public function resolve(Site $site, array $vars, ?string $ephemeralIdentity = null): array
    {
        if (! $this->hasPlaceholder($vars)) {
            return $vars;
        }

        $byPlaceholder = $site->secretResidencies()
            ->get()
            ->keyBy(fn (SiteSecretResidency $r): string => $r->placeholder());

        // The org key is shared by all of a site's escrowed secrets; load it once.
        $orgKey = ($site->organization_id !== null && $byPlaceholder->isNotEmpty())
            ? $this->orgKeys->ensureForOrg($site->organization_id)
            : null;

        $resolved = [];
        foreach ($vars as $key => $value) {
            $resolved[$key] = (($value) && $byPlaceholder->has($value))
                ? $this->resolveOne($byPlaceholder->get($value), $orgKey, $ephemeralIdentity)
                : $value;
        }

        return $resolved;
    }

    /**
     * Whether deploying/pushing this site needs the customer to supply an age
     * identity: it has escrowed (Tier 2) secrets AND the org key is customer-held
     * (dply holds no identity, so it cannot decrypt them on its own).
     */
    public function requiresEphemeralIdentity(Site $site): bool
    {
        if ($site->organization_id === null) {
            return false;
        }
        if (! $site->secretResidencies()->where('mode', SiteSecretResidency::MODE_ESCROW)->exists()) {
            return false;
        }

        $orgKey = $site->organization?->secretKey;

        return $orgKey !== null && ! $orgKey->dplyCanDecrypt();
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function hasPlaceholder(array $vars): bool
    {
        foreach ($vars as $value) {
            if (($value) && str_contains($value, self::PLACEHOLDER_MARKER)) {
                return true;
            }
        }

        return false;
    }

    private function resolveOne(SiteSecretResidency $residency, ?OrgSecretKey $orgKey, ?string $ephemeralIdentity): string
    {
        return match ($residency->mode) {
            SiteSecretResidency::MODE_ESCROW => $this->resolveEscrow($residency, $orgKey, $ephemeralIdentity),
            SiteSecretResidency::MODE_EXTERNAL => $this->resolveExternal($residency),
            default => throw new RuntimeException(
                "Unknown secret residency mode '{$residency->mode}' for '{$residency->key}'."
            ),
        };
    }

    private function resolveExternal(SiteSecretResidency $residency): string
    {
        $store = $residency->store_id !== null ? ExternalSecretStore::find($residency->store_id) : null;
        if ($store === null) {
            throw new RuntimeException("external secret '{$residency->key}' has no store configured.");
        }
        if ($residency->reference === null || $residency->reference === '') {
            throw new RuntimeException("external secret '{$residency->key}' has no reference.");
        }

        // On-box resolution: dply must NEVER fetch the value. When on-box is
        // enabled we leave a directive the server's shim rewrites in place (see
        // SiteEnvPusher::stageOnBoxResolution + dply-resolve-secrets.sh). When it
        // is NOT enabled we fail closed rather than ship an unresolved directive
        // or silently pull the secret into dply.
        if ($store->resolvesOnBox()) {
            throw new RuntimeException(
                "external secret '{$residency->key}' resolves on the server (on-box mode), which dply-edge does not run."
            );
        }

        return $this->stores->for($store)->fetch($store, $residency->reference);
    }

    private function resolveEscrow(SiteSecretResidency $residency, ?OrgSecretKey $orgKey, ?string $ephemeralIdentity): string
    {
        if ($orgKey === null) {
            throw new RuntimeException("escrowed secret '{$residency->key}' has no org key to decrypt with.");
        }
        if ($residency->ciphertext === null || $residency->ciphertext === '') {
            throw new RuntimeException("escrowed secret '{$residency->key}' has no ciphertext.");
        }

        return $this->orgKeys->decrypt($orgKey, $residency->ciphertext, $ephemeralIdentity);
    }
}
