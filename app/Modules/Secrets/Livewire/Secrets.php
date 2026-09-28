<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Livewire;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ExternalSecretStore;
use App\Models\Organization;
use App\Models\OrgSecretKey;
use App\Modules\Secrets\Livewire\Concerns\ManagesOrganizationResidencyKey;
use App\Modules\Secrets\Livewire\Concerns\ManagesOrganizationVaultSecrets;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Laravel\Head\Facades\Head;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org Secrets, three tabs: Shared secrets (the vault — values are write-never
 * after save), Encryption (who holds the org age key) and External stores.
 *
 * Livewire exposes get<Name>Property() methods as $this-><name> in PHP and
 * Blade. PHPStan cannot see that magic, so the contract is stated here.
 *
 * @property-read Collection<int, ExternalSecretStore> $stores
 * @property-read OrgSecretKey|null $orgKey
 */
#[Layout('layouts.app')]
class Secrets extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesOrganizationResidencyKey;
    use ManagesOrganizationVaultSecrets;

    public Organization $organization;

    /** New external store form. */
    public string $store_driver = ExternalSecretStore::DRIVER_VAULT;

    public string $store_name = '';

    public string $store_resolution = ExternalSecretStore::RESOLUTION_DPLY;

    /** @var array<string, string> driver-shaped connection config */
    public array $store_form = [];

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        Head::title($organization->name.' · '.__('Secrets'));
        $this->organization = $organization;
    }

    public function createStore(): void
    {
        $this->authorize('update', $this->organization);
        $this->validate([
            'store_driver' => ['required', 'in:'.implode(',', ExternalSecretStore::DRIVERS)],
            'store_name' => ['required', 'string', 'max:120'],
            'store_resolution' => ['required', 'in:'.ExternalSecretStore::RESOLUTION_DPLY.','.ExternalSecretStore::RESOLUTION_ONBOX],
        ]);

        ExternalSecretStore::create([
            'organization_id' => $this->organization->id,
            'driver' => $this->store_driver,
            'name' => $this->store_name,
            'config' => array_filter($this->store_form, fn ($v): bool => $v !== ''),
            'resolution' => $this->store_resolution,
        ]);

        $this->reset('store_name', 'store_form');
        $this->dispatch('close-modal', 'add-store');
        $this->toastSuccess(__('External secret store added.'));
    }

    public function deleteStore(string $storeId): void
    {
        $this->authorize('update', $this->organization);

        ExternalSecretStore::query()
            ->where('organization_id', $this->organization->id)
            ->whereKey($storeId)
            ->delete();

        $this->toastSuccess(__('External secret store removed.'));
    }

    /** @return Collection<int, ExternalSecretStore> */
    public function getStoresProperty(): Collection
    {
        return ExternalSecretStore::query()
            ->where('organization_id', $this->organization->id)
            ->orderBy('name')
            ->get();
    }

    public function getOrgKeyProperty(): ?OrgSecretKey
    {
        return $this->organization->secretKey()->first();
    }

    public function render(): View
    {
        return view('livewire.organizations.secrets', [
            'activeTab' => self::normalizeTab($this->tab),
            'orgKey' => $this->orgKey,
            'stores' => $this->stores,
            'vaultRows' => $this->vaultSecretRows(),
        ]);
    }
}
