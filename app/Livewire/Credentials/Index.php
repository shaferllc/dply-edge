<?php

namespace App\Livewire\Credentials;

use App\Enums\ServerProvider;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesProviderCredentials;
use App\Models\Organization;
use App\Models\ProviderCredential;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use App\Support\ServerProviderGate;

/**
 * The organization's Credentials page: every secret this org hands to a third
 * party — one API token per cloud/DNS/CDN provider ({@see ProviderCredential}).
 */
class Index extends Component
{
    use DispatchesToastNotifications;
    // Brings BOTH create modes — "connect existing" and "provision a new
    // bucket" — so a storage card here can create the bucket, not just record
    // keys for one you made elsewhere.
    use ManagesProviderCredentials;

    /**
     * Valid values for the capability tab (`?tab=`). Used to filter the provider sidebar.
     *
     * @var list<string>
     */
    private const TABS = ['all', 'server', 'dns', 'cdn', 'imports', 'storage'];

    public ?Organization $organization = null;

    /** @var string Provider key from {@see credentialProviderNav()} */
    public string $active_provider = 'digitalocean';

    /** @var string One of {@see self::TABS}: filters the provider sidebar by capability. */
    public string $tab = 'all';

    /**
     * Explicit setter so the tab strip has a concrete wire:target — `wire:target`
     * can't match a magic `$set`, so the tab's inline spinner never fired and a
     * switch looked frozen for the whole round-trip.
     */
    public function setTab(string $value): void
    {
        if ($value === '' || $value === $this->tab) {
            return;
        }

        $this->tab = $value;

        // Direct assignment doesn't fire Livewire's updated hook, and that hook
        // validates the tab and repoints active_provider — call it explicitly.
        $this->updatedTab($value);
    }


    public function mount(?Organization $organization = null): void
    {
        $this->organization = $organization;

        if ($this->organization) {
            $this->authorize('view', $this->organization);
            session(['current_organization_id' => $this->organization->id]);
        }

        $this->authorize('viewAny', ProviderCredential::class);

        // Tab first — the capability filter constrains which providers are available, so
        // we honor `?tab=` before we resolve `active_provider` against the filtered list.
        $tabParam = request()->query('tab');
        if (is_string($tabParam) && in_array($tabParam, self::TABS, true)) {
            $this->tab = $tabParam;
        }

        $ids = self::credentialProviderIds($this->capabilityForTab());
        if ($ids !== [] && ! in_array($this->active_provider, $ids, true)) {
            $this->active_provider = $ids[0];
        }

        $q = request()->query('provider');
        if (is_string($q) && ServerProviderGate::visible($q) && in_array($q, $ids, true)) {
            $this->active_provider = $q;
        }
    }

    public function updatedActiveProvider(mixed $value): void
    {
        $ids = self::credentialProviderIds($this->capabilityForTab());
        if (! is_string($value) || ! in_array($value, $ids, true)) {
            $this->active_provider = $ids[0] ?? 'digitalocean';
        }
    }

    public function updatedTab(mixed $value): void
    {
        if (! is_string($value) || ! in_array($value, self::TABS, true)) {
            $this->tab = 'all';
        }

        $ids = self::credentialProviderIds($this->capabilityForTab());
        if ($ids !== [] && ! in_array($this->active_provider, $ids, true)) {
            $this->active_provider = $ids[0];
        }
    }

    /**
     * Resolve the capability filter for the current `tab` value. `null` means no filter.
     */
    private function capabilityForTab(): ?string
    {
        return match ($this->tab) {
            'server' => 'compute',
            'dns' => 'dns',
            'cdn' => 'cdn',
            'imports' => 'import',
            default => null,
        };
    }

    /**
     * Sidebar groups for the provider picker (IDs match `provider_credentials.provider` where applicable).
     *
     * @param  string|null  $capability  If set, restricts items to providers whose enum supports the capability
     *                                   ('compute' or 'dns'). Items with no matching enum case are dropped.
     * @return list<array{label: string, items: list<array{id: string, label: string, comingSoon: bool}>}>
     */
    public static function credentialProviderNav(?string $capability = null): array
    {
        $groups = [
            [
                'label' => __('VPS & cloud'),
                'items' => [
                    ['id' => 'digitalocean', 'label' => 'DigitalOcean'],
                    ['id' => 'hetzner', 'label' => 'Hetzner'],
                    ['id' => 'linode', 'label' => 'Linode'],
                    ['id' => 'vultr', 'label' => 'Vultr'],
                    ['id' => 'upcloud', 'label' => 'UpCloud'],
                ],
            ],
            [
                'label' => __('DNS & CDN'),
                'items' => [
                    ['id' => 'cloudflare', 'label' => 'Cloudflare'],
                    ['id' => 'gandi', 'label' => 'Gandi'],
                    ['id' => 'namecheap', 'label' => 'Namecheap'],
                    ['id' => 'vercel_dns', 'label' => __('Vercel DNS')],
                ],
            ],
            [
                'label' => __('Other providers'),
                'items' => [
                    ['id' => 'ovh', 'label' => __('OVH Public Cloud')],
                ],
            ],
            [
                'label' => __('Platforms'),
                'items' => [
                    ['id' => 'aws_app_runner', 'label' => 'AWS App Runner'],
                    ['id' => 'ghcr', 'label' => __('GitHub Container Registry')],
                ],
            ],
            [
                'label' => __('Hyperscale'),
                'items' => [
                    ['id' => 'aws', 'label' => 'AWS'],
                    ['id' => 'gcp', 'label' => 'Google Cloud'],
                    ['id' => 'azure', 'label' => 'Azure'],
                    ['id' => 'oracle', 'label' => __('Oracle Cloud')],
                ],
            ],
            [
                'label' => __('Migrate from'),
                'items' => [
                    ['id' => 'ploi', 'label' => 'Ploi'],
                    ['id' => 'forge', 'label' => 'Laravel Forge'],
                ],
            ],
        ];

        $filtered = [];
        foreach ($groups as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (! ServerProviderGate::visible($item['id'])) {
                    continue;
                }
                if ($capability !== null) {
                    $enum = ServerProvider::tryFrom($item['id']);
                    if ($enum === null) {
                        continue;
                    }
                    $matches = match ($capability) {
                        'dns' => $enum->supportsDns(),
                        'cdn' => $enum->supportsCdn(),
                        'import' => $enum->supportsImport(),
                        default => $enum->supportsCompute(),
                    };
                    if (! $matches) {
                        continue;
                    }
                }
                $items[] = [
                    'id' => $item['id'],
                    'label' => $item['label'],
                    'comingSoon' => ServerProviderGate::comingSoon($item['id']),
                ];
            }
            if ($items !== []) {
                $filtered[] = [
                    'label' => $group['label'],
                    'items' => $items,
                ];
            }
        }

        return $filtered;
    }

    /**
     * @param  string|null  $capability  Optional capability filter forwarded to {@see credentialProviderNav()}.
     * @return list<string>
     */
    public static function credentialProviderIds(?string $capability = null): array
    {
        $ids = [];
        foreach (self::credentialProviderNav($capability) as $group) {
            foreach ($group['items'] as $item) {
                $ids[] = $item['id'];
            }
        }

        return $ids;
    }

    public function resolveActiveProviderLabel(): string
    {
        return self::providerLabel($this->active_provider);
    }

    public static function providerLabel(string $providerId): string
    {
        foreach (self::credentialProviderNav() as $group) {
            foreach ($group['items'] as $item) {
                if ($item['id'] === $providerId) {
                    return $item['label'];
                }
            }
        }

        return $providerId;
    }

    /**
     * Per-provider credential counts, resolved in a single grouped query and
     * memoised for the request. The provider nav calls credentialCountFor()
     * once per provider in two places, so without this each card fired its own
     * COUNT (N×2 duplicate queries).
     *
     * @var array<string, int>|null
     */
    private ?array $credentialCountsMemo = null;

    /** @return array<string, int> provider => count */
    protected function credentialCounts(): array
    {
        if ($this->credentialCountsMemo !== null) {
            return $this->credentialCountsMemo;
        }

        $org = $this->organization ?: auth()->user()->currentOrganization();
        $query = $org
            ? ProviderCredential::query()->where('organization_id', $org->id)
            : auth()->user()->providerCredentials()->whereNull('organization_id');

        return $this->credentialCountsMemo = $query
            ->toBase()
            ->select('provider')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('provider')
            ->pluck('aggregate', 'provider')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    public function credentialCountFor(string $provider): int
    {
        return $this->credentialCounts()[$provider] ?? 0;
    }

    public function render(): View
    {
        $org = $this->organization ?: auth()->user()->currentOrganization();
        $credentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->latest()->get()
            : auth()->user()->providerCredentials()->whereNull('organization_id')->latest()->get();

        return view('livewire.credentials.index', [
            'credentials' => $credentials,
            'providerNav' => $this->tab === 'storage'
                ? []
                : self::credentialProviderNav($this->capabilityForTab()),
            'storageNav' => [],
            'storageCount' => 0,
            'activeProviderLabel' => $this->resolveActiveProviderLabel(),
            'organization' => $org,
            'useOrgShell' => $org instanceof Organization,
            'activeProviderComingSoon' => ServerProviderGate::comingSoon($this->active_provider),
        ])->layout($org instanceof Organization ? 'layouts.app' : 'layouts.settings');
    }

}
