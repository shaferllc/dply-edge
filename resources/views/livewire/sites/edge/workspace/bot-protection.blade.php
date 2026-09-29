@php
    $canEdit = auth()->user()?->can('update', $site) && $managedDelivery;
    $formPaths = collect($dependents)->where('kind', 'form')->pluck('path');
    $ratePaths = collect($dependents)->where('kind', 'rate')->pluck('path');
    $relies = collect([
        $formPaths->isNotEmpty() ? trans_choice('your :paths form|your :paths forms', $formPaths->count(), ['paths' => $formPaths->implode(', ')]) : null,
        $ratePaths->isNotEmpty() ? trans_choice(':paths rate limit|:paths rate limits', $ratePaths->count(), ['paths' => $ratePaths->implode(', ')]) : null,
    ])->filter()->implode(__(' and '));
    $formsUrl = route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'forms']);
    $ratesUrl = route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'rate-limits']);
    $field = 'mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900';
    $keysLine = match (true) {
        ! $hasKeys => __('No keys yet'),
        $keysGenerated && $keysGeneratedAt => __('Keys were generated for this app on :date', ['date' => \Illuminate\Support\Carbon::parse($keysGeneratedAt)->format('M j, Y')]),
        $keysGenerated => __('Keys were generated for this app'),
        default => __('Your own keys are in use'),
    };
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'bot-protection',
            'what' => __('Bot protection uses a privacy-friendly challenge widget so bots can’t submit forms (or browse pages) as easily as real people.'),
            'steps' => [
                __('Tick “Bot protection is on” — keys are generated for this app if it has none — or paste your own under Keys.'),
                __('Choose where to check: form posts only (recommended) or every page.'),
                __('Changes go live on the next request.'),
            ],
            'setupLinks' => $canGenerateKeys ? [] : [
                [
                    'label' => __('Challenge provider console'),
                    'href' => 'https://dash.cloudflare.com/?to=/:account/turnstile',
                    'external' => true,
                ],
            ],
            'tips' => [
                __('Site key = public (safe in HTML). Secret key = server-only — never commit it to your frontend repo.'),
                __('Forms only protects POST / form surfaces; use All HTML pages only for site-wide challenges.'),
                __('Pair with Forms → “Require bot check” so Edge form endpoints reject submissions without a valid token.'),
                __('Requires Dply-hosted Edge delivery.'),
            ],
        ])

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])
    </section>

    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Bot protection') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if (! $enabled)
                    {{ __('Bot protection is off, so nobody is checked.') }}
                    @if ($relies !== '')
                        <span class="text-amber-600 dark:text-amber-300">{{ \Illuminate\Support\Str::ucfirst(__(':who ask for a check, which needs it on.', ['who' => $relies])) }}</span>
                    @endif
                @else
                    {{ __('Visitors are checked') }} <span class="text-brand-sage">{{ $mode === 'all' ? __('on every page') : __('on your forms') }}</span>.
                    @if ($relies !== '')
                        {{ \Illuminate\Support\Str::ucfirst(__(':who rely on it.', ['who' => $relies])) }}
                    @endif
                @endif
            </p>
        </div>

        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Settings') }}</p>
            @foreach ([
                'mode' => [$mode === 'all' ? __('Check visitors on every page') : __('Check visitors on form posts only'), $mode === 'all' ? __('Every page') : __('Forms only')],
                'keys' => [$keysLine, $hasKeys ? __('Ready') : __('Missing')],
            ] as $key => [$sentence, $state])
                <div class="border-b border-brand-ink/10" wire:key="bot-{{ $key }}">
                    @if ($canEdit)
                        <button type="button" wire:click="editSetting('{{ $key }}')" class="flex min-h-12 w-full items-center gap-3 py-3 text-left hover:bg-brand-sand/20">
                            <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $sentence }}</span>
                            <span @class(['shrink-0 text-xs', 'text-brand-moss' => $key !== 'keys' || $hasKeys, 'text-amber-600 dark:text-amber-300' => $key === 'keys' && ! $hasKeys])>{{ $state }}</span>
                            <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                        </button>
                    @else
                        <div class="flex min-h-12 items-center gap-3 py-3">
                            <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $sentence }}</span>
                            <span class="shrink-0 text-xs text-brand-moss">{{ $state }}</span>
                        </div>
                    @endif
                </div>
            @endforeach
            <label class="flex min-h-12 cursor-pointer items-center gap-3 border-b border-brand-ink/10 py-3">
                <span class="flex-1">
                    <span class="block text-sm text-brand-ink sm:text-base">{{ __('Bot protection is on') }}</span>
                    <span class="mt-0.5 block text-xs text-brand-moss">{{ $canGenerateKeys && ! $hasKeys ? __('Saves right away. Keys are generated for this app when you turn it on.') : __('Saves right away.') }}</span>
                </span>
                <input type="checkbox" wire:model.live="enabled" @disabled(! $canEdit) class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
            </label>
        </div>

        @if ($dependents !== [])
            <div>
                <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Also uses it') }}</p>
                @foreach ($dependents as $d)
                    <a href="{{ $d['kind'] === 'form' ? $formsUrl : $ratesUrl }}" wire:navigate class="flex min-h-12 items-center gap-3 border-b border-brand-ink/10 py-3 hover:bg-brand-sand/20">
                        <span class="flex-1 text-sm text-brand-ink sm:text-base">
                            @if ($d['kind'] === 'form')
                                {{ __('The') }} <span class="font-mono">{{ $d['path'] }}</span> {{ __('form rejects posts without a passed check') }}
                            @else
                                {{ __('Past its limit,') }} <span class="font-mono">{{ $d['path'] }}</span> {{ __('asks visitors to prove they’re human') }}
                            @endif
                        </span>
                        <span class="shrink-0 text-xs font-medium text-brand-sage">{{ $d['kind'] === 'form' ? __('Forms') : __('Rate limits') }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <x-modal name="edge-bot-protection" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
        @if ($editingSetting)
            <div class="space-y-5 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ $editingSetting === 'mode' ? __('Where to check') : __('Keys') }}</h2>
                    <button type="button" wire:click="closeSetting" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                @if ($editingSetting === 'mode')
                    <fieldset class="space-y-2">
                        @foreach (['forms' => [__('Form posts only'), __('Contact and signup posts. Recommended for most sites.')], 'all' => [__('Every page'), __('Every HTML page. For when bots are browsing heavily.')]] as $value => [$label, $desc])
                            <label @class(['flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3', 'border-brand-sage bg-brand-sage/5' => $mode === $value, 'border-brand-ink/10 hover:border-brand-ink/25' => $mode !== $value])>
                                <input type="radio" wire:model.live="mode" value="{{ $value }}" class="mt-0.5 h-4 w-4 border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                <span>
                                    <span class="block text-sm font-semibold text-brand-ink">{{ $label }}</span>
                                    <span class="block text-xs text-brand-moss">{{ $desc }}</span>
                                </span>
                            </label>
                        @endforeach
                    </fieldset>
                @else
                    @if ($canGenerateKeys)
                        <div class="flex items-center justify-between gap-3 rounded-lg bg-brand-sand/25 px-4 py-3">
                            <span>
                                <span class="block text-sm font-semibold text-brand-ink">{{ $keysGenerated ? __('Generated by dply') : __('Let dply generate keys') }}</span>
                                <span class="block text-xs text-brand-moss">{{ __('Made for this app’s addresses. Saved and live right away.') }}</span>
                            </span>
                            <button type="button" wire:click="generateKeys" @if ($hasKeys) wire:confirm="{{ __('Replace the current keys with new ones?') }}" @endif wire:loading.attr="disabled" wire:target="generateKeys" class="shrink-0 rounded-lg border border-brand-ink/15 px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                {{ $hasKeys ? __('Regenerate') : __('Generate keys') }}
                            </button>
                        </div>
                        <p class="text-xs text-brand-moss">{{ __('Or use keys from your own challenge provider account:') }}</p>
                    @else
                        <p class="text-xs text-brand-moss">
                            {{ __('Paste keys from your challenge provider.') }}
                            <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-sage hover:underline">{{ __('Get keys') }}</a>
                        </p>
                    @endif
                    <div>
                        <x-input-label for="bot-site-key" :value="__('Site key (public)')" />
                        <input id="bot-site-key" type="text" wire:model="site_key" autocomplete="off" class="{{ $field }}" />
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Safe to show in your HTML.') }}</p>
                        <x-input-error :messages="$errors->get('site_key')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="bot-secret-key" :value="__('Secret key')" />
                        <input id="bot-secret-key" type="password" wire:model="secret_key" autocomplete="off" class="{{ $field }}" />
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Stays on dply to verify checks. Never put it in your frontend code.') }}</p>
                        <x-input-error :messages="$errors->get('secret_key')" class="mt-1" />
                    </div>
                @endif

                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeSetting" class="rounded-lg border border-brand-ink/15 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                    <x-primary-button type="button" wire:click="saveSetting" wire:loading.attr="disabled" wire:target="saveSetting">{{ __('Save') }}</x-primary-button>
                </div>
            </div>
        @endif
    </x-modal>
</div>
