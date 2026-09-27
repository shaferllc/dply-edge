<div @if ($polling) wire:poll.2s="tail" @endif>
    @if ($buffer === '' && ! $logExists)
        <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-5 py-6 text-center text-xs text-brand-moss">
            {{ __('Waiting for build to start — log output will appear here…') }}
        </div>
    @elseif ($buffer === '')
        <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-brand-sand/20 px-5 py-6 text-center text-xs text-brand-moss">
            {{ __('Build started — first output is on its way…') }}
        </div>
    @else
        <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-brand-ink">
            <div class="flex items-center justify-between border-b border-white/10 px-4 py-2">
                <div class="flex items-center gap-2">
                    <span class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-sand/80">{{ __('Live build output') }}</span>
                    @if ($polling)
                        <span class="inline-flex h-2 w-2 animate-pulse rounded-full bg-emerald-400" aria-hidden="true"></span>
                    @endif
                </div>
                <span class="text-2xs uppercase tracking-wide text-brand-sand/50">{{ $polling ? __('streaming') : __('finished') }}</span>
            </div>
            {{-- x-data scroll-pin: keeps the pre stuck to the bottom as new chunks land,
                 unless the user has scrolled up to read history (release pin on scroll). --}}
            <pre
                x-data="{
                    pinned: true,
                    onScroll() {
                        const el = $el;
                        this.pinned = (el.scrollHeight - el.scrollTop - el.clientHeight) < 16;
                    },
                    init() {
                        this.$nextTick(() => { $el.scrollTop = $el.scrollHeight; });
                        this.stopHook = Livewire.hook('morph.updated', () => {
                            const el = this.$el;
                        if (this.pinned && el) { el.scrollTop = el.scrollHeight; }
                        });
                    },
                    destroy() { this.stopHook?.(); },
                }"
                x-on:scroll.throttle.100ms="onScroll"
                class="max-h-[28rem] overflow-auto px-4 py-3 font-mono text-xs leading-relaxed text-brand-cream whitespace-pre-wrap break-words"
            >{!! \App\Modules\Edge\Support\AnsiHtml::toHtml($buffer) !!}</pre>
        </div>
    @endif
</div>
