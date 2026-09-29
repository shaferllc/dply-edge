@php
    $hasRepoErrors = $repoErrors !== [] || $repoMaint !== [];
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'error-pages',
            'what' => __('Error pages and maintenance let you brand 404/500 responses and take the site offline with a 503 — all at the Edge, without touching your repo.'),
            'steps' => [
                __('Click a page to paste your own HTML or start from a template. Blank keeps the built-in page.'),
                __('Turn on Maintenance mode when you need a hard stop; visitors get a 503 until you turn it off.'),
                __('Changes apply on the next request — no rebuild required.'),
            ],
            'tips' => [
                __('Keep error HTML self-contained (inline CSS). External assets may fail if the site is broken.'),
                __('Repo dply.yaml can declare error pages too; dashboard values override for operators.'),
            ],
        ])
    </section>

    @php
        $inRepo = [
            'html_404' => ! empty($repoErrors['html_404']) || ! empty($repoErrors['html_404_path']),
            'html_500' => ! empty($repoErrors['html_500']) || ! empty($repoErrors['html_500_path']),
            'html_403' => false, // dashboard-only; dply.yaml has no key for it
            'maintenance' => ! empty($repoMaint['html']) || ! empty($repoMaint['html_path']),
        ];
        $source = fn (string $k): string => $saved[$k] ? 'custom' : ($inRepo[$k] ? 'repo' : 'builtin');
        $whose = fn (string $k): string => match ($source($k)) {
            'custom' => __('your own'),
            'repo' => __('your :file', ['file' => $sourcePath]),
            default => __('the built-in'),
        };
        $stateLabel = fn (string $k): string => match ($source($k)) {
            'custom' => __('Custom'),
            'repo' => __('From :file', ['file' => $sourcePath]),
            default => __('Built-in'),
        };
        $pages = [
            'html_404' => ['title' => __('404 page'), 'sentence' => __('A missing page shows :whose 404 page', ['whose' => $whose('html_404')]), 'model' => 'error_404_html', 'status' => '404 Not Found'],
            'html_500' => ['title' => __('500 page'), 'sentence' => __('An unexpected error shows :whose 500 page', ['whose' => $whose('html_500')]), 'model' => 'error_500_html', 'status' => '500 Internal Server Error'],
            'html_403' => ['title' => __('Blocked-country page'), 'sentence' => __('A visitor your Firewall blocks by country sees :whose 403 page', ['whose' => $whose('html_403')]), 'model' => 'error_403_html', 'status' => '403 Forbidden'],
            'maintenance' => ['title' => __('Maintenance page'), 'sentence' => __('Maintenance mode shows :whose maintenance page', ['whose' => $whose('maintenance')]), 'model' => 'maintenance_html', 'status' => '503 Service Unavailable'],
        ];
        $editing = $editingPage !== null ? ($pages[$editingPage] ?? null) : null;
        $canEdit = auth()->user()?->can('update', $site);
    @endphp

    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Error pages') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                {{ __('Visitors see') }}
                <span @class(['text-brand-sage' => $source('html_404') !== 'builtin'])>{{ __(':whose 404 page', ['whose' => $whose('html_404')]) }}</span>
                {{ __('and') }}
                <span @class(['text-brand-sage' => $source('html_500') !== 'builtin'])>{{ __(':whose 500 page', ['whose' => $whose('html_500')]) }}</span>.
                @if ($maintenance_enabled)
                    <span class="text-amber-600 dark:text-amber-300">{{ __('Maintenance is on — every visitor gets a 503.') }}</span>
                @else
                    {{ __('The site is live — maintenance is off.') }}
                @endif
            </p>
        </div>

        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('When something goes wrong') }}</p>
            <ul>
                @foreach ($pages as $key => $page)
                    <li class="border-b border-brand-ink/10" wire:key="error-page-{{ $key }}">
                        <button type="button" wire:click="editPage('{{ $key }}')" class="flex min-h-12 w-full items-center gap-3 py-3 text-left hover:bg-brand-sand/20">
                            <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $page['sentence'] }}</span>
                            <span class="text-xs text-brand-moss">{{ $stateLabel($key) }}</span>
                            <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                        </button>
                    </li>
                @endforeach
            </ul>
            <label class="flex min-h-12 cursor-pointer items-center gap-3 border-b border-brand-ink/10 py-3">
                <span class="flex-1">
                    <span class="block text-sm text-brand-ink sm:text-base">{{ __('Maintenance mode') }}</span>
                    <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Every request gets a 503 and your maintenance page. Takes effect right away.') }}</span>
                </span>
                <input type="checkbox" wire:model.live="maintenance_enabled" @disabled(! $canEdit) class="h-4 w-4 rounded border-brand-ink/30 text-amber-600 focus:ring-amber-500" />
            </label>
        </div>
    </section>

    <x-modal name="edge-error-page" maxWidth="5xl" overlayClass="bg-brand-ink/40" focusable>
        @if ($editing)
            <div class="space-y-5 p-6 sm:p-7" wire:key="error-edit-{{ $editingPage }}">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ $editing['title'] }}</h2>
                        <p class="mt-0.5 font-mono text-xs text-brand-mist">{{ $editing['status'] }}</p>
                    </div>
                    <button type="button" wire:click="closePage" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                @if ($canEdit)
                    <div class="flex flex-wrap items-center gap-2 text-xs text-brand-moss">
                        {{ __('Start from') }}
                        @foreach ($templates as $tkey => $template)
                            <button type="button" wire:click="applyTemplate('{{ $editingPage }}', '{{ $tkey }}')" title="{{ $template['hint'] }}" class="inline-flex min-h-8 items-center rounded-full border border-brand-ink/15 px-3 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ $template['label'] }}</button>
                        @endforeach
                    </div>
                @endif

                @php $html = (string) $this->{$editing['model']}; @endphp
                <div class="grid gap-4 lg:grid-cols-2">
                    <div>
                        <label for="error-page-html" class="sr-only">{{ __('HTML') }}</label>
                        <textarea
                            id="error-page-html"
                            wire:model.live.debounce.500ms="{{ $editing['model'] }}"
                            rows="16"
                            spellcheck="false"
                            @disabled(! $canEdit)
                            placeholder="{{ __('Blank = the built-in page') }}"
                            class="block h-80 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs leading-relaxed text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900"
                        ></textarea>
                        @error($editing['model']) <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Keep CSS inline — the site may be half-broken when this page shows.') }}</p>
                    </div>
                    <div class="h-80 overflow-hidden rounded-lg border border-brand-ink/10 bg-white">
                        @if (trim($html) !== '')
                            {{-- sandbox with no allowances: no scripts, no navigation, no same-origin access. --}}
                            <iframe sandbox="" srcdoc="{{ $html }}" title="{{ __('Preview') }}" class="h-full w-full"></iframe>
                        @else
                            <div class="flex h-full items-center justify-center px-6 text-center text-sm text-brand-mist">{{ __('No custom HTML — visitors get the built-in page.') }}</div>
                        @endif
                    </div>
                </div>

                <div class="flex items-center justify-between gap-2">
                    @if ($canEdit && trim($html) !== '')
                        <button type="button" wire:click="useBuiltInPage" class="text-sm font-semibold text-red-600 hover:underline">{{ __('Use the built-in page') }}</button>
                    @else
                        <span></span>
                    @endif
                    <span class="flex gap-2">
                        <button type="button" wire:click="closePage" class="rounded-lg border border-brand-ink/15 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                        @if ($canEdit)
                            <x-primary-button type="button" wire:click="savePage" wire:loading.attr="disabled" wire:target="savePage">{{ __('Save') }}</x-primary-button>
                        @endif
                    </span>
                </div>
            </div>
        @endif
    </x-modal>

    <details class="group" @if ($hasRepoErrors) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
            <span class="inline-flex items-center gap-2">
                {{ __('Advanced') }}
                @if ($hasRepoErrors)
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                        {{ __('Repo') }}
                    </span>
                @endif
            </span>
            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
        </summary>

        <div class="space-y-5 border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</p>
                    <a
                        href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                        class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline"
                    >
                        <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Generate :file', ['file' => $sourcePath]) }}
                    </a>
                </div>
                @if ($hasRepoErrors)
                    <dl class="mt-2 grid grid-cols-1 gap-y-1.5 text-xs sm:grid-cols-[8rem_1fr]">
                        @if (! empty($repoErrors['html_404']))
                            <dt class="text-brand-mist">{{ __('404') }}</dt>
                            <dd class="text-brand-moss">{{ __('Set in repo') }}</dd>
                        @endif
                        @if (! empty($repoErrors['html_500']))
                            <dt class="text-brand-mist">{{ __('500') }}</dt>
                            <dd class="text-brand-moss">{{ __('Set in repo') }}</dd>
                        @endif
                        @if (! empty($repoMaint['enabled']))
                            <dt class="text-brand-mist">{{ __('Maintenance') }}</dt>
                            <dd class="text-brand-moss">{{ __('Enabled in repo') }}</dd>
                        @endif
                        @if (! empty($repoMaint['html']) || ! empty($repoMaint['html_path']))
                            <dt class="text-brand-mist">{{ __('Maintenance HTML') }}</dt>
                            <dd class="text-brand-moss">{{ __('Set in repo') }}</dd>
                        @endif
                    </dl>
                    <p class="mt-2 text-xs text-brand-mist">{{ __('Dashboard values override the repo when both are set.') }}</p>
                @else
                    <p class="mt-2 text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $sourcePath]) }}</p>
                @endif

                <x-edge-yaml-example :file="$sourcePath" :hint="__('Inline HTML or a path relative to the repo root. Dashboard values override when both are set.')">
error_pages:
  # Short inline HTML, or point at a file in the repo:
  html_404: |
    <!doctype html><html lang="en"><head><meta charset="utf-8"><title>404</title>
    <style>body{font-family:system-ui;display:grid;place-items:center;min-height:100vh;margin:0}</style>
    </head><body><main><h1>Page not found</h1><p>That link may be broken.</p></main></body></html>
  html_500_path: "public/500.html"

maintenance:
  enabled: false
  html_path: "public/maintenance.html"
                </x-edge-yaml-example>
            </div>
        </div>
    </details>
</div>
