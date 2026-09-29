{{--
  Redirects / Rewrites / Headers tab: a sentence, then one row per rule.
  Dashboard rows open the rule dialog (routing.blade.php); rows from the repo
  file are read-only. @var string $kind redirects|rewrites|headers
--}}
@php
    $mine = $this->{'dashboard_'.$kind};
    $repo = match ($kind) { 'rewrites' => $repoRewrites, 'headers' => $repoHeaders, default => $repoRedirects };
    $total = count($mine) + count($repo);
    $canEdit = auth()->user()?->can('update', $site) ?? false;
    $row = 'flex min-h-12 w-full items-center gap-3 border-b border-brand-ink/10 py-3 text-left hover:bg-brand-sand/20';
    $permanent = fn ($s) => in_array((int) $s, [301, 308], true);
    $say = fn (array $r): string => match ($kind) {
        'rewrites' => __(':from is served from :to', ['from' => $r['from'], 'to' => $r['to']]),
        'headers' => __(':for gets :names', ['for' => $r['for'], 'names' => implode(', ', array_keys((array) ($r['values'] ?? [])))]),
        default => $permanent($r['status'] ?? 301)
            ? __(':from moves permanently to :to', ['from' => $r['from'], 'to' => $r['to']])
            : __(':from moves temporarily to :to', ['from' => $r['from'], 'to' => $r['to']]),
    };
    $state = fn (array $r): string => match ($kind) {
        'headers' => trans_choice(':count header|:count headers', count((array) ($r['values'] ?? []))),
        'rewrites' => __('Rewrite'),
        default => (string) ($r['status'] ?? 301),
    };
    $label = ['redirects' => __('Redirects'), 'rewrites' => __('Rewrites'), 'headers' => __('Headers')][$kind];
    $addLabel = ['redirects' => __('Add a redirect'), 'rewrites' => __('Add a rewrite'), 'headers' => __('Add a header rule')][$kind];
    $templateKeys = ['redirects' => ['blog-migration'], 'rewrites' => ['api-proxy'], 'headers' => ['security-headers', 'cache-assets']][$kind];
@endphp

<section class="space-y-8 px-5 py-8 sm:px-10 sm:py-10">
    <div>
        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $label }}</p>
        <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
            @if ($total === 0)
                {{ match ($kind) {
                    'rewrites' => __('No rewrites. Every path is served by your app as it is.'),
                    'headers' => __('No header rules. Responses go out with the headers your app sets.'),
                    default => __('No redirects. Every path serves what your app returns.'),
                } }}
            @else
                @if ($kind === 'redirects')
                    @php $perm = collect([...$mine, ...$repo])->filter(fn ($r) => $permanent($r['status'] ?? 301))->count(); @endphp
                    <span class="text-brand-sage">{{ trans_choice(':count redirect|:count redirects', $total) }}</span>
                    {{ $perm === $total ? __('send visitors to a new address for good.') : ($perm === 0 ? __('send visitors somewhere else for now.') : trans_choice('send visitors elsewhere; :count is permanent.|send visitors elsewhere; :count are permanent.', $perm)) }}
                @elseif ($kind === 'rewrites')
                    <span class="text-brand-sage">{{ trans_choice(':count rewrite|:count rewrites', $total) }}</span>
                    {{ __('serve paths from somewhere else without changing the address bar.') }}
                @else
                    <span class="text-brand-sage">{{ trans_choice(':count rule|:count rules', $total) }}</span>
                    {{ __('add headers to responses.') }}
                @endif
                @if ($repo !== [])
                    {{ trans_choice(':count comes from :file.|:count come from :file.', count($repo), ['file' => $sourcePath]) }}
                @endif
            @endif
        </p>
    </div>

    <div>
        <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ $label }}</p>
        @foreach ($repo as $i => $rule)
            <div class="flex min-h-12 items-center gap-3 border-b border-brand-ink/10 py-3" wire:key="repo-{{ $kind }}-{{ $i }}">
                <span class="min-w-0 flex-1 break-all font-mono text-sm text-brand-ink">{{ $say($rule) }}</span>
                <span class="shrink-0 text-xs text-brand-moss">{{ $sourcePath }}</span>
            </div>
        @endforeach
        @foreach ($mine as $i => $rule)
            <button type="button" wire:click="openRule('{{ $kind }}', {{ $i }})" class="{{ $row }}" wire:key="mine-{{ $kind }}-{{ $i }}" @disabled(! $canEdit)>
                <span class="min-w-0 flex-1 break-all font-mono text-sm text-brand-ink">{{ $say($rule) }}</span>
                <span class="shrink-0 font-mono text-xs text-brand-moss">{{ $state($rule) }}</span>
                @if ($canEdit)
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                @endif
            </button>
        @endforeach
        @if ($canEdit)
            <button type="button" wire:click="openRule('{{ $kind }}')" class="{{ $row }} font-medium text-brand-sage">
                <x-heroicon-m-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                <span class="flex-1 text-sm sm:text-base">{{ $addLabel }}</span>
            </button>
            @if ($kind === 'redirects')
                <button type="button" x-on:click="$dispatch('open-modal', 'redirect-import')" class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ __('Import many at once from a CSV or _redirects file') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @endif
            @foreach (collect($templates)->only($templateKeys) as $key => $template)
                <button type="button" wire:click="applyTemplate('{{ $key }}')" wire:confirm="{{ $template['hint'] }}" class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ __('Start from “:name”', ['name' => $template['label']]) }}</span>
                    <span class="shrink-0 text-xs text-brand-moss">{{ __('Template') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @endforeach
        @endif
    </div>

    <details class="text-sm">
        <summary class="cursor-pointer text-xs font-semibold text-brand-moss">{{ __('Keep these in :file', ['file' => $sourcePath]) }}</summary>
        <div class="mt-3">
            <x-edge-yaml-example :file="$sourcePath">{{ match ($kind) {
                'rewrites' => "rewrites:\n  - from: /api/*\n    to: https://api.example.com/:splat",
                'headers' => "headers:\n  - for: /assets/*\n    values:\n      Cache-Control: \"public, max-age=31536000, immutable\"",
                default => "redirects:\n  - from: /old-page\n    to: /new-page\n    status: 301\n  - from: /blog/*\n    to: /news/:splat\n    status: 301",
            } }}</x-edge-yaml-example>
        </div>
    </details>
</section>
