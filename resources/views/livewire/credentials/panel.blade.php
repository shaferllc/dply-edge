@php
    $link = 'text-brand-sage hover:text-brand-ink underline underline-offset-2';
    $hint = 'mt-1 text-sm text-brand-moss leading-relaxed';
    $code = 'rounded-md bg-brand-sand/60 px-1.5 py-0.5 text-xs font-mono text-brand-ink';
    // Capability chips share one neutral pill (brand-cream + ink ring) and use
    // a small colored leading dot for differentiation, so the row stays calm
    // even when a credential picks up several capabilities.
    $capabilityChip = function (string $cap): array {
        return match ($cap) {
            'compute' => ['label' => __('Compute'), 'dot' => 'bg-brand-moss'],
            'dns' => ['label' => __('DNS'), 'dot' => 'bg-brand-sage'],
            'cdn' => ['label' => __('CDN'), 'dot' => 'bg-sky-500'],
            'app_platform' => ['label' => __('App Platform'), 'dot' => 'bg-violet-500'],
            'import' => ['label' => __('Import'), 'dot' => 'bg-amber-500'],
            default => ['label' => ucfirst(str_replace('_', ' ', $cap)), 'dot' => 'bg-brand-mist'],
        };
    };
@endphp

@if ($credentials->isNotEmpty())
    <section class="dply-card overflow-hidden">
        @if ($credentials->contains(fn ($cred) => filled($cred->validation_error)))
            <div class="border-b border-rose-200 bg-rose-50 px-6 py-3 sm:px-7">
                <p class="text-sm font-semibold text-rose-900">{{ __('A saved token can no longer connect') }}</p>
                <p class="mt-1 text-xs leading-relaxed text-rose-800">{{ __('Add a new API key below. Existing servers keep the old one until you do — creating a worker or droplet will fail.') }}</p>
            </div>
        @endif
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Credentials') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Saved in this organization') }}</h3>
            </div>
            <span
                class="ml-auto inline-flex max-w-[min(100%,18rem)] shrink-0 items-start gap-1.5 text-xs leading-snug text-brand-moss sm:text-end"
                title="{{ __('Tokens and keys are encrypted in the database before they are stored on disk (encryption at rest).') }}"
            >
                <x-heroicon-o-lock-closed class="mt-0.5 h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                <span>{{ __('Stored encrypted in our database') }}</span>
            </span>
        </div>
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($credentials as $cred)
                @php $verifyingThis = $verifyingCredentialId === (string) $cred->id; @endphp
                <li
                    class="group flex flex-col gap-3 px-5 py-4 transition-colors hover:bg-brand-cream/30 sm:flex-row sm:items-center sm:justify-between sm:gap-6"
                    wire:key="cred-{{ $cred->id }}"
                >
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                            <span class="truncate font-semibold text-brand-ink">{{ $cred->name }}</span>
                            <span class="font-mono text-xs uppercase tracking-wide text-brand-mist">{{ $cred->provider }}</span>
                        </div>
                        @if (filled($cred->validation_error))
                            <p class="text-xs font-medium text-rose-700">
                                {{ __('Can’t connect') }}
                                @if ($cred->last_validated_at)
                                    · {{ __('checked :time', ['time' => $cred->last_validated_at->diffForHumans()]) }}
                                @endif
                            </p>
                        @elseif ($cred->last_validated_at)
                            <p class="text-xs text-brand-moss">{{ __('Connected :time', ['time' => $cred->last_validated_at->diffForHumans()]) }}</p>
                        @endif
                        @if (count($cred->capabilities()))
                            <div class="flex flex-wrap items-center gap-1.5">
                                @foreach ($cred->capabilities() as $cap)
                                    @php $chip = $capabilityChip($cap); @endphp
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-cream/70 px-2 py-0.5 text-2xs font-medium text-brand-moss ring-1 ring-brand-ink/10">
                                        <span class="inline-block h-1.5 w-1.5 shrink-0 rounded-full {{ $chip['dot'] }}" aria-hidden="true"></span>
                                        {{ $chip['label'] }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        @if ($this->canVerifyCredentialProvider($cred->provider))
                            <button
                                type="button"
                                wire:click="verifyCredential('{{ $cred->id }}')"
                                @if ($verifyingCredentialId !== null) disabled @endif
                                title="{{ __('Verify with provider') }}"
                                class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-brand-moss transition hover:bg-brand-sand/40 hover:text-brand-ink disabled:pointer-events-none disabled:opacity-50"
                            >
                                @if ($verifyingThis)
                                    <x-spinner variant="forest" size="sm" />
                                    <span>{{ __('Verifying…') }}</span>
                                @else
                                    <x-heroicon-o-check-badge class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    <span class="hidden sm:inline">{{ __('Verify') }}</span>
                                @endif
                            </button>
                        @endif
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('destroy', ['{{ $cred->id }}'], @js(__('Remove credential')), @js(__('Remove this credential?')), @js(__('Remove')), true)"
                            title="{{ __('Remove credential') }}"
                            aria-label="{{ __('Remove credential') }}"
                            class="inline-flex items-center justify-center rounded-lg p-1.5 text-brand-mist transition hover:bg-red-50 hover:text-red-700 focus-visible:bg-red-50 focus-visible:text-red-700"
                        >
                            <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                        </button>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endif

@switch($active_provider)
    @case('digitalocean')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                @if (! empty($digitalOceanOAuthConfigured))
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 px-4 py-4 space-y-3">
                        <p class="text-sm text-brand-moss leading-relaxed">{{ __('Sign in with DigitalOcean to connect without pasting a personal access token. Requires a DigitalOcean OAuth app on this deployment.') }}</p>
                        <a
                            href="{{ route('credentials.oauth.digitalocean.redirect') }}"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#0080FF] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#0066CC] transition-colors"
                        >
                            <x-heroicon-o-cloud class="h-4 w-4 shrink-0 opacity-95" aria-hidden="true" />
                            {{ __('Continue with DigitalOcean') }}
                        </a>
                    </div>
                    <p class="text-xs text-brand-mist text-center">{{ __('or use an API token') }}</p>
                @else
                    <p class="text-sm text-brand-moss leading-relaxed">{{ __('Paste a read/write token from DigitalOcean. We verify it before saving. The same token powers Droplets, DNS, and App Platform — dply uses it everywhere DigitalOcean is selected.') }}</p>
                @endif
                <div class="space-y-5">
                    <div>
                        <x-input-label for="do_name" class="flex items-center gap-2">
                            <x-heroicon-o-tag class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('Label (optional)') }}
                        </x-input-label>
                        <x-text-input id="do_name" wire:model="do_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production billing') }}" />
                    </div>
                    <div>
                        <x-input-label for="do_api_token" class="flex items-center gap-2">
                            <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('API token') }}
                        </x-input-label>
                        <x-text-input id="do_api_token" wire:model="do_api_token" type="password" class="mt-1 block w-full" placeholder="dop_v1_…" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token at :link.', ['link' => '<a href="https://cloud.digitalocean.com/account/api/tokens" target="_blank" rel="noopener" class="'.$link.'">DigitalOcean → API</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('do_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeDigitalOcean" wire:loading.attr="disabled" wire:target="storeDigitalOcean">
                        <span wire:loading.remove wire:target="storeDigitalOcean" class="inline-flex items-center justify-center gap-2">
                            <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Connect DigitalOcean') }}
                        </span>
                        <span wire:loading wire:target="storeDigitalOcean" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('cloudflare')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <p class="text-sm text-brand-moss leading-relaxed">
                    {{ __('Use an API token with Zone:DNS:Edit (and Zone:Zone:Read) for the zones Dply should manage. This is independent of where servers are hosted.') }}
                </p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="cloudflare_name" class="flex items-center gap-2">
                            <x-heroicon-o-tag class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('Label (optional)') }}
                        </x-input-label>
                        <x-text-input id="cloudflare_name" wire:model="cloudflare_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production DNS') }}" />
                    </div>
                    <div>
                        <x-input-label for="cloudflare_api_token" class="flex items-center gap-2">
                            <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('API token') }}
                        </x-input-label>
                        <x-text-input id="cloudflare_api_token" wire:model="cloudflare_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token in the :link with DNS permissions for your zones.', ['link' => '<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener" class="'.$link.'">token dashboard</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('cloudflare_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeCloudflare" wire:loading.attr="disabled" wire:target="storeCloudflare">
                        <span wire:loading.remove wire:target="storeCloudflare" class="inline-flex items-center justify-center gap-2">
                            <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Connect account') }}
                        </span>
                        <span wire:loading wire:target="storeCloudflare" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('hetzner')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 px-4 py-4 space-y-3">
                    <p class="text-sm text-brand-moss leading-relaxed">{{ __('Hetzner Cloud uses project API tokens — there is no OAuth sign-in for third-party apps. Sign in to the Hetzner Console, create a read/write token, then paste it below.') }}</p>
                    <a
                        href="https://console.hetzner.cloud/"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#D50C2D] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#B00A26] transition-colors"
                    >
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 opacity-95" aria-hidden="true" />
                        {{ __('Open Hetzner Console') }}
                    </a>
                </div>
                <p class="text-xs text-brand-mist text-center">{{ __('then paste your API token') }}</p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="hetzner_name" :value="__('Label (optional)')" />
                        <x-text-input id="hetzner_name" wire:model="hetzner_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. EU project') }}" />
                    </div>
                    <div>
                        <x-input-label for="hetzner_api_token" :value="__('API token')" />
                        <x-text-input id="hetzner_api_token" wire:model="hetzner_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token at :link (Project → Security → API Tokens). The same token powers servers and DNS zones in that project.', ['link' => '<a href="https://console.hetzner.cloud/" target="_blank" rel="noopener" class="'.$link.'">Hetzner Cloud Console</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('hetzner_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeHetzner" wire:loading.attr="disabled" wire:target="storeHetzner">
                        <span wire:loading.remove wire:target="storeHetzner">{{ __('Connect Hetzner') }}</span>
                        <span wire:loading wire:target="storeHetzner" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('linode')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 px-4 py-4 space-y-3">
                    <p class="text-sm text-brand-moss leading-relaxed">{{ __('Linode uses personal access tokens — there is no OAuth sign-in for third-party apps. Sign in to Cloud Manager, create a token with Linodes and Domains access, then paste it below.') }}</p>
                    <a
                        href="https://cloud.linode.com/profile/tokens"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#009A44] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#007A36] transition-colors"
                    >
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 opacity-95" aria-hidden="true" />
                        {{ __('Open Linode Cloud Manager') }}
                    </a>
                </div>
                <p class="text-xs text-brand-mist text-center">{{ __('then paste your API token') }}</p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="linode_name" :value="__('Label (optional)')" />
                        <x-text-input id="linode_name" wire:model="linode_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production account') }}" />
                    </div>
                    <div>
                        <x-input-label for="linode_api_token" :value="__('API token')" />
                        <x-text-input id="linode_api_token" wire:model="linode_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token at :link with read/write access to Linodes and Domains. The same token powers compute and DNS.', ['link' => '<a href="https://cloud.linode.com/profile/tokens" target="_blank" rel="noopener" class="'.$link.'">Linode → Profile → API Tokens</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('linode_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeLinode" wire:loading.attr="disabled" wire:target="storeLinode">
                        <span wire:loading.remove wire:target="storeLinode">{{ __('Connect Linode') }}</span>
                        <span wire:loading wire:target="storeLinode" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('vultr')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 px-4 py-4 space-y-3">
                    <p class="text-sm text-brand-moss leading-relaxed">{{ __('Vultr uses personal API keys — there is no OAuth sign-in for third-party apps. Sign in to the customer portal, create a key with compute and DNS access, then paste it below.') }}</p>
                    <a
                        href="https://my.vultr.com/settings/#settingsapi"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#007BFC] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#0062C9] transition-colors"
                    >
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 opacity-95" aria-hidden="true" />
                        {{ __('Open Vultr Customer Portal') }}
                    </a>
                </div>
                <p class="text-xs text-brand-mist text-center">{{ __('then paste your API key') }}</p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="vultr_name" :value="__('Label (optional)')" />
                        <x-text-input id="vultr_name" wire:model="vultr_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production account') }}" />
                    </div>
                    <div>
                        <x-input-label for="vultr_api_token" :value="__('API key')" />
                        <x-text-input id="vultr_api_token" wire:model="vultr_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a key at :link. Enable access to Instances and DNS — the same key powers compute and DNS.', ['link' => '<a href="https://my.vultr.com/settings/#settingsapi" target="_blank" rel="noopener" class="'.$link.'">Vultr → Account → API</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('vultr_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeVultr" wire:loading.attr="disabled" wire:target="storeVultr">
                        <span wire:loading.remove wire:target="storeVultr">{{ __('Connect Vultr') }}</span>
                        <span wire:loading wire:target="storeVultr" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('upcloud')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
                    <div>
                        <x-input-label for="upcloud_name" :value="__('Label (optional)')" />
                        <x-text-input id="upcloud_name" wire:model="upcloud_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="upcloud_username" :value="__('API username')" />
                        <x-text-input id="upcloud_username" wire:model="upcloud_username" type="text" class="mt-1 block w-full" required autocomplete="username" />
                        <p class="{{ $hint }}">{!! __('From :link.', ['link' => '<a href="https://hub.upcloud.com/account" target="_blank" rel="noopener" class="'.$link.'">UpCloud Hub → Account</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('upcloud_username')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="upcloud_password" :value="__('API password')" />
                        <x-text-input id="upcloud_password" wire:model="upcloud_password" type="password" class="mt-1 block w-full" required autocomplete="current-password" />
                        <x-input-error :messages="$errors->get('upcloud_password')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeUpCloud" wire:loading.attr="disabled" wire:target="storeUpCloud">
                        <span wire:loading.remove wire:target="storeUpCloud">{{ __('Connect UpCloud') }}</span>
                        <span wire:loading wire:target="storeUpCloud" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('ovh')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="space-y-5">
                    <p class="text-sm leading-relaxed text-brand-moss">
                        {{ __('Create application credentials at') }}
                        <a href="https://api.ovh.com/createToken/" target="_blank" rel="noopener" class="font-medium text-brand-ink underline">api.ovh.com/createToken</a>.
                    </p>
                    <div>
                        <x-input-label for="ovh_name" :value="__('Label (optional)')" />
                        <x-text-input id="ovh_name" wire:model="ovh_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="ovh_endpoint" :value="__('API endpoint')" />
                        <x-select id="ovh_endpoint" wire:model="ovh_endpoint" class="mt-1 block w-full">
                            <option value="ovh-eu">{{ __('OVH Europe (ovh-eu)') }}</option>
                            <option value="ovh-us">{{ __('OVH US (ovh-us)') }}</option>
                            <option value="ovh-ca">{{ __('OVH Canada (ovh-ca)') }}</option>
                        </x-select>
                        <x-input-error :messages="$errors->get('ovh_endpoint')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ovh_application_key" :value="__('Application Key')" />
                        <x-text-input id="ovh_application_key" wire:model="ovh_application_key" type="text" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('ovh_application_key')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ovh_application_secret" :value="__('Application Secret')" />
                        <x-text-input id="ovh_application_secret" wire:model="ovh_application_secret" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('ovh_application_secret')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ovh_consumer_key" :value="__('Consumer Key')" />
                        <x-text-input id="ovh_consumer_key" wire:model="ovh_consumer_key" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('ovh_consumer_key')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeOvh" wire:loading.attr="disabled" wire:target="storeOvh">{{ __('Save credential') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('aws_app_runner')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 px-4 py-4 space-y-2">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Container backend') }}</p>
                    <p class="text-sm text-brand-moss leading-relaxed">{{ __('Connect an IAM access key with App Runner, CloudWatch, and (for private images) ECR permissions. Dply deploys managed containers with auto-scaling and built-in HTTPS.') }}</p>
                </div>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="aws_app_runner_name" :value="__('Label (optional)')" />
                        <x-text-input id="aws_app_runner_name" wire:model="aws_app_runner_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="aws_app_runner_access_key_id" :value="__('Access key ID')" />
                        <x-text-input id="aws_app_runner_access_key_id" wire:model="aws_app_runner_access_key_id" type="text" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('aws_app_runner_access_key_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="aws_app_runner_secret_access_key" :value="__('Secret access key')" />
                        <x-text-input id="aws_app_runner_secret_access_key" wire:model="aws_app_runner_secret_access_key" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('aws_app_runner_secret_access_key')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="aws_app_runner_region" :value="__('Region')" />
                        <x-text-input id="aws_app_runner_region" wire:model="aws_app_runner_region" type="text" class="mt-1 block w-full" placeholder="us-east-1" required />
                        <p class="{{ $hint }}">{{ __('App Runner is available in 8 regions; us-east-1, us-west-2, eu-west-1, ap-northeast-1 are the cheapest.') }}</p>
                        <x-input-error :messages="$errors->get('aws_app_runner_region')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="aws_app_runner_github_connection_arn" :value="__('GitHub connection ARN (for repo deploys)')" />
                        <x-text-input
                            id="aws_app_runner_github_connection_arn"
                            wire:model="aws_app_runner_github_connection_arn"
                            type="text"
                            class="mt-1 block w-full font-mono text-sm"
                            placeholder="arn:aws:apprunner:us-east-1:123456789012:connection/github/…"
                            autocomplete="off"
                        />
                        <p class="{{ $hint }}">{!! __('Required for From repository. Create a GitHub connection in :link, authorize the App Runner GitHub app, then paste the connection ARN here.', ['link' => '<a href="https://console.aws.amazon.com/apprunner/home#/connections" target="_blank" rel="noopener" class="'.$link.'">AWS App Runner → Connections</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('aws_app_runner_github_connection_arn')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="aws_app_runner_access_role_arn" :value="__('ECR access role ARN (optional)')" />
                        <x-text-input
                            id="aws_app_runner_access_role_arn"
                            wire:model="aws_app_runner_access_role_arn"
                            type="text"
                            class="mt-1 block w-full font-mono text-sm"
                            placeholder="arn:aws:iam::123456789012:role/AppRunnerECRAccessRole"
                            autocomplete="off"
                        />
                        <p class="{{ $hint }}">{{ __('Only needed for private ECR images. Public ECR / Docker Hub images work without this.') }}</p>
                        <x-input-error :messages="$errors->get('aws_app_runner_access_role_arn')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeAwsAppRunner" wire:loading.attr="disabled" wire:target="storeAwsAppRunner">{{ __('Save credential') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('ghcr')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 px-4 py-4 space-y-2">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Private images') }}</p>
                    <p class="text-sm text-brand-moss leading-relaxed">{{ __('Pull private images from GitHub Container Registry (ghcr.io) when deploying Cloud apps. Use a GitHub Personal Access Token with read:packages scope.') }}</p>
                </div>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="ghcr_name" :value="__('Label (optional)')" />
                        <x-text-input id="ghcr_name" wire:model="ghcr_name" type="text" class="mt-1 block w-full" placeholder="GHCR — acme" />
                    </div>
                    <div>
                        <x-input-label for="ghcr_username" :value="__('GitHub username')" />
                        <x-text-input id="ghcr_username" wire:model="ghcr_username" type="text" class="mt-1 block w-full font-mono" required autocomplete="off" placeholder="acme-bot" />
                        <x-input-error :messages="$errors->get('ghcr_username')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ghcr_token" :value="__('Personal access token')" />
                        <x-text-input id="ghcr_token" wire:model="ghcr_token" type="password" class="mt-1 block w-full" required autocomplete="off" placeholder="ghp_…" />
                        <p class="{{ $hint }}">{!! __('Create at :link with read:packages scope.', ['link' => '<a href="https://github.com/settings/tokens" target="_blank" rel="noopener" class="'.$link.'">GitHub → Settings → Developer settings</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('ghcr_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeGhcr" wire:loading.attr="disabled" wire:target="storeGhcr">{{ __('Save credential') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('aws')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 px-4 py-4 space-y-3">
                    <p class="text-sm text-brand-moss leading-relaxed">{{ __('Create an IAM user with EC2 and Route 53 permissions, then paste the access key ID and secret. The same credential powers EC2 server provisioning and Route 53 DNS automation.') }}</p>
                    <a
                        href="https://console.aws.amazon.com/iam/home#/users"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#232F3E] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#1a232e] transition-colors"
                    >
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 opacity-95" aria-hidden="true" />
                        {{ __('Open AWS IAM console') }}
                    </a>
                </div>
                <p class="text-xs text-brand-mist text-center">{{ __('then paste your access keys') }}</p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="aws_name" :value="__('Label (optional)')" />
                        <x-text-input id="aws_name" wire:model="aws_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production account') }}" />
                    </div>
                    <div>
                        <x-input-label for="aws_access_key_id" :value="__('Access key ID')" />
                        <x-text-input id="aws_access_key_id" wire:model="aws_access_key_id" type="text" class="mt-1 block w-full font-mono text-sm" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('aws_access_key_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="aws_secret_access_key" :value="__('Secret access key')" />
                        <x-text-input id="aws_secret_access_key" wire:model="aws_secret_access_key" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Use least-privilege IAM policies for EC2 and Route 53. Create keys under :link.', ['link' => '<a href="https://console.aws.amazon.com/iam/home#/users" target="_blank" rel="noopener" class="'.$link.'">AWS IAM</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('aws_secret_access_key')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeAws" wire:loading.attr="disabled" wire:target="storeAws">
                        <span wire:loading.remove wire:target="storeAws">{{ __('Connect AWS') }}</span>
                        <span wire:loading wire:target="storeAws" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('gcp')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 px-4 py-4 space-y-3">
                    <p class="text-sm text-brand-moss leading-relaxed">{{ __('Google Cloud uses service account JSON keys. Create a service account with Compute Engine and Cloud DNS access, download the key JSON, then paste it below.') }}</p>
                    <a
                        href="https://console.cloud.google.com/iam-admin/serviceaccounts"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#1A73E8] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#155DC1] transition-colors"
                    >
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 opacity-95" aria-hidden="true" />
                        {{ __('Open Google Cloud Console') }}
                    </a>
                </div>
                <p class="text-xs text-brand-mist text-center">{{ __('then paste your service account JSON') }}</p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="gcp_name" :value="__('Label (optional)')" />
                        <x-text-input id="gcp_name" wire:model="gcp_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production project') }}" />
                    </div>
                    <div>
                        <x-input-label for="gcp_api_token" :value="__('Service account JSON')" />
                        <textarea
                            id="gcp_api_token"
                            wire:model="gcp_api_token"
                            rows="10"
                            class="mt-1 block w-full rounded-xl border-brand-ink/15 bg-brand-cream/30 px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            placeholder='{"type":"service_account","project_id":"..."}'
                            required
                            autocomplete="off"
                        ></textarea>
                        <p class="{{ $hint }}">{!! __('Create and download a key from :link (IAM & Admin → Service Accounts). Use least-privilege roles needed for Compute and Cloud DNS automation.', ['link' => '<a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener" class="'.$link.'">Google Cloud Console</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('gcp_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeGcp" wire:loading.attr="disabled" wire:target="storeGcp">
                        <span wire:loading.remove wire:target="storeGcp">{{ __('Connect Google Cloud') }}</span>
                        <span wire:loading wire:target="storeGcp" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('azure')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 px-4 py-4 space-y-3">
                    <p class="text-sm text-brand-moss leading-relaxed">{{ __('Azure uses an Entra app (service principal) for API automation. Create an app registration, grant it VM + DNS permissions, then paste Tenant ID, Client ID, Client Secret, and Subscription ID below.') }}</p>
                    <a
                        href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#0078D4] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#005EA2] transition-colors"
                    >
                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 opacity-95" aria-hidden="true" />
                        {{ __('Open Azure Portal') }}
                    </a>
                </div>
                <p class="text-xs text-brand-mist text-center">{{ __('then paste service principal details') }}</p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="azure_name" :value="__('Label (optional)')" />
                        <x-text-input id="azure_name" wire:model="azure_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="azure_tenant_id" :value="__('Tenant ID')" />
                        <x-text-input id="azure_tenant_id" wire:model="azure_tenant_id" type="text" class="mt-1 block w-full font-mono text-sm" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('azure_tenant_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="azure_client_id" :value="__('Client ID')" />
                        <x-text-input id="azure_client_id" wire:model="azure_client_id" type="text" class="mt-1 block w-full font-mono text-sm" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('azure_client_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="azure_client_secret" :value="__('Client secret')" />
                        <x-text-input id="azure_client_secret" wire:model="azure_client_secret" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('azure_client_secret')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="azure_subscription_id" :value="__('Subscription ID')" />
                        <x-text-input id="azure_subscription_id" wire:model="azure_subscription_id" type="text" class="mt-1 block w-full font-mono text-sm" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('azure_subscription_id')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeAzure" wire:loading.attr="disabled" wire:target="storeAzure">{{ __('Save') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('oracle')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <p class="text-sm text-brand-moss leading-relaxed">
                    {{ __('Connect Oracle Cloud Infrastructure using your tenancy/user OCIDs and API signing key. The compartment defaults to your tenancy OCID when left blank.') }}
                </p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="oracle_name" :value="__('Label (optional)')" />
                        <x-text-input id="oracle_name" wire:model="oracle_name" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label for="oracle_tenancy_ocid" :value="__('Tenancy OCID')" />
                        <x-text-input id="oracle_tenancy_ocid" wire:model="oracle_tenancy_ocid" type="text" class="mt-1 block w-full font-mono text-sm" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('oracle_tenancy_ocid')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="oracle_user_ocid" :value="__('User OCID')" />
                        <x-text-input id="oracle_user_ocid" wire:model="oracle_user_ocid" type="text" class="mt-1 block w-full font-mono text-sm" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('oracle_user_ocid')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="oracle_fingerprint" :value="__('API key fingerprint')" />
                        <x-text-input id="oracle_fingerprint" wire:model="oracle_fingerprint" type="text" class="mt-1 block w-full font-mono text-sm" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('oracle_fingerprint')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="oracle_private_key" :value="__('Private key (PEM)')" />
                        <textarea id="oracle_private_key" wire:model="oracle_private_key" rows="8" class="mt-1 block w-full rounded-xl border-brand-ink/20 bg-white/90 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sky focus:ring-brand-sky" required autocomplete="off"></textarea>
                        <x-input-error :messages="$errors->get('oracle_private_key')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="oracle_region" :value="__('Region')" />
                        <x-text-input id="oracle_region" wire:model="oracle_region" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="us-ashburn-1" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('oracle_region')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="oracle_compartment_id" :value="__('Compartment OCID (optional)')" />
                        <x-text-input id="oracle_compartment_id" wire:model="oracle_compartment_id" type="text" class="mt-1 block w-full font-mono text-sm" autocomplete="off" />
                        <x-input-error :messages="$errors->get('oracle_compartment_id')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeOracle" wire:loading.attr="disabled" wire:target="storeOracle">{{ __('Save') }}</x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('gandi')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <p class="text-sm text-brand-moss leading-relaxed">
                    {{ __('Connect Gandi LiveDNS so Dply can manage records for the zones you host at Gandi. This is independent of where your servers run.') }}
                </p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="gandi_name" class="flex items-center gap-2">
                            <x-heroicon-o-tag class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('Label (optional)') }}
                        </x-input-label>
                        <x-text-input id="gandi_name" wire:model="gandi_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production DNS') }}" />
                    </div>
                    <div>
                        <x-input-label for="gandi_api_token" class="flex items-center gap-2">
                            <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('Personal Access Token') }}
                        </x-input-label>
                        <x-text-input id="gandi_api_token" wire:model="gandi_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token in the :link with the "Manage domain name technical configurations" permission.', ['link' => '<a href="https://account.gandi.net/" target="_blank" rel="noopener" class="'.$link.'">Gandi account → Security</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('gandi_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeGandi" wire:loading.attr="disabled" wire:target="storeGandi">
                        <span wire:loading.remove wire:target="storeGandi" class="inline-flex items-center justify-center gap-2">
                            <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Connect Gandi') }}
                        </span>
                        <span wire:loading wire:target="storeGandi" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('namecheap')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <p class="text-sm text-brand-moss leading-relaxed">
                    {{ __('Connect the Namecheap API so Dply can manage host records for your domains. Enable API access and allowlist this server\'s IP in your Namecheap profile first.') }}
                </p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="namecheap_name" class="flex items-center gap-2">
                            <x-heroicon-o-tag class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('Label (optional)') }}
                        </x-input-label>
                        <x-text-input id="namecheap_name" wire:model="namecheap_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production DNS') }}" />
                    </div>
                    <div>
                        <x-input-label for="namecheap_api_user" :value="__('API user')" />
                        <x-text-input id="namecheap_api_user" wire:model="namecheap_api_user" type="text" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{{ __('Usually your Namecheap account username.') }}</p>
                        <x-input-error :messages="$errors->get('namecheap_api_user')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="namecheap_api_key" class="flex items-center gap-2">
                            <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('API key') }}
                        </x-input-label>
                        <x-text-input id="namecheap_api_key" wire:model="namecheap_api_key" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Enable API access and copy the key from :link.', ['link' => '<a href="https://ap.www.namecheap.com/settings/tools/apiaccess/" target="_blank" rel="noopener" class="'.$link.'">Namecheap → Profile → Tools → API Access</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('namecheap_api_key')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeNamecheap" wire:loading.attr="disabled" wire:target="storeNamecheap">
                        <span wire:loading.remove wire:target="storeNamecheap" class="inline-flex items-center justify-center gap-2">
                            <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Connect Namecheap') }}
                        </span>
                        <span wire:loading wire:target="storeNamecheap" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('vercel_dns')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <p class="text-sm text-brand-moss leading-relaxed">
                    {{ __('Connect a Vercel API token so Dply can manage DNS records and put the Vercel Edge Network in front of your sites.') }}
                </p>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="vercel_dns_name" class="flex items-center gap-2">
                            <x-heroicon-o-tag class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('Label (optional)') }}
                        </x-input-label>
                        <x-text-input id="vercel_dns_name" wire:model="vercel_dns_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production CDN') }}" />
                    </div>
                    <div>
                        <x-input-label for="vercel_dns_api_token" class="flex items-center gap-2">
                            <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ __('API token') }}
                        </x-input-label>
                        <x-text-input id="vercel_dns_api_token" wire:model="vercel_dns_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token in :link.', ['link' => '<a href="https://vercel.com/account/tokens" target="_blank" rel="noopener" class="'.$link.'">Vercel → Account Settings → Tokens</a>']) !!}</p>
                        <x-input-error :messages="$errors->get('vercel_dns_api_token')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="vercel_dns_team_id" :value="__('Team ID (optional)')" />
                        <x-text-input id="vercel_dns_team_id" wire:model="vercel_dns_team_id" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="team_…" />
                        <p class="{{ $hint }}">{{ __('Leave blank for a personal account. Required when the domains live under a Vercel team.') }}</p>
                        <x-input-error :messages="$errors->get('vercel_dns_team_id')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeVercelDns" wire:loading.attr="disabled" wire:target="storeVercelDns">
                        <span wire:loading.remove wire:target="storeVercelDns" class="inline-flex items-center justify-center gap-2">
                            <x-heroicon-o-link class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Connect Vercel DNS') }}
                        </span>
                        <span wire:loading wire:target="storeVercelDns" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('forge')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <section class="dply-card overflow-hidden border-amber-200">
                    <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-50 text-amber-900 ring-amber-200">
                                <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Migrate sites from Laravel Forge to dply') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Connect your Forge account to see your existing servers and sites in dply. From there you can launch a guided migration onto a new dply-managed server — code, env, databases, scheduled jobs, daemons, SSL.') }}</p>
                            </div>
                        </div>
                    </div>
                </section>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="forge_name" :value="__('Label (optional)')" />
                        <x-text-input id="forge_name" wire:model="forge_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Agency Forge') }}" />
                    </div>
                    <div>
                        <x-input-label for="forge_api_token" :value="__('API token')" />
                        <x-text-input id="forge_api_token" wire:model="forge_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token in :link.', ['link' => '<a href="https://forge.laravel.com/user-profile/api" target="_blank" rel="noopener" class="'.$link.'">Forge → My Profile → API</a>']) !!}</p>
                        <p class="mt-2 text-xs text-brand-moss">{{ __('The token needs read access to servers and sites, plus SSH-key management (we add and remove a short-lived key per migration). We do not mutate your Forge configuration outside of cutover.') }}</p>
                        <x-input-error :messages="$errors->get('forge_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storeForge" wire:loading.attr="disabled" wire:target="storeForge">
                        <span wire:loading.remove wire:target="storeForge">{{ __('Connect Laravel Forge') }}</span>
                        <span wire:loading wire:target="storeForge" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @case('ploi')
        <div class="dply-card overflow-hidden">
            <div class="p-6 sm:p-8 space-y-6">
                <section class="dply-card overflow-hidden border-amber-200">
                    <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-50 text-amber-900 ring-amber-200">
                                <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Migrate sites from Ploi to dply') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Connect your Ploi account to see your existing servers and sites in dply. From there you can launch a guided migration onto a new dply-managed server — code, env, databases, crons, SSL.') }}</p>
                            </div>
                        </div>
                    </div>
                </section>
                <div class="space-y-5">
                    <div>
                        <x-input-label for="ploi_name" :value="__('Label (optional)')" />
                        <x-text-input id="ploi_name" wire:model="ploi_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Personal Ploi') }}" />
                    </div>
                    <div>
                        <x-input-label for="ploi_api_token" :value="__('API token')" />
                        <x-text-input id="ploi_api_token" wire:model="ploi_api_token" type="password" class="mt-1 block w-full" required autocomplete="off" />
                        <p class="{{ $hint }}">{!! __('Create a token in :link.', ['link' => '<a href="https://ploi.io/profile/api-keys" target="_blank" rel="noopener" class="'.$link.'">Ploi → Profile → API Keys</a>']) !!}</p>
                        <p class="mt-2 text-xs text-brand-moss">{{ __('The token needs read access to servers and sites, plus SSH-key management (we add and remove a short-lived key per migration). It is never used to mutate your Ploi configuration outside of cutover.') }}</p>
                        <x-input-error :messages="$errors->get('ploi_api_token')" class="mt-2" />
                    </div>
                    <x-primary-button type="button" wire:click="storePloi" wire:loading.attr="disabled" wire:target="storePloi">
                        <span wire:loading.remove wire:target="storePloi">{{ __('Connect Ploi') }}</span>
                        <span wire:loading wire:target="storePloi" class="inline-flex items-center justify-center gap-2">
                            <x-spinner variant="cream" />
                            {{ __('Connecting…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @break

    @default
        <div class="rounded-2xl border border-brand-ink/10 bg-amber-50 px-4 py-3 text-sm text-amber-950">{{ __('Unknown provider. Choose another from the list.') }}</div>
@endswitch
