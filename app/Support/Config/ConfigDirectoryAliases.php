<?php

declare(strict_types=1);

namespace App\Support\Config;

/**
 * Maps legacy top-level config keys onto the nested trees loaded from
 * config/product/*, config/servers/*, and the other domain folders.
 *
 * Laravel turns config/servers/logs.php into config('servers.logs'). Callers
 * still use config('server_logs') — this copies each nested tree back onto
 * the old key after the file loader runs. Files are required (not read from
 * the already-aliased repository) so parent keys like insights → insights.core
 * stay idempotent. config:cache already contains both keys, so apply() is a
 * no-op when the configuration is cached.
 */
final class ConfigDirectoryAliases
{
    /**
     * Legacy key => nested key after the folder move.
     *
     * @var array<string, string>
     */
    public const MAP = [
        'dply_runtime' => 'product.runtime',
        'admin' => 'product.admin',
        'api_token_permissions' => 'product.api_token_permissions',
        'audit' => 'product.audit',
        'bundle' => 'product.bundle',
        'cli' => 'product.cli',
        'console_actions' => 'product.console_actions',
        'dply' => 'product.dply',
        'edge' => 'product.edge',
        'lookout' => 'product.lookout',
        'passkeys' => 'product.passkeys',
        'preview' => 'product.preview',
        'profile_options' => 'product.profile_options',
        'quick_download' => 'product.quick_download',
        'secret_vault' => 'product.secret_vault',
        'solo' => 'product.solo',
        'subscription' => 'product.subscription',
        'testing_domains' => 'product.testing_domains',
        'user_preferences' => 'product.user_preferences',
        'vat' => 'product.vat',
        'sites' => 'sites.core',
        'site_settings' => 'sites.settings',
        'site_uptime' => 'sites.uptime',
        'notifications' => 'notifications.core',
        'notification_channels' => 'notifications.channels',
        'notification_events' => 'notifications.events',
    ];

    /**
     * Root config/*.php files that may remain (Laravel, packages, Pennant landmines).
     *
     * @var list<string>
     */
    public const ROOT_ALLOW_LIST = [
        'admin_feature_flags.php',
        'app.php',
        'auth.php',
        'blade-icons.php',
        'broadcasting.php',
        'cache.php',
        'database.php',
        'debugbar.php',
        'features.php',
        'filesystems.php',
        'horizon.php',
        'logging.php',
        'mail.php',
        'octane.php',
        'pennant.php',
        'pulse.php',
        'queue.php',
        'reverb.php',
        'services.php',
        'session.php',
    ];

    public static function pathForNestedKey(string $nested): string
    {
        $parts = explode('.', $nested);
        $file = array_pop($parts);

        return config_path(implode(DIRECTORY_SEPARATOR, [...$parts, $file]).'.php');
    }

    public static function apply(): void
    {
        if (app()->configurationIsCached()) {
            return;
        }

        foreach (self::MAP as $legacy => $nested) {
            config()->set($legacy, require self::pathForNestedKey($nested));
        }
    }
}
