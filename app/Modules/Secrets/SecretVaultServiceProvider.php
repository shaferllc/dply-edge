<?php

declare(strict_types=1);

namespace App\Modules\Secrets;

use App\Modules\Secrets\Services\AgeEncryptor;
use App\Modules\Secrets\Services\EphemeralSecretIdentityContext;
use App\Modules\Secrets\Services\SecretVault;
use App\Modules\Secrets\Services\Stores\GitOpsRepoVaultStore;
use App\Modules\Secrets\Services\Stores\ObjectStorageVaultStore;
use App\Modules\Secrets\Services\Stores\OnePasswordVaultStore;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class SecretVaultServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\SecretsEscrowCommand::class,
                Console\SecretsOrgKeyCommand::class,
                Console\SecretsReencryptCommand::class,
                Console\SecretsResidencyCommand::class,
                Console\SecretsRestoreCommand::class,
                Console\SecretsRestoreDrillCommand::class,
                Console\SecretsVerifyCanaryCommand::class,
            ]);
        }

        // The single crypto seam — shared by the platform DR path (SecretVault)
        // and the per-org secret-residency path (OrgSecretKeyManager). Stateless,
        // so a singleton is fine.
        // Job-scoped holder for a customer-supplied identity (deploy path). One
        // per container so the deploy job and the env push it triggers share it.
        $this->app->singleton(EphemeralSecretIdentityContext::class);

        $this->app->singleton(AgeEncryptor::class, function (): AgeEncryptor {
            $cfg = (array) config('secret_vault');

            return new AgeEncryptor(
                ageBin: (string) ($cfg['age_bin'] ?? 'age'),
                recipientsPath: (string) ($cfg['recipients_path'] ?? ''),
                identityPath: $cfg['identity_path'] ?? null,
                keygenBin: (string) ($cfg['age_keygen_bin'] ?? 'age-keygen'),
            );
        });

        // Transient so each resolution gets a fresh UTC stamp for the blob key.
        $this->app->bind(SecretVault::class, function (): SecretVault {
            $cfg = (array) config('secret_vault');

            // Order = read preference (object primary, then git, then 1Password).
            $stores = [
                new ObjectStorageVaultStore((array) ($cfg['stores']['object'] ?? [])),
                new GitOpsRepoVaultStore((array) ($cfg['stores']['git'] ?? [])),
                new OnePasswordVaultStore((array) ($cfg['stores']['onepassword'] ?? [])),
            ];

            return new SecretVault(
                age: $this->app->make(AgeEncryptor::class),
                stores: $stores,
                keyPrefix: trim((string) ($cfg['key_prefix'] ?? 'secret-vault/v1'), '/'),
                utcStamp: gmdate('Ymd\THis\Z'),
            );
        });
    }

    public function boot(): void
    {
        // Full-page org Secrets page, moved out of App\Livewire — register under
        // its original auto-derived name for route ::class resolution.
        // Fully qualified: `Livewire\Secrets::class` resolved against the
        // `use Livewire\Livewire` import above, yielding Livewire\Livewire\Secrets
        // — a class that does not exist, so this alias pointed at nothing.
        Livewire::component('organizations.secrets', \App\Modules\Secrets\Livewire\Secrets::class);
    }
}
