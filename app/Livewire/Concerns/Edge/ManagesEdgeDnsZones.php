<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Models\EdgeDnsZone;
use App\Models\Site;
use App\Modules\Edge\Jobs\CheckEdgeDnsZonesJob;
use App\Modules\Edge\Services\EdgeCustomDomainProvisioner;
use App\Modules\Edge\Services\EdgeDnsZones;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Throwable;

/**
 * Routing → Domains dialogs: "Use your own domain" (dply runs DNS, or point
 * it yourself), the DNS dialog for a domain dply runs, and one domain's
 * detail. Attaching still goes through ManagesEdgeDomains::attachEdgeDomain.
 *
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesEdgeDnsZones
{
    public string $addHost = '';

    /** dply = let dply run DNS; records = point it at dply yourself. */
    public string $addWay = 'dply';

    public ?string $dnsZoneId = null;

    /** @var list<string> scanned record ids to keep */
    public array $keepScanned = [];

    public string $recordType = 'TXT';

    public string $recordName = '';

    public string $recordContent = '';

    public ?int $recordPriority = 10;

    public string $openDomain = '';

    /** @var array<string, list<array<string, mixed>>> per-request memo of zone reads */
    private array $zoneReads = [];

    public function openAddDomain(): void
    {
        $this->authorize('update', $this->site);
        $this->reset('addHost');
        $this->addWay = EdgeDnsZones::enabled() ? 'dply' : 'records';
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'domain-add');
    }

    public function continueAddDomain(EdgeDnsZones $zones): void
    {
        $this->authorize('update', $this->site);
        $host = EdgeCustomDomainProvisioner::normalizeHostname((string) preg_replace('#^https?://#', '', trim($this->addHost)));
        $host = rtrim($host, '/');
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $host)) {
            $this->addError('addHost', __('Enter a domain like www.example.com.'));

            return;
        }

        $zone = null;
        if ($this->addWay === 'dply' && EdgeDnsZones::enabled()) {
            try {
                $zone = $zones->add($this->site->organization, $host, (string) auth()->id());
            } catch (Throwable $e) {
                $this->addError('addHost', $e->getMessage());

                return;
            }
        }

        $this->edge_domain_input = $host;
        $this->attachEdgeDomain();
        if (! array_key_exists($host, $this->site->fresh()->edgeMeta()['routing']['custom_domains'] ?? [])) {
            return; // attachEdgeDomain already said why
        }

        $this->dispatch('close-modal', 'domain-add');
        if ($zone !== null && ! $zone->isActive()) {
            $this->openZone($zone->id);

            return;
        }
        $this->openDomainDetail($host);
    }

    public function openZone(string $zoneId): void
    {
        $zone = $this->zone($zoneId);
        if ($zone === null) {
            return;
        }
        $this->dnsZoneId = $zone->id;
        $this->keepScanned = array_column($this->safeRead($zone, 'scanned'), 'id');
        $this->reset('recordName', 'recordContent');
        $this->dispatch('open-modal', 'dns-zone');
    }

    public function keepScannedRecords(EdgeDnsZones $zones): void
    {
        $zone = $this->zone($this->dnsZoneId);
        if ($zone === null) {
            return;
        }
        try {
            $zones->reviewScanned($zone, array_map('strval', $this->keepScanned));
            $this->toastSuccess(__('Records saved to dply DNS.'));
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function checkZoneNow(EdgeDnsZones $zones, EdgeCustomDomainProvisioner $provisioner): void
    {
        $zone = $this->zone($this->dnsZoneId);
        if ($zone === null) {
            return;
        }
        try {
            if ($zones->refresh($zone, nudge: true)) {
                CheckEdgeDnsZonesJob::finishWaitingDomains($zone, $provisioner);
                $this->site->refresh();
                $this->toastSuccess(__('dply now runs DNS for :name. Its domains are going live.', ['name' => $zone->name]));

                return;
            }
            $this->toastError(__('The nameservers haven’t switched yet. Registrars can take up to a day; we keep checking every few minutes.'));
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function addZoneRecord(EdgeDnsZones $zones): void
    {
        $zone = $this->zone($this->dnsZoneId);
        if ($zone === null) {
            return;
        }
        $this->validate([
            'recordType' => ['required', 'in:A,AAAA,CNAME,MX,TXT'],
            'recordName' => ['nullable', 'string', 'max:253'],
            'recordContent' => ['required', 'string', 'max:2048'],
            'recordPriority' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        try {
            $zones->addRecord($zone, $this->recordType, $this->recordName, $this->recordContent, $this->recordPriority);
            $this->reset('recordName', 'recordContent');
            $this->toastSuccess(__('Record added.'));
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function deleteZoneRecord(EdgeDnsZones $zones, string $recordId): void
    {
        $zone = $this->zone($this->dnsZoneId);
        if ($zone === null) {
            return;
        }
        try {
            $zones->deleteRecord($zone, $recordId);
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function removeZone(EdgeDnsZones $zones): void
    {
        $zone = $this->zone($this->dnsZoneId);
        if ($zone === null) {
            return;
        }
        $inUse = [];
        foreach (Site::query()->where('organization_id', $zone->organization_id)->whereNotNull('edge_backend')->get() as $site) {
            if ($site->isEdgePreview()) {
                continue;
            }
            foreach (array_keys((array) ($site->edgeMeta()['routing']['custom_domains'] ?? [])) as $hostname) {
                if (is_string($hostname) && $zone->covers($hostname)) {
                    $inUse[] = $hostname;
                }
            }
        }
        if ($inUse !== []) {
            $this->toastError(__('Remove :list from their apps first; they stop working without dply DNS.', ['list' => implode(', ', array_unique($inUse))]));

            return;
        }

        try {
            $zones->remove($zone);
            $this->dnsZoneId = null;
            $this->dispatch('close-modal', 'dns-zone');
            $this->toastSuccess(__('dply no longer runs DNS for :name. Point its nameservers back at your registrar.', ['name' => $zone->name]));
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function openDomainDetail(string $hostname): void
    {
        $this->openDomain = $hostname;
        $this->dispatch('open-modal', 'domain-detail');
    }

    /** The zone being edited, only when it belongs to this site's organization. */
    protected function zone(?string $zoneId): ?EdgeDnsZone
    {
        if ($zoneId === null || $zoneId === '') {
            return null;
        }
        $this->authorize('update', $this->site);

        return EdgeDnsZone::query()->whereKey($zoneId)->where('organization_id', $this->site->organization_id)->first();
    }

    /** @return list<EdgeDnsZone> */
    protected function organizationZones(): array
    {
        return EdgeDnsZone::query()->where('organization_id', $this->site->organization_id)->orderBy('name')->get()->all();
    }

    /**
     * Records or scanned records for the open zone; a Cloudflare error shows
     * as an empty list rather than breaking the page.
     *
     * @return list<array<string, mixed>>
     */
    protected function safeRead(EdgeDnsZone $zone, string $what): array
    {
        $key = $zone->id.'.'.$what;
        if (! array_key_exists($key, $this->zoneReads)) {
            try {
                $zones = app(EdgeDnsZones::class);
                $this->zoneReads[$key] = $what === 'scanned' ? $zones->scannedRecords($zone) : $zones->records($zone);
            } catch (Throwable) {
                $this->zoneReads[$key] = [];
            }
        }

        return $this->zoneReads[$key];
    }

    /** Who hosts a domain's DNS today, from its NS records ("GoDaddy"), or null. */
    protected static function dnsProviderFor(string $hostname): ?string
    {
        $zone = EdgeDnsZones::zoneNameFor($hostname);
        if (! str_contains($zone, '.')) {
            return null;
        }
        $ns = Cache::remember('edge.dns-provider.'.$zone, 600, function () use ($zone): string {
            $records = @dns_get_record($zone, DNS_NS);

            return strtolower(implode(' ', array_column(is_array($records) ? $records : [], 'target')));
        });
        if ($ns === '') {
            return null;
        }

        foreach ([
            'domaincontrol.com' => 'GoDaddy', 'cloudflare.com' => 'Cloudflare', 'awsdns' => 'Amazon Route 53',
            'registrar-servers.com' => 'Namecheap', 'googledomains.com' => 'Google Domains', 'squarespacedns' => 'Squarespace',
            'digitalocean.com' => 'DigitalOcean', 'vercel-dns.com' => 'Vercel', 'nsone.net' => 'NS1', 'dnsimple' => 'DNSimple',
            'hostinger' => 'Hostinger', 'porkbun.com' => 'Porkbun', 'name.com' => 'Name.com', 'gandi.net' => 'Gandi',
            'ui-dns' => 'IONOS', 'wixdns.net' => 'Wix', 'hover.com' => 'Hover', 'dply.io' => 'dply',
        ] as $needle => $name) {
            if (str_contains($ns, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
