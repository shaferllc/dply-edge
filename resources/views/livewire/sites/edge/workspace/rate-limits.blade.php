@php
    use App\Livewire\Sites\Edge\Workspace\RateLimits;

    $canEdit = auth()->user()?->can('update', $site) && $managedDelivery;
    $where = fn (string $path): string => in_array(trim($path), ['*', '/*'], true) ? __('every page') : $path;
    $challengeRules = collect($rules)->where('action', 'challenge');
    $field = 'block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900';
    $burst = max(1, (int) ceil(((int) $rule_limit / max(1, (int) $rule_window)) * 2));
    $blockedAfter = (int) ceil((int) $rule_limit / $burst);
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'rate-limits',
            'what' => __('Rate limits count requests per visitor IP on matching paths. When someone exceeds the limit in the window, Edge stops them before your site or origin does the work — unlike Waiting room, this is about abusive volume from one client, not total concurrent humans.'),
            'steps' => [
                __('Add a rule: start from Login, API, Forms or Whole site, or set the path, the allowance and the window yourself.'),
                __('Choose Block (plain HTTP 429) or Challenge (bot check page — needs Bot protection keys).'),
                __('Save the rule. It applies on the next request; the first rule turns rate limits on.'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Bot protection'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'bot-protection']),
                ],
                [
                    'label' => __('Waiting room'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'waiting-room']),
                ],
            ],
            'tips' => [
                __('Protect hot endpoints tightly (/api/login, form POSTs); avoid ultra-low limits on /* or you will throttle real browsers loading assets.'),
                __('Challenge without Bot protection falls back to Block (429).'),
                __('Requires Dply-hosted Edge delivery.'),
            ],
        ])

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])
    </section>

    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Rate limits') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if ($rules === [])
                    {{ __('No rate limits yet, so one visitor can send as many requests as they like. Add a rule to protect a login page or an API.') }}
                @elseif (! $enabled)
                    {{ trans_choice(':count rule is set up, but rate limits are off, so nothing is limited.|:count rules are set up, but rate limits are off, so nothing is limited.', count($rules)) }}
                @else
                    <span class="text-brand-sage">{{ trans_choice(':count rule|:count rules', count($rules)) }}</span>
                    {{ trans_choice('stops any one visitor from hammering your app.|stop any one visitor from hammering your app.', count($rules)) }}
                    @if ($challengeRules->isEmpty())
                        {{ __('Past a limit they’re blocked for the rest of the window.') }}
                    @else
                        {{ __('Past a limit they’re blocked, or asked to prove they’re human on') }}
                        <span class="font-mono">{{ $challengeRules->pluck('path')->map($where)->implode(', ') }}</span>.
                    @endif
                @endif
            </p>
        </div>

        <div>
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 pb-2">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Rules') }}</p>
                @if ($canEdit)
                    <button type="button" wire:click="newRule" class="inline-flex min-h-9 items-center gap-1 text-sm font-medium text-brand-sage hover:underline">
                        <x-heroicon-m-plus class="h-4 w-4" aria-hidden="true" />{{ __('Add a rule') }}
                    </button>
                @endif
            </div>
            @foreach ($rules as $i => $rule)
                @php
                    $sentence = __('On :where, one visitor gets :n requests :per', ['where' => $where($rule['path']), 'n' => number_format($rule['limit']), 'per' => RateLimits::per($rule['window_seconds'])]);
                    $then = $rule['action'] === 'challenge' ? __('Then bot check') : __('Then block');
                @endphp
                <div class="border-b border-brand-ink/10" wire:key="rate-rule-{{ $i }}">
                    @if ($canEdit)
                        <button type="button" wire:click="editRule({{ $i }})" class="flex min-h-12 w-full items-center gap-3 py-3 text-left hover:bg-brand-sand/20">
                            <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $sentence }}</span>
                            <span class="shrink-0 text-xs text-brand-moss">{{ $then }}</span>
                            <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                        </button>
                    @else
                        <div class="flex min-h-12 items-center gap-3 py-3">
                            <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $sentence }}</span>
                            <span class="shrink-0 text-xs text-brand-moss">{{ $then }}</span>
                        </div>
                    @endif
                </div>
            @endforeach
            @if ($rules === [])
                <p class="border-b border-brand-ink/10 py-4 text-sm text-brand-moss">{{ __('No rules yet.') }}</p>
            @endif
            <label class="flex min-h-12 cursor-pointer items-center gap-3 border-b border-brand-ink/10 py-3">
                <span class="flex-1">
                    <span class="block text-sm text-brand-ink sm:text-base">{{ __('Rate limits are on') }}</span>
                    <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Saves right away. When off, rules are ignored and every request passes through.') }}</span>
                </span>
                <input type="checkbox" wire:model.live="enabled" @disabled(! $canEdit) class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
            </label>
        </div>

        @if ($challengeRules->isNotEmpty() && ! $botProtectionReady)
            <p class="text-sm text-amber-700 dark:text-amber-300">
                {{ __('Bot protection keys are missing, so “ask to prove they’re human” acts like a block until you set them up.') }}
                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'bot-protection']) }}" wire:navigate class="font-medium underline">{{ __('Bot protection') }}</a>
            </p>
        @endif
    </section>

    <x-modal name="edge-rate-rule" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        @if ($editingRule !== null)
            <div class="space-y-5 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ $editingRule === -1 ? __('Add a rule') : __('Rule for :path', ['path' => $where($rules[$editingRule]['path'] ?? $rule_path)]) }}</h2>
                    <button type="button" wire:click="closeRule" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-2 text-xs text-brand-moss">
                    {{ __('Start from') }}
                    @foreach (['login' => __('Login'), 'api' => __('API'), 'forms' => __('Forms'), 'site' => __('Whole site')] as $key => $label)
                        <button type="button" wire:click="usePreset('{{ $key }}')" class="inline-flex min-h-8 items-center rounded-full border border-brand-ink/15 px-3 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ $label }}</button>
                    @endforeach
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <x-input-label for="rule-path" :value="__('On')" />
                        <input id="rule-path" type="text" wire:model.live.debounce.300ms="rule_path" placeholder="/api/*" class="{{ $field }} mt-1" />
                        <x-input-error :messages="$errors->get('rule_path')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="rule-limit" :value="__('Allow')" />
                        <input id="rule-limit" type="number" min="1" wire:model.live.debounce.300ms="rule_limit" class="{{ $field }} mt-1" />
                        <x-input-error :messages="$errors->get('rule_limit')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="rule-window" :value="__('Requests every (seconds)')" />
                        <input id="rule-window" type="number" min="1" wire:model.live.debounce.300ms="rule_window" class="{{ $field }} mt-1" />
                        <x-input-error :messages="$errors->get('rule_window')" class="mt-1" />
                    </div>
                </div>

                <fieldset class="space-y-2">
                    <legend class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Then') }}</legend>
                    @foreach (['block' => [__('Block (HTTP 429)'), __('A plain “Too many requests” with Retry-After. Best for APIs and scripts.')], 'challenge' => [__('Ask to prove they’re human'), __('A bot check on the same URL; passing it lets the request through. Best for login pages. Needs Bot protection keys.')]] as $value => [$label, $desc])
                        <label @class(['flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3', 'border-brand-sage bg-brand-sage/5' => $rule_action === $value, 'border-brand-ink/10 hover:border-brand-ink/25' => $rule_action !== $value])>
                            <input type="radio" wire:model.live="rule_action" value="{{ $value }}" class="mt-0.5 h-4 w-4 border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                            <span>
                                <span class="block text-sm font-semibold text-brand-ink">{{ $label }}</span>
                                <span class="block text-xs text-brand-moss">{{ $desc }}</span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                @if ((int) $rule_limit > 0 && (int) $rule_window > 0)
                    <p class="rounded-lg bg-brand-sand/25 px-3 py-2.5 text-sm text-brand-ink">
                        {{ __('That’s :pace per visitor. Someone sending :burst a second is cut off after about :after seconds, until the window resets.', ['pace' => RateLimits::pace((int) $rule_limit, (int) $rule_window), 'burst' => $burst, 'after' => $blockedAfter]) }}
                    </p>
                @endif

                <div class="flex items-center justify-between gap-2">
                    @if ($editingRule >= 0)
                        <button type="button" wire:click="removeEditingRule" class="text-sm font-semibold text-red-600 hover:underline">{{ __('Remove rule') }}</button>
                    @else
                        <span></span>
                    @endif
                    <span class="flex gap-2">
                        <button type="button" wire:click="closeRule" class="rounded-lg border border-brand-ink/15 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                        <x-primary-button type="button" wire:click="saveRule" wire:loading.attr="disabled" wire:target="saveRule">{{ __('Save') }}</x-primary-button>
                    </span>
                </div>
            </div>
        @endif
    </x-modal>
</div>
