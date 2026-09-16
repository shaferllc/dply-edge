@if (! empty($edgeDeliveryBanner))
    @php
        $tone = $edgeDeliveryBanner['tone'] ?? 'amber';
        $bannerClass = match ($tone) {
            'emerald' => 'border-emerald-200 bg-emerald-50/80 text-emerald-950 dark:border-raw-emerald-900/40 dark:bg-raw-emerald-950/30 dark:text-raw-emerald-200',
            'rose' => 'border-rose-200 bg-rose-50/80 text-rose-950 dark:border-raw-rose-900/40 dark:bg-rose-950/30 dark:text-raw-rose-200',
            'sky' => 'border-sky-200 bg-sky-50/80 text-sky-950 dark:border-sky-900/40 dark:bg-sky-950/30 dark:text-raw-sky-200',
            default => 'border-amber-200 bg-amber-50/80 text-amber-950 dark:border-raw-amber-900/40 dark:bg-raw-amber-950/30 dark:text-raw-amber-200',
        };
        $copyButtonClass = match ($tone) {
            'emerald' => 'text-emerald-900/70 hover:text-emerald-700',
            'rose' => 'text-rose-900/70 hover:text-rose-700',
            'sky' => 'text-sky-900/70 hover:text-sky-700',
            default => 'text-amber-900/70 hover:text-amber-700',
        };
    @endphp
    <div
        x-data="{ copied: false, copy() { navigator.clipboard.writeText(@js($edgeDeliveryBanner['message'])); this.copied = true; setTimeout(() => { this.copied = false; }, 1500); } }"
        class="rounded-xl border px-3 py-2.5 text-sm {{ $bannerClass }}"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="font-semibold">{{ $edgeDeliveryBanner['title'] }}</p>
                <p class="mt-1 break-words leading-relaxed opacity-90">{{ $edgeDeliveryBanner['message'] }}</p>
            </div>
            <button
                type="button"
                x-on:click="copy()"
                class="shrink-0 inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-2xs font-semibold uppercase tracking-wide {{ $copyButtonClass }}"
                :title="copied ? '{{ __('Copied') }}' : '{{ __('Copy message') }}'"
            >
                <x-heroicon-o-clipboard x-show="!copied" class="h-4 w-4" />
                <x-heroicon-s-check x-show="copied" x-cloak class="h-4 w-4" />
                <span x-show="!copied">{{ __('Copy') }}</span>
                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
            </button>
        </div>
    </div>
@endif
