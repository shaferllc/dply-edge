{{--
  Livewire fields read by this modal (must exist on the parent component
  via HandlesSiteRemovalFlow):
    - $removeSiteMode           (string: 'now' | 'in_30' | 'scheduled')
    - $deleteSiteConfirmName    (string — must equal $siteName to submit)
    - $scheduledSiteRemovalDate (string Y-m-d)
    - $siteDeletionReason       (string, optional)
  Methods:
    - closeRemoveSiteModal
    - submitRemoveSite
    - applySiteRemovalDatePreset(string $preset)
  Props: $open (bool), $siteName (string)
--}}
@php
    $nameMatches = trim($deleteSiteConfirmName ?? '') === $siteName;
    $submitDisabled = ! $nameMatches;
    $submitLabel = match ($removeSiteMode) {
        'in_30' => __('Remove in 30 minutes'),
        'scheduled' => __('Schedule removal'),
        default => __('Remove now'),
    };
@endphp
@if ($open)
    @teleport('body')
    <div
        class="fixed inset-0 isolate z-[100] overflow-y-auto overscroll-y-contain"
        role="dialog"
        aria-modal="true"
        aria-labelledby="remove-site-modal-title"
        x-trap="true"
        x-on:keydown.escape.window="$wire.closeRemoveSiteModal()"
        x-data="{ copied: false, copy() { navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($siteName) }}).then(() => { this.copied = true; setTimeout(() => this.copied = false, 1500); }); } }"
    >
        <div class="fixed inset-0 z-0 bg-brand-ink/60 backdrop-blur-sm" wire:click="closeRemoveSiteModal" wire:key="remove-site-backdrop"></div>
        <div class="relative z-10 flex min-h-full justify-center px-4 py-10 sm:px-6 sm:py-14">
            <div
                class="my-auto w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-brand-ink/5"
                @click.stop
                wire:key="remove-site-dialog"
            >
                <form wire:submit="submitRemoveSite" class="flex flex-col">
                    <div class="flex items-start gap-4 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-50 ring-1 ring-red-100">
                            <x-heroicon-o-trash class="h-5 w-5 text-red-600" aria-hidden="true" />
                        </span>
                        <div class="flex-1 pt-0.5">
                            <h2 id="remove-site-modal-title" class="text-base font-semibold text-brand-ink">{{ __('Delete site') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                {{ __('Removes the vhost, releases/repo/cert, supervisor rows, deploy SSH key, and re-syncs server crontab. This action cannot be undone.') }}
                            </p>
                        </div>
                        <button
                            type="button"
                            wire:click="closeRemoveSiteModal"
                            class="ms-2 -me-2 -mt-1 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-brand-mist transition hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-ink/10"
                            aria-label="{{ __('Close') }}"
                        >
                            <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
                        </button>
                    </div>

                    <div class="space-y-5 border-t border-brand-sand/60 bg-brand-cream/30 px-6 py-5 sm:px-7">
                        <fieldset>
                            <legend class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Timing') }}</legend>
                            @php
                                $modes = [
                                    'now' => ['label' => __('Now'), 'icon' => 'heroicon-o-bolt'],
                                    'in_30' => ['label' => __('In 30 min'), 'icon' => 'heroicon-o-clock'],
                                    'scheduled' => ['label' => __('On a date'), 'icon' => 'heroicon-o-calendar-days'],
                                ];
                            @endphp
                            <div class="mt-2 inline-flex w-full rounded-xl border border-brand-ink/10 bg-white p-1 text-xs shadow-sm">
                                @foreach ($modes as $modeValue => $modeMeta)
                                    <label class="flex-1 cursor-pointer">
                                        <input type="radio" wire:model.live="removeSiteMode" value="{{ $modeValue }}" class="peer sr-only" />
                                        <span class="flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 font-semibold text-brand-moss transition peer-checked:bg-red-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-red-500/40 hover:text-brand-ink peer-checked:hover:text-white">
                                            <x-dynamic-component :component="$modeMeta['icon']" class="h-4 w-4" aria-hidden="true" />
                                            {{ $modeMeta['label'] }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="mt-2 text-xs text-brand-mist">
                                @switch($removeSiteMode)
                                    @case('in_30')
                                        {{ __('Removal runs in 30 minutes. Cancel anytime from the site page before then.') }}
                                        @break
                                    @case('scheduled')
                                        {{ __('Removal runs at the end of the selected day in your app timezone.') }}
                                        @break
                                    @default
                                        {{ __('Removal starts immediately.') }}
                                @endswitch
                            </p>
                        </fieldset>

                        @if ($removeSiteMode === 'scheduled')
                            <div class="space-y-2">
                                <label for="scheduled-site-removal-date" class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Removal date') }}</label>
                                <input
                                    id="scheduled-site-removal-date"
                                    type="date"
                                    wire:model.live="scheduledSiteRemovalDate"
                                    min="{{ now()->addDay()->toDateString() }}"
                                    class="block w-full rounded-lg border-brand-ink/15 bg-white text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                                />
                                <div class="flex flex-wrap gap-1.5">
                                    <button type="button" wire:click="applySiteRemovalDatePreset('tomorrow')" class="rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink hover:border-red-200 hover:bg-red-50/50">{{ __('Tomorrow') }}</button>
                                    <button type="button" wire:click="applySiteRemovalDatePreset('week')" class="rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink hover:border-red-200 hover:bg-red-50/50">{{ __('In a week') }}</button>
                                    <button type="button" wire:click="applySiteRemovalDatePreset('month')" class="rounded-full border border-brand-ink/10 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink hover:border-red-200 hover:bg-red-50/50">{{ __('In a month') }}</button>
                                </div>
                                @error('scheduledSiteRemovalDate')
                                    <p class="text-xs text-red-700">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        @if ($removeSiteMode !== 'now')
                            <div class="space-y-2">
                                <label for="site-deletion-reason" class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Reason (optional)') }}</label>
                                <textarea
                                    id="site-deletion-reason"
                                    wire:model="siteDeletionReason"
                                    rows="2"
                                    placeholder="{{ __('Tagged in the audit log so teammates know why this site was scheduled for removal.') }}"
                                    class="block w-full rounded-lg border-brand-ink/15 bg-white text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                                ></textarea>
                            </div>
                        @endif

                        <div class="space-y-2">
                            <label for="delete-site-confirm-name" class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">
                                {{ __('Type the site name to confirm') }}
                            </label>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    @click="copy()"
                                    :aria-label="copied ? '{{ __('Copied') }}' : '{{ __('Copy site name') }}'"
                                    class="group inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1.5 font-mono text-xs text-brand-ink shadow-sm transition hover:border-brand-ink/20 hover:bg-brand-sand/30 focus:outline-none focus:ring-2 focus:ring-red-500/40"
                                >
                                    <span>{{ $siteName }}</span>
                                    <span class="flex h-4 w-4 items-center justify-center">
                                        <svg x-show="!copied" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4 text-brand-mist transition group-hover:text-brand-moss">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375A1.875 1.875 0 0 1 13.875 22.5h-9A1.875 1.875 0 0 1 3 20.625V9.75A1.875 1.875 0 0 1 4.875 7.875H8.25M9.75 6.375h9A1.875 1.875 0 0 1 20.625 8.25v9A1.875 1.875 0 0 1 18.75 19.125h-9A1.875 1.875 0 0 1 7.875 17.25v-9A1.875 1.875 0 0 1 9.75 6.375Z" />
                                        </svg>
                                        <svg x-show="copied" x-cloak xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.4" stroke="currentColor" class="h-4 w-4 text-emerald-600">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                    </span>
                                </button>
                                <span x-show="copied" x-cloak class="text-xs font-semibold text-emerald-700" aria-live="polite">{{ __('Copied — paste below') }}</span>
                            </div>
                            <input
                                id="delete-site-confirm-name"
                                type="text"
                                wire:model.live="deleteSiteConfirmName"
                                autocomplete="off"
                                spellcheck="false"
                                placeholder="{{ __('Paste the site name') }}"
                                @class([
                                    'block w-full rounded-lg bg-white font-mono text-sm shadow-sm transition focus:border-red-500 focus:ring-red-500',
                                    'border-brand-ink/15' => ! $nameMatches,
                                    'border-emerald-300 ring-1 ring-emerald-200' => $nameMatches,
                                ])
                            />
                            @error('deleteSiteConfirmName')
                                <p class="text-xs text-red-700">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex flex-col-reverse gap-2 border-t border-brand-sand/60 bg-white px-6 py-4 sm:flex-row sm:justify-end sm:gap-2 sm:px-7">
                        <button
                            type="button"
                            wire:click="closeRemoveSiteModal"
                            wire:loading.attr="disabled"
                            wire:target="submitRemoveSite"
                            class="inline-flex justify-center rounded-lg border border-brand-ink/10 bg-white px-4 py-2 text-sm font-semibold text-brand-ink transition hover:bg-brand-sand/30 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            class="inline-flex min-w-[10rem] items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500/40 disabled:cursor-not-allowed disabled:bg-red-300"
                            @disabled($submitDisabled)
                            wire:loading.attr="disabled"
                            wire:target="submitRemoveSite"
                        >
                            <span wire:loading.remove wire:target="submitRemoveSite" class="inline-flex items-center gap-2">
                                <x-heroicon-m-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ $submitLabel }}
                            </span>
                            <span wire:loading wire:target="submitRemoveSite" class="inline-flex items-center gap-2">
                                <x-spinner size="sm" variant="white" />
                                {{ __('Confirming…') }}
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endteleport
@endif
