<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'waiting-room',
            'what' => __('When too many people hit a protected path at once, Edge holds the extras in a queue so the live site stays within your capacity. No separate queue domain — people wait on the URL they opened.'),
            'steps' => [
                __('Visitor opens a matching path on your Edge hostname (e.g. /checkout).'),
                __('If there is room under max active + admits/minute, Edge sets a short session cookie and lets them through to your site.'),
                __('If the room is full, Edge serves a “You’re in line” page on that same URL (HTTP 503) and auto-refreshes until a slot opens.'),
                __('After the session minutes expire, they may re-queue on the next visit.'),
            ],
            'tips' => [
                __('They wait in the browser on your Edge URL — not email, not a third-party lobby, not a different hostname.'),
                __('Protect only the hot paths (e.g. /checkout/*). Leave marketing pages out so people can still read while others queue.'),
                __('Start with a low max active, then raise once the queue drains cleanly.'),
                __('Requires Dply-hosted Edge delivery. Every change goes live on the next request.'),
            ],
        ])

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])
    </section>

    @php
        $canEdit = auth()->user()?->can('update', $site) && $managedDelivery;
        $pathList = collect(preg_split('/\r\n|\r|\n/', $paths) ?: [])->map(fn ($p) => trim($p))->filter()->values();
        $pathText = $pathList->isEmpty() || $pathList->all() === ['/*'] ? __('your whole site') : $pathList->implode(', ');
        $rush = max(1000, (int) $total_active_users * 5);
        $waiting = max(0, $rush - (int) $total_active_users);
        $mins = (int) ceil($waiting / max(1, (int) $new_users_per_minute));
        $waitText = $mins >= 60 ? __(':h h :m min', ['h' => intdiv($mins, 60), 'm' => $mins % 60]) : trans_choice(':count minute|:count minutes', $mins);
        $rows = [
            'paths' => [__('Only these pages can have a line'), $pathList->implode(' ') ?: '/*'],
            'active' => [__('Up to :n people are let in at the same time', ['n' => number_format($total_active_users)]), number_format($total_active_users)],
            'rate' => [__(':n more people get in each minute once there’s room', ['n' => number_format($new_users_per_minute)]), __(':n / min', ['n' => number_format($new_users_per_minute)])],
            'session' => [trans_choice('An admitted visitor keeps their spot for :count minute|An admitted visitor keeps their spot for :count minutes', $session_duration_minutes), __(':n min', ['n' => $session_duration_minutes])],
        ];
        $modal = [
            'paths' => [__('Which pages wait'), __('Paths, one per line'), __('Leave marketing pages out so people can still read while others wait. Empty means the whole site.')],
            'active' => [__('How many at once'), __('Max active visitors'), __('Start low, then raise it once the line drains cleanly.')],
            'rate' => [__('How fast the line moves'), __('Let in per minute'), __('Once there’s room, this many newcomers are admitted each minute.')],
            'session' => [__('How long a spot lasts'), __('Session length (minutes)'), __('After this they may have to line up again on their next visit.')],
        ];
        $field = 'block rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900';
    @endphp

    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Waiting room') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if ($enabled)
                    {{ __('Up to') }} <span class="text-brand-sage">{{ trans_choice(':count person|:count people', $total_active_users, ['count' => number_format($total_active_users)]) }}</span>
                    {{ __('can use :where at once. Past that, visitors wait in line and :n more get in each minute.', ['where' => $pathText, 'n' => number_format($new_users_per_minute)]) }}
                @else
                    {{ __('The waiting room is off, so everyone goes straight to your app. When it’s on, up to :max people can use :where at once.', ['max' => number_format($total_active_users), 'where' => $pathText]) }}
                @endif
            </p>
        </div>

        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('How it works') }}</p>
            <ul>
                @foreach ($rows as $key => [$sentence, $value])
                    <li class="border-b border-brand-ink/10" wire:key="wr-{{ $key }}">
                        @if ($canEdit)
                            <button type="button" wire:click="editSetting('{{ $key }}')" class="flex min-h-12 w-full items-center gap-3 py-3 text-left hover:bg-brand-sand/20">
                                <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $sentence }}</span>
                                <span class="max-w-[40%] truncate font-mono text-xs text-brand-moss">{{ $value }}</span>
                                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                            </button>
                        @else
                            <div class="flex min-h-12 items-center gap-3 py-3">
                                <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $sentence }}</span>
                                <span class="max-w-[40%] truncate font-mono text-xs text-brand-moss">{{ $value }}</span>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            <label class="flex min-h-12 cursor-pointer items-center gap-3 border-b border-brand-ink/10 py-3">
                <span class="flex-1">
                    <span class="block text-sm text-brand-ink sm:text-base">{{ __('Waiting room is on') }}</span>
                    <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Saves right away. When off, every visitor goes straight to your app.') }}</span>
                </span>
                <input type="checkbox" wire:model.live="enabled" @disabled(! $canEdit) class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
            </label>
        </div>

        <button type="button" x-on:click="$dispatch('open-modal', 'edge-waiting-room-preview')" class="text-sm font-medium text-brand-sage hover:underline">{{ __('See what visitors see when it’s full') }}</button>
    </section>

    <x-modal name="edge-waiting-room" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
        @if ($editingSetting)
            @php [$title, $label, $hint] = $modal[$editingSetting]; @endphp
            <div class="space-y-5 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ $title }}</h2>
                    <button type="button" wire:click="closeSetting" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                <div>
                    <x-input-label for="wr-field" :value="$label" />
                    @if ($editingSetting === 'paths')
                        <textarea id="wr-field" wire:model="paths" rows="4" placeholder="/checkout/*&#10;/tickets/*" class="{{ $field }} mt-1 w-full"></textarea>
                        <x-input-error :messages="$errors->get('paths')" class="mt-1" />
                    @else
                        @php
                            [$model, $min, $max, $unit] = match ($editingSetting) {
                                'active' => ['total_active_users', 1, 100000, __('at once')],
                                'rate' => ['new_users_per_minute', 1, 10000, __('per minute')],
                                default => ['session_duration_minutes', 1, 1440, __('minutes')],
                            };
                        @endphp
                        <span class="mt-1 flex items-center gap-3">
                            <input id="wr-field" type="number" min="{{ $min }}" max="{{ $max }}" wire:model.live.debounce.300ms="{{ $model }}" class="{{ $field }} w-36" />
                            <span class="text-sm text-brand-moss">{{ $unit }}</span>
                        </span>
                        <x-input-error :messages="$errors->get($model)" class="mt-1" />
                    @endif
                    <p class="mt-2 text-xs text-brand-moss">{{ $hint }}</p>
                </div>

                @if (in_array($editingSetting, ['active', 'rate'], true))
                    <p class="rounded-lg bg-brand-sand/25 px-3 py-2.5 text-sm text-brand-ink">
                        {{ __('If :rush people arrive at once, :max get in right away and the last one waits about', ['rush' => number_format($rush), 'max' => number_format($total_active_users)]) }}
                        <span class="font-semibold text-amber-600 dark:text-amber-300">{{ $waitText }}</span>.
                        <span class="block text-xs text-brand-moss">{{ __('Rough estimate: assumes nobody leaves early.') }}</span>
                    </p>
                @endif

                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeSetting" class="rounded-lg border border-brand-ink/15 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                    <x-primary-button type="button" wire:click="saveSetting" wire:loading.attr="disabled" wire:target="saveSetting">{{ __('Save') }}</x-primary-button>
                </div>
            </div>
        @endif
    </x-modal>

    <x-modal name="edge-waiting-room-preview" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        <div class="space-y-4 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('What visitors see when it’s full') }}</h2>
                <button type="button" x-on:click="$dispatch('close-modal', 'edge-waiting-room-preview')" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                    <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
            <div class="overflow-hidden rounded-xl border border-brand-ink/15 shadow-sm">
                <div class="flex items-center gap-2 bg-[#171a0e] px-3 py-2">
                    <span class="flex gap-1" aria-hidden="true">
                        <span class="size-2 rounded-full bg-white/25"></span>
                        <span class="size-2 rounded-full bg-white/25"></span>
                        <span class="size-2 rounded-full bg-white/25"></span>
                    </span>
                    <span class="min-w-0 truncate font-mono text-xs text-[#e8ece3]/80">{{ parse_url((string) ($site->edgeLiveUrl() ?? ''), PHP_URL_HOST) ?: __('your app') }}</span>
                </div>
                <div class="bg-[#f6f5ef] px-6 py-12 text-center">
                    <p class="text-lg font-semibold text-[#171a0e]">{{ __('You’re in line') }}</p>
                    <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-[#3f4438]">{{ __('This site is at capacity. We’ll refresh automatically.') }}</p>
                </div>
            </div>
            <ol class="grid gap-3 text-sm sm:grid-cols-3">
                <li><span class="block font-medium text-brand-ink">{{ __('1 · Arrive') }}</span><span class="text-xs text-brand-moss">{{ __('On the URL they opened. No redirect to another domain.') }}</span></li>
                <li><span class="block font-medium text-brand-ink">{{ __('2 · Wait if full') }}</span><span class="text-xs text-brand-moss">{{ __('This page (HTTP 503), refreshing every few seconds.') }}</span></li>
                <li><span class="block font-medium text-brand-ink">{{ __('3 · Enter') }}</span><span class="text-xs text-brand-moss">{{ __('A cookie lets them in for the session length.') }}</span></li>
            </ol>
            <p class="text-xs text-brand-moss">{{ __('Served by the Edge, not your build. Branding isn’t customizable yet.') }}</p>
        </div>
    </x-modal>
</div>
