<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether a provider is exposed in UI and accepted for new credentials / server create.
 */
final class ServerProviderGate
{
    /**
     * @var list<string>
     */
    private const SERVER_CREATE_ORDER = [
        'digitalocean',
        'digitalocean_functions',
        'digitalocean_kubernetes',
        'hetzner',
        'vultr',
        'linode',
        'upcloud',
        'aws',
        'azure',
        'oracle',
        'aws_app_runner',
        'aws_kubernetes',
        'aws_lambda',
        'custom',
    ];

    /**
     * Providers that are surfaced as "coming soon" in the credentials UI — visible in the
     * picker but disabled (no form submission). Set as a constant rather than via env so
     * the placeholder rollout is deterministic in tests.
     *
     * This is every provider the credentials nav knows about that isn't shipping yet, so
     * the picker shows the whole roadmap rather than silently hiding rows: an operator
     * looking for Namecheap should see it listed as coming soon, not conclude dply will
     * never support it. Only {@see visible()} and {@see comingSoon()} read this, and both
     * are used exclusively by the credentials UI — server create and credential
     * acceptance still go through {@see enabled()}, so nothing here becomes usable early.
     *
     * @var list<string>
     */
    private const COMING_SOON = [
        // Hyperscale
        'aws',
        'gcp',
        'azure',
        'oracle',
        // VPS & cloud
        'upcloud',
        // Other providers
        'ovh',
        // DNS & CDN
        'gandi',
        'namecheap',
        'vercel_dns',
        // Platforms
        'ghcr',
        // Migrate from
        'ploi',
        'forge',
    ];

    public static function enabled(string $provider): bool
    {
        $configEnabled = filter_var(
            config('server_providers.enabled.'.$provider, false),
            FILTER_VALIDATE_BOOL
        );

        if (! $configEnabled) {
            return false;
        }

        return true;
    }

    /**
     * Whether the provider is rendered as a "coming soon" placeholder (visible in the
     * credentials nav but no functional add-credential form).
     */
    public static function comingSoon(string $provider): bool
    {
        // A provider enabled in config is never "coming soon".
        if (self::enabled($provider)) {
            return false;
        }

        return in_array($provider, self::COMING_SOON, true);
    }

    /**
     * Visible in the credentials sidebar — either fully enabled, or a "coming soon"
     * placeholder.
     */
    public static function visible(string $provider): bool
    {
        return self::enabled($provider) || self::comingSoon($provider);
    }

    public static function defaultServerCreateType(): string
    {
        foreach (self::SERVER_CREATE_ORDER as $id) {
            if (self::enabled($id)) {
                return $id;
            }
        }

        return self::enabled('custom') ? 'custom' : 'digitalocean';
    }
}
