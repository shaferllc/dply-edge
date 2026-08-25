<?php

declare(strict_types=1);

namespace Tests\Unit\SecretReencryptCoverageTest;

use Illuminate\Support\Facades\File;

/**
 * Existential guard for APP_KEY rotation (secrets:reencrypt).
 *
 * `encrypted`-cast columns are auto-discovered by the rotation command, so they
 * can't be missed. RAW Crypt::encryptString / encrypt() writes are NOT
 * discoverable — if one persists to a column that isn't in
 * config/secret_vault.php (raw_crypt / json_crypt), rotation would silently
 * leave it on the old key and it would break the moment the old key is dropped.
 *
 * This tripwire enumerates every encrypt() write site in app/ and asserts each
 * is classified. When it fails you added a new one — classify it:
 *   • persists to a column → add to secret_vault raw_crypt/json_crypt AND here.
 *   • transient / age / the engine itself → add here with a note.
 */

/**
 * @return list<string> relative paths under app/ that contain an encrypt write
 */
function encryptWriteSites(): array
{
    // Crypt::encrypt[String](  |  ->encrypt(  |  bare encrypt() helper.
    $pattern = '/(Crypt::encrypt(String)?\(|->encrypt\(|(?<![A-Za-z_>:\\\\])encrypt\()/';

    $found = [];
    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (preg_split('/\r?\n/', (string) file_get_contents($file->getPathname())) ?: [] as $line) {
            $trimmed = ltrim($line);
            // Skip comments and any decrypt-side line.
            if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            if (str_contains($line, 'decrypt')) {
                continue;
            }
            if (preg_match($pattern, $line)) {
                $found[] = str_replace(base_path().'/', '', $file->getPathname());
                break;
            }
        }
    }

    sort($found);

    return $found;
}

test('every encrypt() write site is classified for rotation coverage', function (): void {
    // Each entry is either covered by config/secret_vault.php (registry) or is
    // not APP_KEY at-rest data (age / engine / trait).
    $classified = [
        // --- registry-covered (config/secret_vault.php) ---
        'app/Actions/Servers/BuildServerProvisionMeta.php' => 'json_crypt: servers.meta.{cache,database}_server.password_encrypted',
        'app/Livewire/Auth/TwoFactorChallenge.php' => 'raw_crypt: users.two_factor_recovery_codes',
        'app/Livewire/Servers/Concerns/ManagesServerWebhook.php' => 'json_crypt: servers.meta.server_event_webhook_secret',
        'app/Livewire/TwoFactor/Page.php' => 'raw_crypt: users.two_factor_secret/recovery_codes',
        'app/Modules/Deploy/Services/EphemeralDeployCredentialManager.php' => 'raw_crypt: site_deployment_ephemeral_credentials.private_key_encrypted',
        'app/Services/Servers/ServerMetricsGuestPushService.php' => 'json_crypt: servers.meta.monitoring_guest_push_cipher',

        // --- not APP_KEY at-rest data (no registry entry needed) ---
        'app/Actions/Concerns/AsEncrypted.php' => 'trait definition; not used to persist in-app',
        'app/Modules/Secrets/Console/SecretsReencryptCommand.php' => 'the rotation engine itself',
        'app/Modules/Secrets/Services/AgeEncryptor.php' => 'age encryption, not APP_KEY',
        'app/Modules/Secrets/Services/SecretVault.php' => 'delegates to age, not APP_KEY',
    ];

    expect(encryptWriteSites())->toEqualCanonicalizing(array_keys($classified));
});

test('secret_vault rotation registry is well-formed', function (): void {
    foreach ((array) config('secret_vault.reencrypt.raw_crypt') as $t) {
        expect($t['table'] ?? '')->not->toBe('');
        expect($t['columns'] ?? [])->toBeArray()->not->toBeEmpty();
    }
    foreach ((array) config('secret_vault.reencrypt.json_crypt') as $t) {
        expect($t['table'] ?? '')->not->toBe('');
        expect($t['column'] ?? '')->not->toBe('');
        expect($t['paths'] ?? [])->toBeArray()->not->toBeEmpty();
    }
});
