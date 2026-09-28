<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Livewire\Concerns;

use App\Models\Organization;
use App\Models\OrganizationSecret;
use App\Support\Sites\OrganizationSecretException;
use App\Support\Sites\OrganizationSecretManager;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cloud-style vault on the org Secrets page. Values are write-never after save.
 *
 * @phpstan-require-extends Component
 *
 * @property Organization $organization
 */
trait ManagesOrganizationVaultSecrets
{
    #[Url]
    public string $tab = 'secrets';

    public string $vault_key = '';

    public string $vault_value = '';

    public string $vault_notes = '';

    public ?string $rotating_secret_id = null;

    public string $rotate_value = '';

    /** @var list<string> Secret ids ticked for a bulk action. */
    public array $selected_secret_ids = [];

    public function setTab(string $tab): void
    {
        $this->tab = self::normalizeTab($tab);
    }

    /** `residency` is the tab's old name; old links land on Encryption. */
    public static function normalizeTab(string $tab): string
    {
        return match ($tab) {
            'encryption', 'residency' => 'encryption',
            'stores' => 'stores',
            default => 'secrets',
        };
    }

    public function createVaultSecret(OrganizationSecretManager $manager): void
    {
        $this->authorize('update', $this->organization);
        $this->validate([
            'vault_key' => OrganizationSecretManager::KEY_RULE,
            'vault_value' => 'required|string|max:16000',
            'vault_notes' => 'nullable|string|max:2000',
        ]);

        try {
            $manager->create(
                $this->organization,
                $this->vault_key,
                $this->vault_value,
                $this->vault_notes !== '' ? $this->vault_notes : null,
                auth()->user(),
            );
        } catch (OrganizationSecretException $e) {
            $this->addError('vault_notes', $e->getMessage());

            return;
        }

        $this->reset('vault_key', 'vault_value', 'vault_notes');
        $this->dispatch('close-modal', 'new-secret');
        $this->toastSuccess(__('Secret saved. The value cannot be read back — rotate to replace it.'));
    }

    public function startRotateVaultSecret(string $secretId): void
    {
        $this->authorize('update', $this->organization);
        $this->secretForOrg($secretId);
        $this->rotating_secret_id = $secretId;
        $this->rotate_value = '';
    }

    public function cancelRotateVaultSecret(): void
    {
        $this->reset('rotating_secret_id', 'rotate_value');
    }

    public function rotateVaultSecret(OrganizationSecretManager $manager): void
    {
        $this->authorize('update', $this->organization);
        $this->validate([
            'rotate_value' => 'required|string|max:16000',
        ]);

        $secret = $this->secretForOrg((string) $this->rotating_secret_id);
        $manager->rotate($secret, $this->rotate_value);
        $this->reset('rotating_secret_id', 'rotate_value');
        $this->toastSuccess(__('Secret rotated. Linked sites pick up the new value on the next deploy.'));
    }

    public function deleteVaultSecret(string $secretId, OrganizationSecretManager $manager): void
    {
        $this->authorize('update', $this->organization);
        $secret = $this->secretForOrg($secretId);
        $manager->delete($secret);
        $this->toastSuccess(__('Secret deleted. Linked sites will drop the key on the next deploy.'));
    }

    /** Tick or untick every secret currently listed. */
    public function toggleAllVaultSecrets(): void
    {
        $ids = array_column($this->vaultSecretRows(), 'id');

        $this->selected_secret_ids = count($this->selected_secret_ids) === count($ids) ? [] : $ids;
    }

    public function clearVaultSelection(): void
    {
        $this->selected_secret_ids = [];
    }

    /**
     * Delete every ticked secret.
     *
     * Ids are re-resolved through secretForOrg(), so a tampered id from another
     * organization 404s rather than being deleted.
     */
    public function deleteSelectedVaultSecrets(OrganizationSecretManager $manager): void
    {
        $this->authorize('update', $this->organization);

        $ids = array_values(array_unique(array_filter($this->selected_secret_ids)));
        if ($ids === []) {
            return;
        }

        $deleted = 0;
        foreach ($ids as $id) {
            try {
                $manager->delete($this->secretForOrg((string) $id));
                $deleted++;
            } catch (\Throwable) {
                // One bad id must not abandon the rest of the batch.
                continue;
            }
        }

        $this->selected_secret_ids = [];

        $this->toastSuccess(trans_choice(
            '{1} :count secret deleted. Linked sites drop the key on the next deploy.|[2,*] :count secrets deleted. Linked sites drop those keys on the next deploy.',
            $deleted,
            ['count' => $deleted],
        ));
    }

    /**
     * `updated_at` stands in for "last rotated": rotating bumps it (so does a
     * notes edit, which this page doesn't offer).
     *
     * @return list<array{id: string, key: string, notes: ?string, sites_count: int, site_names: list<string>, updated_at: ?Carbon}>
     */
    public function vaultSecretRows(): array
    {
        return OrganizationSecret::query()
            ->forListing()
            ->where('organization_id', $this->organization->id)
            ->with(['sites:id,name'])
            ->orderBy('key')
            ->orderBy('created_at')
            ->get()
            ->map(static fn (OrganizationSecret $secret): array => [
                'id' => $secret->id,
                'key' => $secret->key,
                'notes' => $secret->notes,
                'sites_count' => $secret->sites->count(),
                'site_names' => $secret->sites->pluck('name')->filter()->values()->all(),
                'updated_at' => $secret->updated_at,
            ])
            ->all();
    }

    private function secretForOrg(string $secretId): OrganizationSecret
    {
        return OrganizationSecret::query()
            ->where('organization_id', $this->organization->id)
            ->whereKey($secretId)
            ->firstOrFail();
    }
}
