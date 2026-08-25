<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Edge'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
        ['label' => __('Templates'), 'icon' => 'sparkles'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Edge templates')"
        :description="__('Hand-picked starter repositories. Click Deploy — dply pre-fills the Create form with the template\'s repo, framework, and build settings so you go from zero to live in under a minute.')"
        icon="heroicon-o-sparkles"
    >
        <x-slot:actions>
            <x-outline-link href="{{ route('edge.index') }}" wire:navigate size="sm">
                <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Back to Edge sites') }}
            </x-outline-link>
            <x-outline-link href="{{ route('edge.import') }}" wire:navigate size="sm">
                <x-heroicon-o-arrow-down-tray class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Import instead') }}
            </x-outline-link>
        </x-slot:actions>

    @if ($tags !== [])
        <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
            <span class="text-2xs font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Filter') }}</span>
            <button type="button"
                    wire:click="setFilter('')"
                    @class([
                        'rounded-full px-3 py-1 text-xs font-semibold transition',
                        'bg-brand-ink text-brand-cream' => $filterTag === '',
                        'bg-brand-sand/30 text-brand-moss hover:bg-brand-sand/60' => $filterTag !== '',
                    ])>{{ __('All') }}</button>
            @foreach ($tags as $tag)
                <button type="button"
                        wire:click="setFilter('{{ $tag }}')"
                        @class([
                            'rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide transition',
                            'bg-brand-ink text-brand-cream' => $filterTag === $tag,
                            'bg-brand-sand/30 text-brand-moss hover:bg-brand-sand/60' => $filterTag !== $tag,
                        ])>{{ $tag }}</button>
            @endforeach
        </div>
    @endif

    @if ($templates === [])
        <p class="px-3 py-12 text-center text-sm text-brand-moss sm:px-4">{{ __('No templates match this filter yet.') }}</p>
    @else
        <div class="grid gap-4 px-3 py-4 sm:grid-cols-2 sm:px-4 lg:grid-cols-3">
            @foreach ($templates as $template)
                @php
                    $prefill = array_filter([
                        'name' => $template['slug'] ?? null,
                        'repo' => $template['clone_repo'] ?? $template['repo'] ?? null,
                        'branch' => $template['branch'] ?? 'main',
                        'framework' => $template['framework'] ?? null,
                        'runtime_mode' => $template['runtime_mode'] ?? null,
                        'template' => $template['slug'] ?? null,
                    ], fn ($v) => $v !== null && $v !== '');
                    $deployUrl = route('edge.create', $prefill);
                @endphp
                <article class="group flex flex-col rounded-2xl border border-brand-ink/10 bg-white shadow-sm transition hover:border-brand-sage hover:shadow-md dark:border-brand-mist/20 dark:bg-zinc-900">
                    @if (! empty($template['hero_url']))
                        <div class="aspect-[3/2] w-full overflow-hidden rounded-t-2xl border-b border-brand-ink/10 bg-brand-sand/30">
                            <img src="{{ asset(ltrim($template['hero_url'], '/')) }}" alt="{{ $template['name'] }}" class="h-full w-full object-cover" loading="lazy" />
                        </div>
                        <div class="px-5 pt-4">
                            <h2 class="text-sm font-semibold text-brand-ink">{{ $template['name'] }}</h2>
                            <p class="mt-0.5 font-mono text-2xs text-brand-mist truncate" title="{{ $template['repo'] }}">{{ $template['repo'] }}</p>
                        </div>
                    @else
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 px-5 py-4">
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-sage/30 to-brand-gold/20 text-base font-bold text-brand-ink">
                                {{ $template['hero_emoji'] ?? '·' }}
                            </span>
                            <div class="min-w-0">
                                <h2 class="text-sm font-semibold text-brand-ink">{{ $template['name'] }}</h2>
                                <p class="mt-0.5 font-mono text-2xs text-brand-mist truncate" title="{{ $template['repo'] }}">{{ $template['repo'] }}</p>
                            </div>
                        </div>
                    @endif
                    <p class="flex-1 px-5 py-4 text-xs leading-relaxed text-brand-moss">{{ $template['description'] }}</p>
                    @if (! empty($template['tags']))
                        <div class="flex flex-wrap gap-1.5 px-5 pb-3">
                            @foreach ($template['tags'] as $tag)
                                <span class="rounded-full bg-brand-sand/40 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif
                    <div class="flex items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/20 px-5 py-3">
                        <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $template['framework'] ?? 'static' }} · {{ $template['runtime_mode'] ?? 'static' }}</span>
                        <a href="{{ $deployUrl }}"
                           wire:navigate
                           class="inline-flex items-center gap-1 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90">
                            {{ __('Deploy') }} <span aria-hidden="true">→</span>
                        </a>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
    </x-profile-shell>
</div>
