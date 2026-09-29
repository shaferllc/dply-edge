{{-- Toast stack (Alpine toastStore). Close control is always top-right. --}}
<div x-bind:class="regionClass" aria-live="polite">
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-show="true"
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
