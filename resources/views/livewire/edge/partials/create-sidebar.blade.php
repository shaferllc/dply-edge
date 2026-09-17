{{-- Edge create flow — sticky companion summary (sibling of profile-shell form). --}}
@php
    $appLabel = trim((string) ($form->name ?? '')) !== '' ? $form->name : __('Untitled edge app');
    $repoLabel = trim((string) $repo) !== '' ? $repo : __('Repository (unset)');
    $buildCommand = trim((string) ($form->build_command ?? ''));
    $outputDir = trim((string) ($form->output_dir ?? ''));
    $runtimeMode = (string) ($form->runtime_mode ?? 'static');
    $runtimeLabel = match ($runtimeMode) {
        'hybrid' => __('Hybrid'),
        'ssr' => __('Worker SSR'),
        'container' => __('Container'),
        default => __('Static / SSG'),
    };
    $deliveryLabel = ($form->delivery_mode ?? 'managed') === 'byo'
        ? __('Your account')
        : __('Dply-hosted');
@endphp
{{-- pb-24 clears the floating dock; max-h leaves room below sticky top so the cost row stays in view. --}}
<aside class="space-y-4 pb-24 lg:sticky lg:top-24 lg:max-h-[calc(100vh-8rem)] lg:overflow-y-auto lg:overscroll-contain lg:self-start">
    <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
        <div class="border-b border-brand-ink/8 bg-gradient-to-br from-brand-sage/10 via-transparent to-brand-gold/10 px-5 py-3.5 dark:border-brand-mist/15">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-brand-sage">{{ __('Deploy summary') }}</p>
            <p class="mt-1 truncate text-sm font-semibold text-brand-ink">{{ $appLabel }}</p>
        </div>
        <dl class="divide-y divide-brand-ink/8 text-sm dark:divide-brand-mist/15">
            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Source') }}</dt>
                <dd class="min-w-0 text-end font-mono text-xs text-brand-ink dark:text-brand-ink">
                    <span class="block truncate">{{ $repoLabel }}</span>
                    @if (trim((string) $branch) !== '')
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Branch') }} {{ $branch }}</span>
                    @endif
                </dd>
            </div>
            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Build') }}</dt>
                <dd class="min-w-0 text-end text-xs font-semibold text-brand-ink dark:text-brand-ink">
                    <span class="block truncate font-mono font-normal">{{ $runtimeMode === 'container' ? __('Docker image') : ($buildCommand !== '' ? $buildCommand : __('Detected / default')) }}</span>
                    <span class="mt-0.5 block text-xs font-normal text-brand-moss">
                        {{ $runtimeMode === 'container' ? __('Your Dockerfile, or generated') : __('Output').' '.($outputDir !== '' ? $outputDir : 'dist') }}
                    </span>
                </dd>
            </div>
            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Mode') }}</dt>
                <dd class="min-w-0 text-end text-xs font-semibold text-brand-ink dark:text-brand-ink">{{ $runtimeLabel }}</dd>
            </div>
            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Hosting') }}</dt>
                <dd class="min-w-0 text-end text-xs font-semibold text-brand-ink dark:text-brand-ink">{{ $deliveryLabel }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3 bg-brand-sand/20 px-5 py-3 dark:bg-brand-sand/10">
                <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Cost') }} · {{ $planCost['plan'] }}</dt>
                <dd class="min-w-0 text-end">
                    <p class="text-lg font-semibold tracking-tight text-brand-ink">{{ $planCost['headline'] }}</p>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ $planCost['detail'] }}</p>
                </dd>
            </div>
        </dl>
        <div class="border-t border-brand-ink/8 px-5 py-2.5 dark:border-brand-mist/15">
            <x-docs-link doc-route="docs.markdown" doc-slug="edge-create" class="!border-0 !bg-transparent !px-0 !py-0 !shadow-none text-xs font-semibold text-brand-sage transition-colors hover:text-brand-forest dark:hover:text-brand-gold">
                {{ __('Docs') }}
                <x-heroicon-m-arrow-top-right-on-square class="h-4 w-4" aria-hidden="true" />
            </x-docs-link>
        </div>
    </div>

    <p class="px-1 text-xs leading-relaxed text-brand-moss">
        {{ __('Static and SSG sites, hybrid and Worker SSR, and Laravel, Rails or Node servers as containers. WordPress isn’t supported.') }}
    </p>
</aside>
