{{-- Toast stack (Alpine toastStore). Close control is always top-right.
     type "live" (a deploy went live, deploy-pill.js) gets its own launch card. --}}
<div x-bind:class="regionClass" aria-live="polite">
    <template x-for="toast in toasts" :key="toast.id">
        <div>
            <template x-if="toast.type === 'live'">
                <div
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-y-3 scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                    class="dply-live-toast relative w-[23rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-[#c3f53c]/30 bg-[#0b0d0a] text-[#e8ece3] shadow-2xl shadow-[#c3f53c]/15"
                    role="status"
                >
                    {{-- Glow behind the badge, and a faint grid that drifts. --}}
                    <div class="pointer-events-none absolute -left-10 -top-12 h-40 w-40 rounded-full bg-[#c3f53c]/25 blur-3xl" aria-hidden="true"></div>
                    <div class="dply-live-grid pointer-events-none absolute inset-0 opacity-[0.07]" aria-hidden="true"></div>

                    <div class="relative flex items-start gap-3.5 py-4 pl-4 pr-10">
                        <div class="relative mt-0.5 h-11 w-11 shrink-0" aria-hidden="true">
                            <span class="dply-live-ring absolute inset-0 rounded-full border-2 border-[#c3f53c]"></span>
                            <span class="dply-live-ring dply-live-ring-late absolute inset-0 rounded-full border border-[#c3f53c]/60"></span>
                            <span class="dply-live-badge absolute inset-0 grid place-items-center rounded-full bg-[#c3f53c] text-[#0b0d0a] shadow-[0_0_24px_rgba(195,245,60,0.55)]">
                                <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round">
                                    <path class="dply-live-check" d="M5 12.5l4.5 4.5L19 7.5" />
                                </svg>
                            </span>
                            <template x-for="n in 8" :key="n">
                                <span class="dply-live-spark absolute left-1/2 top-1/2 h-1.5 w-1.5 rounded-full bg-[#d9ff7a]" :style="`--a: ${n * 45}deg`"></span>
                            </template>
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.18em] text-[#c3f53c]">
                                <span class="relative flex h-1.5 w-1.5">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#c3f53c] opacity-75 motion-reduce:hidden"></span>
                                    <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-[#c3f53c]"></span>
                                </span>
                                {{ __('Deployed') }}
                            </p>
                            <p class="mt-1 text-[15px] font-semibold leading-snug text-white" x-text="toast.message"></p>
                            <p x-show="toast.detail" class="mt-0.5 truncate font-mono text-xs text-[#a4ab99]" x-text="toast.detail"></p>
                            <a
                                x-show="toast.url"
                                :href="toast.url"
                                :target="toast.newTab ? '_blank' : null"
                                :rel="toast.newTab ? 'noopener' : null"
                                class="group mt-3 inline-flex items-center gap-1.5 rounded-lg bg-[#c3f53c] px-3 py-1.5 text-xs font-semibold text-[#0b0d0a] shadow-[0_0_0_0_rgba(195,245,60,0.6)] transition hover:bg-[#d9ff7a] hover:shadow-[0_0_18px_2px_rgba(195,245,60,0.35)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#d9ff7a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0b0d0a]"
                            >
                                <span x-text="toast.linkLabel"></span>
                                <svg viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5 transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.22 14.78a.75.75 0 0 0 1.06 0l7.22-7.22v5.69a.75.75 0 0 0 1.5 0v-7.5a.75.75 0 0 0-.75-.75h-7.5a.75.75 0 0 0 0 1.5h5.69l-7.22 7.22a.75.75 0 0 0 0 1.06Z" clip-rule="evenodd" />
                                </svg>
                            </a>
                        </div>
                    </div>

                    <button
                        type="button"
                        @click="remove(toast.id)"
                        class="absolute right-2 top-2 inline-flex h-7 w-7 items-center justify-center rounded-md text-base leading-none text-[#a4ab99] transition hover:bg-white/10 hover:text-white"
                        aria-label="{{ __('Dismiss') }}"
                    >&times;</button>

                    {{-- Time left before it goes away. --}}
                    <div class="absolute inset-x-0 bottom-0 h-0.5 bg-white/5" aria-hidden="true">
                        <div class="dply-live-bar h-full origin-left bg-gradient-to-r from-[#c3f53c] to-[#d9ff7a]" :style="`animation-duration: ${toast.duration}ms`"></div>
                    </div>
                </div>
            </template>

            <template x-if="toast.type !== 'live'">
                <div
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 translate-y-2"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    :class="toast.type === 'error'
                        ? 'bg-red-50 border-red-200 text-red-800'
                        : toast.type === 'warning'
                            ? 'bg-amber-50 border-amber-200 text-amber-950'
                            : 'bg-brand-ink text-brand-cream'"
                    class="relative min-w-[200px] max-w-xl rounded-lg border py-3 pl-4 pr-10 text-sm shadow-lg"
                >
                    <span class="block" x-text="toast.message"></span>
                    <a x-show="toast.url" :href="toast.url" :target="toast.newTab ? '_blank' : null" :rel="toast.newTab ? 'noopener' : null" class="mt-1 inline-block text-xs font-semibold underline underline-offset-2" x-text="toast.linkLabel"></a>
                    <button
                        type="button"
                        @click="remove(toast.id)"
                        class="absolute right-1.5 top-1.5 inline-flex h-7 w-7 items-center justify-center rounded-md text-base leading-none opacity-70 hover:bg-black/5 hover:opacity-100"
                        aria-label="{{ __('Dismiss') }}"
                    >&times;</button>
                </div>
            </template>
        </div>
    </template>
</div>
