{{-- Domains tab: where visitors reach the app, in a sentence; each address and rule is a row. --}}
@php
    use App\Modules\Edge\Services\EdgeDnsZones;

    $primaryDomain = $site->edgePrimaryDomain();
    $dplyHost = $edgeLiveUrl ? preg_replace('#^https?://#', '', rtrim((string) $edgeLiveUrl, '/')) : null;
    $domains = collect($edgeAttachedDomains)->filter(fn ($i, $h) => is_string($h) && $h !== '');
    $zones = collect($this->organizationZones());
    $zoneFor = fn (string $host) => $zones->filter(fn ($z) => $z->covers($host))->sortByDesc(fn ($z) => strlen($z->name))->first();
    $canEdit = auth()->user()?->can('update', $site) ?? false;
    $redirects = count($dashboard_redirects) + count($repoRedirects ?? []);
    $rewrites = count($dashboard_rewrites) + count($repoRewrites ?? []);
    $headers = count($dashboard_headers) + count($repoHeaders ?? []);

    // One plain status per domain: sentence, short state, tone.
    $describe = function (string $host, array $info) use ($zoneFor): array {
        $dns = (string) ($info['dns_status'] ?? 'pending');
        $ssl = (string) ($info['ssl_status'] ?? '');
        $zone = $zoneFor($host);

        return match (true) {
            $dns === 'ready' && in_array($ssl, ['active', ''], true) => [__(':host is live over HTTPS', ['host' => $host]), __('Live'), 'ok'],
            $dns === 'ready' && $ssl === 'failed' => [__(':host’s certificate failed', ['host' => $host]), __('TLS failed'), 'bad'],
            $dns === 'ready' => [__(':host is getting its certificate', ['host' => $host]), __('Issuing'), 'warn'],
            $zone !== null && ! $zone->isActive() => [__(':host goes live once :zone’s nameservers point at dply', ['host' => $host, 'zone' => $zone->name]), __('Nameservers'), 'warn'],
            $dns === 'failed' => [__(':host couldn’t be verified yet', ['host' => $host]), __('Check DNS'), 'bad'],
            default => [__(':host is waiting for its DNS records', ['host' => $host]), __('Pending DNS'), 'warn'],
        };
    };
    $tone = fn (string $t) => match ($t) {
        'ok' => 'text-brand-sage',
        'bad' => 'text-rose-600 dark:text-rose-300',
        default => 'text-amber-600 dark:text-amber-300',
    };
    $waiting = $domains->filter(fn ($i) => ($i['dns_status'] ?? 'pending') !== 'ready')->keys();
    $row = 'flex min-h-12 w-full items-center gap-3 border-b border-brand-ink/10 py-3 text-left hover:bg-brand-sand/20';
    $x = fn (string $modal) => '<button type="button" x-on:click="$dispatch(\'close-modal\', \''.$modal.'\')" class="dply-icon-btn h-9 w-9" aria-label="'.e(__('Close')).'">'.svg('heroicon-o-x-mark', 'h-5 w-5', ['aria-hidden' => 'true'])->toHtml().'</button>';
    $copy = fn (string $value) => '<button type="button" x-data="{ c: false }" x-on:click="navigator.clipboard.writeText('.e(json_encode($value)).'); c = true; setTimeout(() => c = false, 1500)" class="shrink-0 rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"><span x-show="! c">'.e(__('Copy')).'</span><span x-show="c" x-cloak>'.e(__('Copied')).'</span></button>';
@endphp

<section class="space-y-8 px-5 py-8 sm:px-10 sm:py-10">
    <div>
        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Routing') }}</p>
        <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
            @if ($dplyHost === null)
                {{ __('This app gets its address on its first deploy.') }}
            @else
                {{ __('Visitors reach this app at') }} <span class="break-all text-brand-sage">{{ $primaryDomain ?? $dplyHost }}</span>{{ $domains->count() > 0 ? '' : '.' }}
                @if ($domains->count() > 0)
                    {{ trans_choice('and :count other address.|and :count other addresses.', $domains->count()) }}
                @else
                    {{ __('It has no domain of its own yet.') }}
                @endif
            @endif
            @if ($waiting->isNotEmpty())
                <span class="text-amber-600 dark:text-amber-300">{{ trans_choice(':list is waiting on DNS.|:list are waiting on DNS.', $waiting->count(), ['list' => $waiting->implode(', ')]) }}</span>
            @endif
        </p>
    </div>

    <div>
        <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Addresses') }}</p>
        <div class="flex min-h-12 items-center gap-3 border-b border-brand-ink/10 py-3">
            <span class="flex-1 text-sm text-brand-ink sm:text-base">
                @if ($dplyHost)
                    <span class="break-all font-mono">{{ $dplyHost }}</span> {{ __('always works') }}
                @else
                    {{ __('Your dply address appears after the first deploy') }}
                @endif
            </span>
            @if ($primaryDomain === null)
                <span class="shrink-0 text-xs text-brand-moss">{{ __('Primary') }}</span>
            @elseif ($canEdit)
                <button type="button" wire:click="makeEdgeDomainPrimary(null)" class="shrink-0 text-xs font-medium text-brand-sage hover:underline">{{ __('Make primary') }}</button>
            @endif
        </div>
        @foreach ($domains as $host => $info)
            @php [$sentence, $state, $t] = $describe($host, is_array($info) ? $info : []); @endphp
            <button type="button" wire:click="openDomainDetail(@js($host))" class="{{ $row }}" wire:key="domain-row-{{ $host }}">
                <span class="min-w-0 flex-1 break-words text-sm text-brand-ink sm:text-base">{{ $sentence }}@if ($primaryDomain === $host) <span class="ml-1 text-xs text-brand-moss">· {{ __('primary') }}</span>@endif</span>
                <span class="shrink-0 text-xs {{ $tone($t) }}">{{ $state }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </button>
        @endforeach
        @if ($canEdit)
            <button type="button" wire:click="openAddDomain" class="{{ $row }} font-medium text-brand-sage">
                <x-heroicon-m-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                <span class="flex-1 text-sm sm:text-base">{{ __('Use your own domain') }}</span>
            </button>
        @endif
        @if ($repoDomains !== [])
            <p class="pt-3 text-xs text-brand-moss">{{ __(':file also attaches', ['file' => $sourcePath]) }} <span class="font-mono">{{ implode(', ', $repoDomains) }}</span> {{ __('on every deploy.') }}</p>
        @endif
    </div>

    @if ($zones->isNotEmpty())
        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('DNS dply runs') }}</p>
            @foreach ($zones as $zone)
                <button type="button" wire:click="openZone(@js($zone->id))" class="{{ $row }}" wire:key="zone-row-{{ $zone->id }}" @disabled(! $canEdit)>
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">
                        {{ $zone->isActive() ? __('dply runs DNS for :name', ['name' => $zone->name]) : __(':name is waiting for its nameservers to change', ['name' => $zone->name]) }}
                    </span>
                    <span @class(['shrink-0 text-xs', 'text-brand-sage' => $zone->isActive(), 'text-amber-600 dark:text-amber-300' => ! $zone->isActive()])>{{ $zone->isActive() ? __('Active') : __('Pending') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @endforeach
            <p class="pt-3 text-xs text-brand-moss">{{ __('Shared across your organization: any app can use these domains.') }}</p>
        </div>
    @endif

    <div>
        <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Rules') }}</p>
        @foreach ([
            ['redirects', $redirects === 0 ? __('No redirects') : trans_choice(':count redirect sends visitors somewhere else|:count redirects send visitors somewhere else', $redirects), $redirects],
            ['rewrites', $rewrites === 0 ? __('No rewrites') : trans_choice(':count rewrite serves a path from another one|:count rewrites serve paths from other ones', $rewrites), $rewrites],
            ['headers', $headers === 0 ? __('No header rules') : trans_choice(':count header rule|:count header rules', $headers), $headers],
        ] as [$tabId, $sentence, $count])
            <button type="button" wire:click="setTab('{{ $tabId }}')" class="{{ $row }}">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $sentence }}</span>
                <span class="shrink-0 font-mono text-xs text-brand-moss">{{ $count }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </button>
        @endforeach
    </div>

    <details class="text-sm">
        <summary class="cursor-pointer text-xs font-semibold text-brand-moss">{{ __('Declare domains in :file', ['file' => $sourcePath]) }}</summary>
        <div class="mt-3 space-y-3">
            <x-edge-yaml-example :file="$sourcePath" :hint="__('Auto-attached on deploy. Removing one from the file doesn’t detach it.')">
domains:
  - "www.example.com"
  - "example.com"
            </x-edge-yaml-example>
            <a href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}" class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline">
                <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Generate :file', ['file' => $sourcePath]) }}
            </a>
        </div>
    </details>
</section>

{{-- Use your own domain --}}
<x-modal name="domain-add" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
    @php
        $hostOk = (bool) preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i', trim($addHost));
        $addZoneName = $hostOk ? EdgeDnsZones::zoneNameFor($addHost) : null;
        $existingZone = $hostOk ? $zoneFor(strtolower(trim($addHost))) : null;
        $provider = $existingZone === null ? $addProvider : null;
    @endphp
    <form wire:submit="continueAddDomain" class="space-y-5 p-6 sm:p-7">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Use your own domain') }}</h2>
                <p class="mt-0.5 text-sm text-brand-moss">
                    @if ($existingZone)
                        {{ $existingZone->isActive() ? __('dply already runs DNS for :zone, so this goes live right away.', ['zone' => $existingZone->name]) : __(':zone is waiting for its nameservers to change.', ['zone' => $existingZone->name]) }}
                    @elseif ($provider)
                        {{ __(':zone’s DNS is at :provider today.', ['zone' => $addZoneName, 'provider' => $provider]) }}
                    @else
                        {{ __('Visitors will reach this app at the address you enter.') }}
                    @endif
                </p>
            </div>
            {!! $x('domain-add') !!}
        </div>

        <x-sheet.field :label="__('Domain')" for="add-host">
            <input id="add-host" type="text" wire:model.live.debounce.500ms="addHost" autocomplete="off" spellcheck="false" placeholder="www.example.com" class="dply-input mt-0 font-mono" />
        </x-sheet.field>
        @error('addHost') <x-sheet.note tone="danger">{{ $message }}</x-sheet.note> @enderror

        @if ($existingZone === null)
            <fieldset class="grid gap-2">
                <legend class="mb-2 text-sm font-semibold text-brand-ink">{{ __('How should we connect it?') }}</legend>
                @if (EdgeDnsZones::enabled())
                    <label @class(['flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3', 'border-brand-sage' => $addWay === 'dply', 'border-brand-ink/10' => $addWay !== 'dply'])>
                        <input type="radio" wire:model.live="addWay" value="dply" class="mt-1 accent-brand-sage">
                        <span>
                            <span class="block text-sm font-semibold text-brand-ink">{{ __('Let dply run DNS') }} <span class="ml-1 text-xs font-medium text-brand-sage">{{ __('Easiest') }}</span></span>
                            <span class="block text-xs text-brand-moss">{{ __('Change the nameservers at your registrar once. We copy your existing records (like email) and every subdomain after that is one click.') }}</span>
                        </span>
                    </label>
                @endif
                <label @class(['flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3', 'border-brand-sage' => $addWay === 'records', 'border-brand-ink/10' => $addWay !== 'records'])>
                    <input type="radio" wire:model.live="addWay" value="records" class="mt-1 accent-brand-sage">
                    <span>
                        <span class="block text-sm font-semibold text-brand-ink">{{ __('Point it at dply myself') }}</span>
                        <span class="block text-xs text-brand-moss">{{ __('Keep your DNS where it is and add one CNAME record (plus a TXT record for a root domain). If the domain is in your connected Cloudflare account, we add it for you.') }}</span>
                    </span>
                </label>
            </fieldset>
        @endif

        <div class="flex justify-end gap-2">
            <x-sheet.button type="button" x-on:click="$dispatch('close-modal', 'domain-add')">{{ __('Cancel') }}</x-sheet.button>
            <x-sheet.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="continueAddDomain">
                <span wire:loading.remove wire:target="continueAddDomain">{{ __('Continue') }}</span>
                <span wire:loading wire:target="continueAddDomain">{{ __('Setting up…') }}</span>
            </x-sheet.button>
        </div>
    </form>
</x-modal>

{{-- DNS dply runs for one domain --}}
<x-modal name="dns-zone" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
    @php $openZone = $dnsZoneId ? $this->zone($dnsZoneId) : null; @endphp
    @if ($openZone)
        @php
            $scanned = $openZone->records_reviewed ? [] : $this->safeRead($openZone, 'scanned');
            $records = $this->safeRead($openZone, 'records');
        @endphp
        <div class="space-y-6 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('DNS for :name', ['name' => $openZone->name]) }}</h2>
                    <p class="mt-0.5 text-sm text-brand-moss">
                        {{ $openZone->isActive() ? __('dply answers for this domain. Its hostnames attach with no records to copy.') : __('Waiting for the nameservers to change. We check every few minutes.') }}
                    </p>
                </div>
                {!! $x('dns-zone') !!}
            </div>

            @unless ($openZone->isActive())
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('1. At your registrar, replace the nameservers with these') }}</p>
                    @foreach ((array) $openZone->name_servers as $ns)
                        <div class="flex items-center justify-between gap-3 rounded-lg bg-brand-sand/30 px-3 py-2">
                            <code class="font-mono text-sm text-brand-ink">{{ $ns }}</code>
                            {!! $copy($ns) !!}
                        </div>
                    @endforeach
                    @if ((array) $openZone->original_name_servers !== [])
                        <p class="text-xs text-brand-moss">{{ __('Right now it uses :list.', ['list' => implode(', ', (array) $openZone->original_name_servers)]) }}</p>
                    @endif
                    <x-sheet.note>{{ __('Turn off DNSSEC at your registrar first. A domain with DNSSEC on stops resolving when its nameservers change.') }}</x-sheet.note>
                </div>
            @endunless

            @if ($scanned !== [])
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-brand-ink">{{ $openZone->isActive() ? __('Records we found') : __('2. Keep your existing records') }}</p>
                    <p class="text-xs text-brand-moss">{{ __('We looked up the records your domain has today. Keep the ones you use, especially MX and TXT for email, before the switch.') }}</p>
                    <ul class="divide-y divide-brand-ink/10 border-y border-brand-ink/10">
                        @foreach ($scanned as $record)
                            <li wire:key="scan-{{ $record['id'] }}">
                                <label class="flex cursor-pointer items-center gap-3 py-2 text-xs">
                                    <input type="checkbox" wire:model="keepScanned" value="{{ $record['id'] }}" class="accent-brand-sage">
                                    <span class="w-12 shrink-0 font-mono text-brand-moss">{{ $record['type'] }}</span>
                                    <span class="w-40 shrink-0 truncate font-mono text-brand-ink">{{ $record['name'] }}</span>
                                    <span class="min-w-0 flex-1 truncate font-mono text-brand-moss">{{ $record['priority'] !== null ? $record['priority'].' ' : '' }}{{ $record['content'] }}</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    <x-sheet.button type="button" wire:click="keepScannedRecords" variant="primary">{{ __('Keep the checked records') }}</x-sheet.button>
                </div>
            @elseif (! $openZone->records_reviewed && ! $openZone->isActive())
                <p class="text-xs text-brand-moss">{{ __('We’re still looking up your existing records. Reopen this in a minute, or add the ones you need below.') }}</p>
            @endif

            <div class="space-y-3">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Records') }}</p>
                @if ($records === [])
                    <p class="text-xs text-brand-moss">{{ __('No records yet. Hostnames you attach add their own.') }}</p>
                @else
                    <ul class="max-h-64 divide-y divide-brand-ink/10 overflow-y-auto border-y border-brand-ink/10">
                        @foreach ($records as $record)
                            <li class="flex items-center gap-3 py-2 text-xs" wire:key="rec-{{ $record['id'] }}">
                                <span class="w-12 shrink-0 font-mono text-brand-moss">{{ $record['type'] }}</span>
                                <span class="w-40 shrink-0 truncate font-mono text-brand-ink" title="{{ $record['name'] }}">{{ $record['name'] }}</span>
                                <span class="min-w-0 flex-1 truncate font-mono text-brand-moss" title="{{ $record['content'] }}">{{ $record['priority'] !== null ? $record['priority'].' ' : '' }}{{ $record['content'] }}</span>
                                <button type="button" wire:click="deleteZoneRecord(@js($record['id']))" wire:confirm="{{ __('Delete this record?') }}" class="shrink-0 text-rose-600 hover:underline dark:text-rose-300">{{ __('Delete') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form wire:submit="addZoneRecord" class="flex flex-wrap items-end gap-2">
                    <select wire:model.live="recordType" class="dply-input mt-0 w-24 font-mono text-xs" aria-label="{{ __('Type') }}">
                        @foreach (['A', 'AAAA', 'CNAME', 'MX', 'TXT'] as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                    <input type="text" wire:model="recordName" placeholder="@ or mail" class="dply-input mt-0 w-32 font-mono text-xs" aria-label="{{ __('Name') }}">
                    @if ($recordType === 'MX')
                        <input type="number" wire:model="recordPriority" class="dply-input mt-0 w-20 font-mono text-xs" aria-label="{{ __('Priority') }}">
                    @endif
                    <input type="text" wire:model="recordContent" placeholder="{{ __('Value') }}" class="dply-input mt-0 min-w-0 flex-1 font-mono text-xs" aria-label="{{ __('Value') }}">
                    <x-sheet.button type="submit">{{ __('Add') }}</x-sheet.button>
                </form>
                @error('recordContent') <x-sheet.note tone="danger">{{ $message }}</x-sheet.note> @enderror
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 pt-4">
                <button type="button" wire:click="removeZone" wire:confirm="{{ __('Stop running DNS for :name? Its records are deleted; point the nameservers back at your registrar first.', ['name' => $openZone->name]) }}" class="text-xs font-medium text-rose-600 hover:underline dark:text-rose-300">{{ __('Stop using dply DNS') }}</button>
                @unless ($openZone->isActive())
                    <x-sheet.button type="button" wire:click="checkZoneNow" variant="primary" wire:loading.attr="disabled" wire:target="checkZoneNow">
                        <span wire:loading.remove wire:target="checkZoneNow">{{ __('I’ve changed them — check now') }}</span>
                        <span wire:loading wire:target="checkZoneNow">{{ __('Checking…') }}</span>
                    </x-sheet.button>
                @endunless
            </div>
        </div>
    @endif
</x-modal>

{{-- One domain --}}
<x-modal name="domain-detail" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
    @php $d = $openDomain !== '' && is_array($edgeAttachedDomains[$openDomain] ?? null) ? $edgeAttachedDomains[$openDomain] : null; @endphp
    @if ($d !== null)
        @php
            [$sentence, $state, $t] = $describe($openDomain, $d);
            $dZone = $zoneFor($openDomain);
            $isReady = ($d['dns_status'] ?? '') === 'ready';
            $proof = is_array($d['dply_verification'] ?? null) ? $d['dply_verification'] : null;
            $ownership = is_array($d['ownership_verification'] ?? null) ? $d['ownership_verification'] : null;
            $target = (string) ($d['cname_target'] ?? '');
        @endphp
        <div class="space-y-5 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="break-all text-lg font-semibold text-brand-ink">{{ $openDomain }}</h2>
                    <p class="mt-0.5 text-sm {{ $tone($t) }}">{{ $sentence }}</p>
                </div>
                {!! $x('domain-detail') !!}
            </div>

            @if ($dZone !== null)
                <p class="text-sm text-brand-moss">
                    {{ $dZone->isActive() ? __('dply runs DNS for :zone, so there’s nothing to add.', ['zone' => $dZone->name]) : __('This goes live on its own once :zone’s nameservers point at dply.', ['zone' => $dZone->name]) }}
                    <button type="button" wire:click="openZone(@js($dZone->id))" x-on:click="$dispatch('close-modal', 'domain-detail')" class="font-medium text-brand-sage hover:underline">{{ __('DNS for :zone', ['zone' => $dZone->name]) }}</button>
                </p>
            @elseif (! $isReady)
                <div class="space-y-3">
                    @php
                        // DNS providers want the name relative to the domain: @ for the root, www for www.example.com.
                        $zoneName = \App\Modules\Edge\Services\EdgeDnsZones::zoneNameFor($openDomain);
                        $relName = fn (string $n): string => $n === $zoneName ? '@' : (str_ends_with($n, '.'.$zoneName) ? substr($n, 0, -strlen('.'.$zoneName)) : $n);
                        $isApex = $relName($openDomain) === '@';
                    @endphp
                    <p class="text-sm text-brand-moss">{{ __('Add these at your DNS provider for :zone, then check. We also check every 15 minutes.', ['zone' => $zoneName]) }}</p>
                    @foreach (array_filter([
                        $target !== '' ? ['CNAME', $openDomain, $target] : null,
                        $proof && ($proof['value'] ?? '') !== '' ? ['TXT', (string) $proof['name'], (string) $proof['value']] : null,
                        $ownership && ($ownership['value'] ?? '') !== '' && ($d['ssl_status'] ?? '') !== 'active' ? [strtoupper((string) ($ownership['type'] ?? 'TXT')), (string) $ownership['name'], (string) $ownership['value']] : null,
                    ]) as [$type, $name, $value])
                        <div class="rounded-lg border border-brand-ink/10 px-3 py-2">
                            <p class="text-2xs text-brand-mist">
                                <span class="font-mono">{{ $type }}</span> ·
                                {{ __('Name') }} <code class="rounded bg-brand-ink/10 px-1 font-mono text-brand-ink">{{ $relName($name) }}</code>
                                <span class="font-mono">({{ $name }})</span>
                            </p>
                            <div class="mt-1 flex items-center gap-2">
                                <code class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink">{{ $value }}</code>
                                {!! $copy($value) !!}
                            </div>
                        </div>
                    @endforeach
                    @if ($isApex)
                        <p class="text-xs text-brand-moss">{{ __('If your provider won’t take a CNAME on @, use ALIAS, ANAME or CNAME flattening, or let dply run DNS. For www, add www.:zone as its own domain.', ['zone' => $zoneName]) }}</p>
                    @endif
                </div>
            @endif

            @foreach (array_filter([$d['error'] ?? null, $d['ssl_error'] ?? null]) as $err)
                <x-sheet.note tone="danger">{{ $err }}</x-sheet.note>
            @endforeach

            @if ($canEdit)
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 pt-4">
                    <button
                        type="button"
                        x-on:click="$dispatch('close-modal', 'domain-detail')"
                        wire:click="openConfirmActionModal('detachEdgeDomain', @js([$openDomain]), @js(__('Remove domain')), @js(__('Remove :hostname from this app? It stops serving here and its certificate is removed.', ['hostname' => $openDomain])), @js(__('Remove')), true)"
                        class="text-xs font-medium text-rose-600 hover:underline dark:text-rose-300"
                    >{{ __('Remove') }}</button>
                    <div class="flex gap-2">
                        @if ($isReady && $primaryDomain !== $openDomain)
                            <x-sheet.button type="button" wire:click="makeEdgeDomainPrimary({{ \Illuminate\Support\Js::from($openDomain) }})">{{ __('Make primary') }}</x-sheet.button>
                        @endif
                        @unless ($isReady)
                            <x-sheet.button type="button" variant="primary" wire:click="verifyEdgeDomain({{ \Illuminate\Support\Js::from($openDomain) }})" wire:loading.attr="disabled" wire:target="verifyEdgeDomain">
                                <span wire:loading.remove wire:target="verifyEdgeDomain">{{ __('Check DNS now') }}</span>
                                <span wire:loading wire:target="verifyEdgeDomain">{{ __('Checking…') }}</span>
                            </x-sheet.button>
                        @endunless
                    </div>
                </div>
            @endif
        </div>
    @endif
</x-modal>
