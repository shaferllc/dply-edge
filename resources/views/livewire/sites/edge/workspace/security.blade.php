@php
    $toneClass = [
        'ok' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
        'warn' => 'bg-amber-100 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200',
        'bad' => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
        'muted' => 'bg-brand-sand/60 text-brand-moss',
    ];
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'open' => $outboundCertificate !== null,
            'what' => __('A summary of this app only: its hostnames and TLS, whether firewall, bot protection, and rate limits are on, and requests Edge blocked in the last 7 days.'),
            'steps' => array_values(array_filter([
                __('Check the hostname row. HTTPS on the Edge URL is issued with the site. Custom domains show their own certificate state.'),
                __('Turn a control on from its page. Off means that check is not running.'),
                __('Blocked (403) is the firewall. Rate limited (429) is a rate-limit rule. Both come from this app’s request log.'),
                ($outboundCertificate !== null)
                    ? __('Calls to other servers: when this app calls another server over HTTPS, that server sees a certificate from this app. Deploy before those calls present it. The other server must trust the certificate.')
                    : null,
            ])),
            'tips' => [
                __('This is not an account-wide scanner. Other apps in the workspace are not included.'),
            ],
        ])

        <div class="rounded-xl border border-brand-ink/10">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Hostnames') }}</p>
                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing', 'tab' => 'domains']) }}" wire:navigate class="text-xs font-semibold text-brand-sage hover:underline">{{ __('Domains') }}</a>
            </div>
            <ul class="divide-y divide-brand-ink/10">
                <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                    <div class="min-w-0">
                        <p class="font-mono text-sm text-brand-ink break-all">{{ $security['hostname'] !== '' ? $security['hostname'] : __('Pending first deploy') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Edge URL') }}</p>
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $toneClass[$security['tlsTone']] }}">{{ $security['tlsLabel'] }}</span>
                </li>
                @forelse ($security['domains'] as $domain)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3" wire:key="security-domain-{{ $domain['hostname'] }}">
                        <div class="min-w-0">
                            <p class="font-mono text-sm text-brand-ink break-all">{{ $domain['hostname'] }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Custom domain') }}</p>
                        </div>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $toneClass[$domain['tlsTone']] }}">{{ $domain['tlsLabel'] }}</span>
                    </li>
                @empty
                    <li class="px-4 py-3 text-sm text-brand-moss">{{ __('No custom domains yet.') }}</li>
                @endforelse
            </ul>
        </div>

        <div class="mt-4 rounded-xl border border-brand-ink/10">
            <div class="border-b border-brand-ink/10 px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Controls') }}</p>
            </div>
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($security['controls'] as $control)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-brand-ink">{{ $control['label'] }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ $control['detail'] }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' => $control['on'],
                                'bg-brand-sand/60 text-brand-moss' => ! $control['on'],
                            ])>{{ $control['on'] ? __('On') : __('Off') }}</span>
                            <a href="{{ $control['href'] }}" wire:navigate class="text-xs font-semibold text-brand-sage hover:underline">{{ __('Open') }}</a>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        @if ($outboundCertificate !== null)
            <div class="mt-4 rounded-xl border border-brand-ink/10">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Calls to other servers') }}</p>
                    @if ($outboundCertificate === '')
                        <button type="button" wire:click="enableOutboundCertificate" class="rounded-md bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white">{{ __('Turn on') }}</button>
                    @else
                        <div class="flex items-center gap-3">
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">{{ __('On') }}</span>
                            <button type="button" wire:click="redeployEdge" class="rounded-md bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white">{{ __('Deploy') }}</button>
                            <button type="button" wire:click="askRemoveOutboundCertificate" x-on:click="$dispatch('open-modal', 'security-remove-certificate')" class="text-xs font-semibold text-brand-ink underline">{{ __('Remove') }}</button>
                        </div>
                    @endif
                </div>
                <div class="px-4 py-3">
                    <p class="max-w-xl text-xs text-brand-moss">{{ __('When this app calls another server over HTTPS, that server sees this certificate and can tell the call came from this app. Visitors still use the certificate on this app’s own address. The other server only accepts the call as authenticated if it trusts this certificate. Servers that do not ask for one ignore it.') }}</p>
                    @if ($outboundCertificate !== '')
                        <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Deploy this app before those calls present the certificate.') }}</p>
                    @endif
                    <x-input-error :messages="$errors->get('outboundCertificate')" class="mt-2" />
                </div>
            </div>
        @endif

        <div class="mt-4 rounded-xl border border-brand-ink/10">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Blocked requests') }}</p>
                <p class="text-xs text-brand-moss">{{ __('Last 7 days') }}</p>
            </div>
            <div class="grid grid-cols-2 divide-x divide-brand-ink/10 border-b border-brand-ink/10">
                <div class="px-4 py-3">
                    <p class="text-xs text-brand-moss">{{ __('Blocked (403)') }}</p>
                    <p class="mt-1 text-lg font-semibold text-brand-ink">{{ number_format($security['blocked']) }}</p>
                </div>
                <div class="px-4 py-3">
                    <p class="text-xs text-brand-moss">{{ __('Rate limited (429)') }}</p>
                    <p class="mt-1 text-lg font-semibold text-brand-ink">{{ number_format($security['limited']) }}</p>
                </div>
            </div>
            @if ($security['recent'] === [])
                <p class="px-4 py-4 text-sm text-brand-moss">{{ __('No blocked requests in the last 7 days.') }}</p>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($security['recent'] as $row)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5">
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300' => $row['status'] === 403,
                                'bg-amber-100 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200' => $row['status'] === 429,
                            ])>{{ $row['status'] }}</span>
                            <span class="font-mono text-xs text-brand-moss">{{ $row['method'] }}</span>
                            <span class="min-w-0 flex-1 truncate font-mono text-sm text-brand-ink" title="{{ $row['path'] }}">{{ $row['path'] }}</span>
                            @if ($row['country'] !== '')
                                <span class="text-xs text-brand-moss">{{ $row['country'] }}</span>
                            @endif
                            <span class="text-xs text-brand-moss">{{ $row['when'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    <x-modal name="security-remove-certificate" :show="$confirmRemoveCertificate" maxWidth="md" focusable>
        <div class="space-y-4 bg-white p-5 dark:bg-zinc-900">
            <h2 class="text-sm font-semibold text-brand-ink">{{ __('Remove this certificate?') }}</h2>
            <p class="text-xs text-brand-moss">{{ __('HTTPS calls from this app will stop presenting it on the next deploy. This cannot be undone.') }}</p>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="$set('confirmRemoveCertificate', false)" x-on:click="$dispatch('close-modal', 'security-remove-certificate')" class="rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Cancel') }}</button>
                <button type="button" wire:click="removeOutboundCertificate" class="rounded-md bg-red-700 px-3 py-1.5 text-xs font-semibold text-white">{{ __('Remove certificate') }}</button>
            </div>
        </div>
    </x-modal>
</div>
