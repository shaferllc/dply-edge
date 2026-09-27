@php
    $hasRepoErrors = $repoErrors !== [] || $repoMaint !== [];
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'error-pages',
            'what' => __('Error pages and maintenance let you brand 404/500 responses and take the site offline with a 503 — all at the Edge, without touching your repo.'),
            'steps' => [
                __('Paste full HTML for 404 and/or 500, or leave blank to keep the built-in defaults.'),
                __('Turn on Maintenance mode when you need a hard stop; visitors get 503 until you turn it off and Save.'),
                __('Save to republish delivery. Changes apply on the next request — no rebuild required.'),
            ],
            'tips' => [
                __('Keep error HTML self-contained (inline CSS). External assets may fail if the site is broken.'),
                __('Repo dply.yaml can declare error pages too; dashboard values override for operators.'),
            ],
        ])
    </section>

    {{-- Starters — same pattern as Tags / Snippets examples. --}}
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-3 dark:bg-brand-sand/10 sm:px-4">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Examples') }}</p>
            <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Fill 404, 500, and maintenance at once — then edit before Save. Self-contained HTML with inline CSS.') }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($templates as $key => $template)
                    <button
                        type="button"
                        wire:click="applyAllTemplates('{{ $key }}')"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:border-brand-sage/40 hover:bg-brand-sage/5 dark:bg-zinc-900"
                        title="{{ $template['hint'] }}"
                    >
                        {{ $template['label'] }}
                        <span class="font-normal text-brand-mist">+</span>
                    </button>
                @endforeach
            </div>
            <div class="mt-3 flex flex-wrap gap-2 border-t border-brand-ink/10 pt-3">
                <span class="w-full text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Per page') }}</span>
                @foreach ($templates as $key => $template)
                    <button type="button" wire:click="applyTemplate('html_404', '{{ $key }}')" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 dark:bg-zinc-900" title="{{ $template['hint'] }}">{{ __('404') }} · {{ $template['label'] }}</button>
                    <button type="button" wire:click="applyTemplate('html_500', '{{ $key }}')" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 dark:bg-zinc-900">{{ __('500') }} · {{ $template['label'] }}</button>
                    <button type="button" wire:click="applyTemplate('maintenance', '{{ $key }}')" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 dark:bg-zinc-900">{{ __('Maint') }} · {{ $template['label'] }}</button>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Maintenance — primary ops control (live host-map republish). --}}
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <label class="flex items-start gap-3">
            <input
                type="checkbox"
                wire:model.live="maintenance_enabled"
                class="mt-1 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest"
            />
            <span class="min-w-0">
                <span class="block text-sm font-semibold text-brand-ink">{{ __('Maintenance mode') }}</span>
                <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Visitors get a 503 until you turn this off. Takes effect immediately after Save.') }}</span>
            </span>
        </label>
    </section>

    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <label class="block" for="error-404">
            <span class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('404 page') }}</span>
            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Shown when a path isn’t found. Blank = built-in default.') }}</span>
        </label>
        <textarea
            id="error-404"
            wire:model="error_404_html"
            rows="5"
            spellcheck="false"
            class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900"
            placeholder="<!doctype html>…"
        ></textarea>
        @error('error_404_html') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
    </section>

    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <label class="block" for="error-500">
            <span class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('500 page') }}</span>
            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Unexpected errors. Blank = built-in default.') }}</span>
        </label>
        <textarea
            id="error-500"
            wire:model="error_500_html"
            rows="5"
            spellcheck="false"
            class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900"
            placeholder="<!doctype html>…"
        ></textarea>
        @error('error_500_html') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
    </section>

    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <label class="block" for="maintenance-html">
            <span class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Maintenance page') }}</span>
            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Optional HTML when maintenance mode is on.') }}</span>
        </label>
        <textarea
            id="maintenance-html"
            wire:model="maintenance_html"
            rows="5"
            spellcheck="false"
            class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900"
            placeholder="<!doctype html>…"
        ></textarea>
        @error('maintenance_html') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
    </section>

    <div class="flex items-center justify-end gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-5 py-3 sm:px-6">
        <span wire:loading.inline-flex wire:target="save" class="inline-flex items-center gap-1.5 text-xs text-brand-moss">
            <x-spinner size="sm" variant="muted" />
            {{ __('Saving…') }}
        </span>
        @can('update', $site)
            <button
                type="button"
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
                class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
            >
                {{ __('Save') }}
            </button>
        @endcan
    </div>

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
