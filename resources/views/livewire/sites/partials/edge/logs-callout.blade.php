<section class="rounded-xl border border-sky-200/60 bg-sky-50/50 px-5 py-4 dark:border-sky-900/40 dark:bg-sky-950/20 sm:px-6">
    <div class="flex flex-wrap items-start gap-3">
        <x-heroicon-o-information-circle class="mt-0.5 h-5 w-5 shrink-0 text-sky-600 dark:text-sky-400" />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-brand-ink">{{ __('These are build and deploy logs — not visitor HTTP logs') }}</p>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Each entry is an Edge deployment: clone/build output, publish status, and failure reasons. Visitor request logs, page speed, and real-time traffic are not recorded here.') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <a
                    href="{{ route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'traffic']) }}"
                    wire:navigate
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-forest hover:underline dark:text-brand-sage"
                >
                    {{ __('Open Traffic & analytics') }}
                    <x-heroicon-o-arrow-right class="h-4 w-4" />
                </a>
            </div>
        </div>
    </div>
</section>
