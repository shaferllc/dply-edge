@php
    $toneText = fn (string $tone): string => match ($tone) {
        'ok' => 'text-brand-forest',
        'warn' => 'text-amber-700',
        default => 'text-brand-moss',
    };
    $toneDot = fn (string $tone): string => match ($tone) {
        'ok' => 'bg-brand-sage',
        'warn' => 'bg-amber-500',
        default => 'bg-brand-mist',
    };
    $managedZones = collect($zones)->where('managed', true)->count();
    $hostCount = collect($zones)->sum(fn ($z) => count($z['hosts']));
    $appCount = collect($zones)->flatMap(fn ($z) => array_keys($z['apps']))->unique()->count();
    $providerCount = $credentials->pluck('provider')->unique()->count();
    $rejectedCount = $credentials->filter(fn ($c) => filled($c->validation_error) && in_array($c->provider, $dnsProviderIds, true))->count();
    $label = 'text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist';
    $ghostBtn = 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-brand-ink/15 px-3 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40';
    $canConnect = auth()->user()?->can('create', \App\Models\ProviderCredential::class);
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Domains & DNS') }}</h1>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Your zones, which provider controls each, and whether dply can manage it.') }}</p>
        </div>
        @if ($canConnect)
            <button
                type="button"
                x-on:click="$dispatch('open-add-provider-credential-modal')"
                class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-ink px-3.5 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest"
            >
                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Connect a provider') }}
            </button>
        @endif
    </div>

    <x-livewire-validation-errors />

    {{-- OAuth round trips land back here, so this is the only place their
         outcome can be reported. --}}
    @if (session('success') || session('error'))
        <x-alert :tone="session('error') ? 'danger' : 'success'">
            {{ session('error') ?: session('success') }}
        </x-alert>
    @endif

    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="dply-card p-4">
            <dt class="{{ $label }}">{{ __('Zones') }}</dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ count($zones) }}</dd>
            <dd class="mt-0.5 text-xs text-brand-moss">{{ __(':n managed automatically', ['n' => $managedZones]) }}</dd>
        </div>
        <div class="dply-card p-4">
            <dt class="{{ $label }}">{{ __('Providers') }}</dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $providerCount }}</dd>
            <dd @class(['mt-0.5 text-xs', 'text-amber-700' => $rejectedCount > 0, 'text-brand-moss' => $rejectedCount === 0])>
                {{ $rejectedCount > 0
                    ? trans_choice(':n token rejected|:n tokens rejected', $rejectedCount, ['n' => $rejectedCount])
                    : trans_choice(':n token|:n tokens', $credentials->count(), ['n' => $credentials->count()]) }}
            </dd>
        </div>
        <div class="dply-card p-4">
            <dt class="{{ $label }}">{{ __('Custom domains') }}</dt>
            <dd class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $hostCount }}</dd>
            <dd class="mt-0.5 text-xs text-brand-moss">{{ trans_choice('on :n app|on :n apps', $appCount, ['n' => $appCount]) }}</dd>
        </div>
    </dl>

    {{-- Zones behind the org's custom domains. --}}
    <section class="dply-card overflow-hidden p-0" aria-labelledby="dns-zones-heading">
        <h2 id="dns-zones-heading" class="sr-only">{{ __('Zones') }}</h2>
        @if ($zones === [])
            <div class="px-5 py-8 text-center sm:px-6">
                <p class="text-sm font-semibold text-brand-ink">{{ __('No custom domains yet') }}</p>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Add a domain from an app’s Domains tab. With a Cloudflare token connected, dply writes the DNS record for you.') }}</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="{{ $label }} text-left">
                            <th scope="col" class="px-5 py-3 font-semibold sm:px-6">{{ __('Zone') }}</th>
                            <th scope="col" class="px-3 py-3 font-semibold">{{ __('Provider') }}</th>
                            <th scope="col" class="px-3 py-3 font-semibold">{{ __('Used by') }}</th>
                            <th scope="col" class="px-3 py-3 font-semibold">{{ __('Status') }}</th>
                            <th scope="col" class="px-5 py-3 sm:px-6"><span class="sr-only">{{ __('Action') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/8 border-t border-brand-ink/10">
                        @foreach ($zones as $zone)
                            @php $firstApp = array_key_first($zone['apps']); @endphp
                            <tr wire:key="zone-{{ $zone['zone'] }}">
                                <td class="px-5 py-3.5 align-middle sm:px-6">
                                    <p class="font-mono text-sm text-brand-ink">{{ $zone['zone'] }}</p>
                                    @if ($zone['managed'] && $zone['hosts'] !== [$zone['zone']])
                                        <p class="mt-0.5 truncate text-xs text-brand-moss">{{ implode(', ', $zone['hosts']) }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-3.5 align-middle text-brand-ink">{{ $zone['provider'] ?? '—' }}</td>
                                <td class="px-3 py-3.5 align-middle">
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($zone['apps'] as $siteId => $siteName)
                                            <a href="{{ route('sites.show', ['site' => $siteId, 'section' => 'routing', 'tab' => 'domains']) }}" wire:navigate class="rounded-md bg-brand-sand/50 px-2 py-0.5 text-xs text-brand-ink hover:bg-brand-sand/80">{{ $siteName }}</a>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-3 py-3.5 align-middle">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-medium {{ $toneText($zone['tone']) }}">
                                        <span class="h-2 w-2 shrink-0 rounded-full {{ $toneDot($zone['tone']) }}" aria-hidden="true"></span>
                                        {{ $zone['status'] }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right align-middle sm:px-6">
                                    @if ($zone['fix_token'] && $canConnect)
                                        <button type="button" x-on:click="$dispatch('open-add-provider-credential-modal', { provider: 'cloudflare' })" class="{{ $ghostBtn }}">{{ __('Fix token') }}</button>
                                    @elseif ($firstApp !== null)
                                        <a href="{{ route('sites.show', ['site' => $firstApp, 'section' => 'routing', 'tab' => 'domains']) }}" wire:navigate class="{{ $ghostBtn }}">{{ __('Domains') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Connected provider tokens. --}}
    <section class="dply-card overflow-hidden p-0" aria-labelledby="dns-providers-heading">
        <div class="border-b border-brand-ink/10 px-5 py-3.5 sm:px-6">
            <h2 id="dns-providers-heading" class="text-sm font-semibold text-brand-ink">{{ __('Connected providers') }}</h2>
        </div>
        @if ($credentials->isEmpty())
            <p class="px-5 py-4 text-sm text-brand-moss sm:px-6">{{ __('No DNS provider connected. Without one, you add the DNS record for each domain yourself.') }}</p>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($credentials as $cred)
                    @php $verifyingThis = $verifyingCredentialId === (string) $cred->id; @endphp
                    <li class="flex flex-wrap items-center gap-4 px-5 py-3.5 sm:px-6" wire:key="cred-{{ $cred->id }}">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sand/50 text-brand-forest">
                            <x-credentials-provider-icon :provider="$cred->provider" class="h-4 w-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-brand-ink">{{ $cred->name }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss">
                                {{ \App\Livewire\Credentials\Index::providerLabel($cred->provider) }}
                                ·
                                @if (filled($cred->validation_error))
                                    <span class="font-medium text-rose-700">{{ __('Can’t connect') }}</span>
                                    @if ($cred->last_validated_at)
                                        · {{ __('checked :time', ['time' => $cred->last_validated_at->diffForHumans()]) }}
                                    @endif
                                @elseif ($cred->last_validated_at)
                                    <span class="font-medium text-brand-forest">{{ __('Verified') }}</span> · {{ $cred->last_validated_at->diffForHumans() }}
                                @else
                                    {{ __('Not verified yet') }}
                                @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            @if ($this->canVerifyCredentialProvider($cred->provider))
                                <button
                                    type="button"
                                    wire:click="verifyCredential('{{ $cred->id }}')"
                                    @disabled($verifyingCredentialId !== null)
                                    class="{{ $ghostBtn }} disabled:pointer-events-none disabled:opacity-50"
                                >
                                    @if ($verifyingThis)
                                        <x-spinner variant="forest" size="sm" />
                                        {{ __('Verifying…') }}
                                    @else
                                        {{ __('Verify') }}
                                    @endif
                                </button>
                            @endif
                            @if ($canConnect && in_array($cred->provider, $dnsProviderIds, true))
                                {{-- Saves a new token for this provider; remove the old one once it verifies. --}}
                                <button type="button" x-on:click="$dispatch('open-add-provider-credential-modal', { provider: @js($cred->provider) })" class="{{ $ghostBtn }}">{{ __('Replace token') }}</button>
                            @endif
                            @can('delete', $cred)
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('destroy', ['{{ $cred->id }}'], @js(__('Remove provider')), @js(__('Remove :name? Anything dply does with this token stops — DNS updates for your domains, and deploys for apps that deliver through this account.', ['name' => $cred->name])), @js(__('Remove')), true)"
                                    class="inline-flex h-8 items-center px-2 text-xs font-semibold text-rose-700 transition hover:text-rose-900"
                                >
                                    {{ __('Remove') }}
                                </button>
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canConnect && ! empty($providerNav))
            <div class="border-t border-brand-ink/10 px-5 py-4 sm:px-6">
                <p class="{{ $label }}">{{ __('Add a DNS provider') }}</p>
                <ul class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($providerNav as $group)
                        @foreach ($group['items'] as $item)
                            @php $isComing = ! empty($item['comingSoon']); @endphp
                            <li>
                                <button
                                    type="button"
                                    @unless ($isComing) x-on:click="$dispatch('open-add-provider-credential-modal', { provider: @js($item['id']) })" @endunless
                                    @disabled($isComing)
                                    @class([
                                        'flex w-full items-center gap-2.5 rounded-lg border border-brand-ink/10 px-3 py-2.5 text-left transition',
                                        'hover:border-brand-sage/40 hover:bg-brand-sand/30' => ! $isComing,
                                        'cursor-not-allowed opacity-60' => $isComing,
                                    ])
                                >
                                    <x-credentials-provider-icon :provider="$item['id']" class="h-4 w-4 shrink-0 text-brand-forest" />
                                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-brand-ink">{{ $item['label'] }}</span>
                                    @if ($isComing)
                                        <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Soon') }}</span>
                                    @else
                                        <x-heroicon-o-plus class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    <p class="text-xs text-brand-moss">{{ __('Only owners and admins can connect or remove providers. Tokens are encrypted before they reach disk and never shown again.') }}</p>
</div>
