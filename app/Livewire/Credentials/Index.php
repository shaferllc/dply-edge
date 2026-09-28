<?php

namespace App\Livewire\Credentials;

use App\Enums\ServerProvider;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesProviderCredentials;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Support\ServerProviderGate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Laravel\Head\Facades\Head;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The organization's Domains & DNS page (was "Credentials"): the zones behind
 * the org's custom domains, which provider controls each, and the DNS provider
 * tokens ({@see ProviderCredential}) dply uses to manage them.
 */
class Index extends Component
{
    use DispatchesToastNotifications;
    use ManagesProviderCredentials;

    public ?Organization $organization = null;

    public function mount(?Organization $organization = null): void
    {
        $this->organization = $organization;

        if ($this->organization) {
            $this->authorize('view', $this->organization);
            Head::title($this->organization->name.' · '.__('Credentials'));
            session(['current_organization_id' => $this->organization->id]);
        }

        $this->authorize('viewAny', ProviderCredential::class);
    }

    /** The shared modal stored a token; re-render so the list shows it. */
    #[On('provider-credential-created')]
    public function refreshCredentials(): void {}

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
                'label' => __('DNS'),
                'items' => [
                    ['id' => 'cloudflare', 'label' => 'Cloudflare'],
                    ['id' => 'gandi', 'label' => 'Gandi'],
                    ['id' => 'namecheap', 'label' => 'Namecheap'],
                    ['id' => 'vercel_dns', 'label' => __('Vercel DNS')],
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

    public static function providerLabel(string $providerId): string
    {
        foreach (self::credentialProviderNav() as $group) {
            foreach ($group['items'] as $item) {
                if ($item['id'] === $providerId) {
                    return $item['label'];
                }
            }
        }

        return ServerProvider::tryFrom($providerId)?->label() ?? $providerId;
    }

    public function render(): View
    {
        $org = $this->organization ?: auth()->user()->currentOrganization();
        $credentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->latest()->get()
            : auth()->user()->providerCredentials()->whereNull('organization_id')->latest()->get();

        return view('livewire.credentials.index', [
            'credentials' => $credentials,
            'providerNav' => self::credentialProviderNav('dns'),
            // Tokens the connect modal can take; older (VM-era) ones can only be removed.
            'dnsProviderIds' => self::credentialProviderIds(),
            'zones' => $org instanceof Organization ? $this->zoneRows($org, $credentials) : [],
            'organization' => $org,
            'useOrgShell' => $org instanceof Organization,
        ])->layout($org instanceof Organization ? 'layouts.app' : 'layouts.settings');
    }

    /**
     * One row per zone behind the org's custom domains, read from each edge
     * site's `routing.custom_domains` (no provider API calls on render).
     *
     * Only an auto-provisioned entry carries a `zone` Cloudflare confirmed, and
     * only the Cloudflare path auto-provisions, so those rows are Cloudflare.
     * A manual hostname has no confirmed zone and gets its own row with no
     * provider — ponytail: grouping those by registrable domain needs a
     * public-suffix list (none installed; Site::deriveRegistrableDomain is a
     * last-two-labels split that breaks on .co.uk).
     *
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return list<array{zone: string, managed: bool, provider: ?string, hosts: list<string>, apps: array<string, string>, status: string, tone: string, fix_token: bool}>
     */
    private function zoneRows(Organization $org, Collection $credentials): array
    {
        $cloudflare = $credentials->where('provider', 'cloudflare');
        $cloudflareState = match (true) {
            $cloudflare->isEmpty() => 'missing',
            $cloudflare->every(fn (ProviderCredential $c): bool => filled($c->validation_error)) => 'rejected',
            default => 'ok',
        };

        $rows = [];
        foreach ($org->sites()->get(['id', 'name', 'meta']) as $site) {
            if ($site->isEdgePreview()) {
                continue; // a preview holds a copy of its parent's domains
            }
            $routing = is_array($site->edgeMeta()['routing'] ?? null) ? $site->edgeMeta()['routing'] : [];
            $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];

            foreach ($domains as $key => $entry) {
                $entry = is_array($entry) ? $entry : [];
                $host = strtolower(trim(is_string($key) && $key !== '' ? $key : (string) ($entry['hostname'] ?? '')));
                if ($host === '') {
                    continue;
                }
                $zone = strtolower(trim((string) ($entry['zone'] ?? '')));
                $managed = ($entry['mode'] ?? null) === 'auto' && $zone !== '';
                $rowKey = $managed ? $zone : $host;

                $rows[$rowKey] ??= ['zone' => $rowKey, 'managed' => $managed, 'hosts' => [], 'apps' => [], 'dns' => []];
                $rows[$rowKey]['hosts'][] = $host;
                $rows[$rowKey]['apps'][(string) $site->id] = (string) $site->name;
                $rows[$rowKey]['dns'][] = (string) ($entry['dns_status'] ?? 'pending');
            }
        }

        ksort($rows);

        return array_values(array_map(function (array $row) use ($cloudflareState): array {
            $failed = in_array('failed', $row['dns'], true);
            $ready = array_diff($row['dns'], ['ready']) === [];

            if ($row['managed']) {
                [$status, $tone] = match (true) {
                    $cloudflareState === 'rejected' => [__('Token rejected'), 'warn'],
                    $cloudflareState === 'missing' => [__('Token removed'), 'warn'],
                    $failed => [__('DNS update failed'), 'warn'],
                    default => [__('Managed'), 'ok'],
                };
            } else {
                [$status, $tone] = match (true) {
                    $failed => [__('Records not found'), 'warn'],
                    $ready => [__('You manage DNS'), 'muted'],
                    default => [__('Waiting for DNS'), 'muted'],
                };
            }

            return [
                'zone' => $row['zone'],
                'managed' => $row['managed'],
                'provider' => $row['managed'] ? 'Cloudflare' : null,
                'hosts' => array_values(array_unique($row['hosts'])),
                'apps' => $row['apps'],
                'status' => $status,
                'tone' => $tone,
                'fix_token' => $row['managed'] && $cloudflareState !== 'ok',
            ];
        }, $rows));
    }
}
