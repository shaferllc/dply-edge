<?php

namespace App\Livewire\Credentials;

use App\Livewire\Concerns\ManagesProviderCredentials;
use App\Models\ProviderCredential;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class AddProviderCredentialModal extends Component
{
    use ManagesProviderCredentials;

    public string $modalName = 'add-provider-credential-modal';

    public ?string $defaultProvider = null;

    public ?string $capability = null;

    /** @var string Provider key from {@see Index::credentialProviderNav()} */
    public string $active_provider = 'digitalocean';

    public function mount(
        ?string $defaultProvider = null,
        ?string $capability = null,
        string $modalName = 'add-provider-credential-modal',
    ): void {
        $this->defaultProvider = $defaultProvider;
        $this->capability = $capability;
        $this->modalName = $modalName;

        $ids = Index::credentialProviderIds($capability);
        if ($defaultProvider !== null && in_array($defaultProvider, $ids, true)) {
            $this->active_provider = $defaultProvider;
        } elseif ($ids !== []) {
            $this->active_provider = $ids[0];
        }
    }

    #[On('open-add-provider-credential-modal')]
    public function openModal(?string $provider = null): void
    {
        if ($this->defaultProvider !== null) {
            $this->active_provider = $this->defaultProvider;
        } elseif (is_string($provider) && $provider !== '') {
            $ids = Index::credentialProviderIds($this->capability);
            if (in_array($provider, $ids, true)) {
                $this->active_provider = $provider;
            }
        }

        $this->resetErrorBag();
        $this->dispatch('open-modal', $this->modalName);
    }

    public function updatedActiveProvider(mixed $value): void
    {
        if ($this->defaultProvider !== null) {
            $this->active_provider = $this->defaultProvider;

            return;
        }

        $ids = Index::credentialProviderIds($this->capability);
        if (! is_string($value) || ! in_array($value, $ids, true)) {
            $this->active_provider = $ids[0] ?? 'cloudflare';
        }
    }

    public function closeModal(): void
    {
        $this->resetErrorBag();
        $this->dispatch('close-modal', $this->modalName);
    }

    public function afterProviderCredentialStored(string $provider): void
    {
        $orgId = auth()->user()?->currentOrganization()?->id;
        $credentialId = null;

        if ($orgId) {
            $credentialId = ProviderCredential::query()
                ->where('organization_id', $orgId)
                ->where('provider', $provider)
                ->latest('id')
                ->value('id');
        }

        $this->dispatch('provider-credential-created', provider: $provider, credentialId: $credentialId);
        $this->dispatch('close-modal', $this->modalName);
    }

    public function render(): View
    {
        // Load the org's existing credentials for the selected provider so
        // the modal can show "Saved in this organization" alongside the add
        // form (the credentials index page no longer has its own list — the
        // modal owns provider management end-to-end).
        $orgId = auth()->user()?->currentOrganization()?->id;
        $credentials = $orgId !== null
            ? ProviderCredential::query()
                ->where('organization_id', $orgId)
                ->where('provider', $this->active_provider)
                ->latest()
                ->get()
            : auth()->user()?->providerCredentials()
                ->whereNull('organization_id')
                ->where('provider', $this->active_provider)
                ->latest()
                ->get() ?? collect();

        return view('livewire.credentials.add-provider-credential-modal', [
            'providerNav' => Index::credentialProviderNav($this->capability),
            'activeProviderLabel' => Index::providerLabel($this->active_provider),
            'digitalOceanOAuthConfigured' => filled(config('services.digitalocean_oauth.client_id'))
                && filled(config('services.digitalocean_oauth.client_secret')),
            'providerPickerLocked' => $this->defaultProvider !== null,
            'credentials' => $credentials,
        ]);
    }
}
