<div wire:init="loadTraffic">
    @if ($loaded)
        @include('livewire.sites.partials.edge.traffic')
    @else
        {{-- Skeleton of the loaded page: summary sentence, then the "Look closer" rows. --}}
        <section class="space-y-8 px-5 py-8 sm:px-10 sm:py-10" aria-busy="true" aria-live="polite">
            <span class="sr-only">{{ __('Loading traffic…') }}</span>
            <div class="motion-safe:animate-pulse">
                <div class="h-3 w-36 rounded bg-brand-ink/10"></div>
                <div class="mt-4 max-w-3xl space-y-3">
                    <div class="h-7 w-full rounded-md bg-brand-ink/10"></div>
                    <div class="h-7 w-11/12 rounded-md bg-brand-ink/10"></div>
                    <div class="h-7 w-2/5 rounded-md bg-brand-ink/10"></div>
                </div>
            </div>
            <div>
                <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Look closer') }}</p>
                @foreach (['w-3/5', 'w-2/5', 'w-4/5', 'w-1/2', 'w-1/3'] as $i => $width)
                    <div class="flex min-h-12 items-center gap-3 border-b border-brand-ink/10 py-3 motion-safe:animate-pulse" style="animation-delay: {{ $i * 120 }}ms">
                        <div class="h-4 {{ $width }} rounded bg-brand-ink/10"></div>
                        <div class="ml-auto h-3 w-14 rounded bg-brand-ink/10"></div>
                        <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist/40" aria-hidden="true" />
                    </div>
                @endforeach
                <p class="mt-4 inline-flex items-center gap-2 text-xs text-brand-mist">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-sage motion-safe:animate-ping"></span>
                    {{ __('Counting the last 7 days of requests…') }}
                </p>
            </div>
        </section>
    @endif
</div>
