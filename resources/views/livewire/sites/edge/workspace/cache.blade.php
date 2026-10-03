@php
    use App\Livewire\Sites\Edge\Workspace\Cache;
    use Illuminate\Support\Js;

    $modes = [
        'off' => [__('Off'), __('Every request reaches your app.')],
        'assets' => [__('Static assets'), __('Scripts, styles, images and fonts. Safe for any app.')],
        'standard' => [__('Follow cache headers'), __('Stores what your app marks public with Cache-Control.')],
        'everything' => [__('All public pages'), __('Every public GET that returns 200.')],
    ];
    // The edge keeps entries for at most a day; longer settings act as 1 day.
    $edgeLabel = __(Cache::EDGE_TTLS[min((int) $edgeTtl, 86400)] ?? '1 day');
    $notOnYet = ! $configured && (string) ($site->edgeMeta()['runtime_mode'] ?? 'static') !== 'static';
    $browserLabel = __(Cache::BROWSER_TTLS[(int) $browserTtl] ?? '1 day');
    $what = match ($mode) {
        'assets' => __('static assets (scripts, styles, images, fonts)'),
        'standard' => __('what your app marks public'),
        default => __('every public page'),
    };
    $isStatic = (string) ($site->edgeMeta()['runtime_mode'] ?? 'static') === 'static';
    $layers = array_filter([
        [__('Pages and responses, by the settings above'), __('Everywhere'), $mode !== 'off'],
        [__('Built files with hashed names, like /build/assets/app-3f2a9c.js. Kept for a year: a deploy gives changed files new names.'), __('Each location'), ! $isStatic],
        [__('Your deployed files, after the first visit in each location. A deploy switches to the new files at once.'), __('Each location'), $isStatic],
        [__('Which deploy, domains and settings to use. Each location checks again every 60 seconds.'), __('Each location'), true],
        [(int) $browserTtl === 0 ? __('Browsers ask for a fresh copy on every visit.') : __('Browsers keep their own copy for :t and can’t be purged.', ['t' => $browserLabel]), __('Each visitor'), true],
    ], fn ($layer) => $layer[2]);
    $located = collect($locations ?? []);
    $locTotal = (int) $located->sum('requests');
    $locEdge = (int) $located->sum(fn ($l) => $l['cached'] + $l['files']);
    $pct = fn (int $part, int $whole) => $whole > 0 ? (int) round($part * 100 / $whole) : 0;
    $canEdit = auth()->user()?->can('update', $site) ?? false;
    $canPurge = auth()->user()?->can('deploy', $site) ?? false;
    $row = 'flex min-h-12 w-full items-center gap-3 border-b border-brand-ink/10 py-3 text-left hover:bg-brand-sand/20 disabled:cursor-default disabled:hover:bg-transparent';
    $state = 'shrink-0 font-mono text-xs text-brand-moss';
    $close = fn (string $action) => '<button type="button" '.$action.' class="dply-icon-btn h-9 w-9" aria-label="'.e(__('Close')).'">'.svg('heroicon-o-x-mark', 'h-5 w-5', ['aria-hidden' => 'true'])->toHtml().'</button>';
@endphp

<div wire:init="loadPage">
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'what' => __('The edge cache stores public responses so the next visit does not wait on the app. Hashed files such as JavaScript, CSS, and images are kept. HTML that sets a session cookie is not.'),
            'steps' => [
                __('Read the sentence, then click a row to change that setting.'),
                __('A public GET that returns 200 with a cache lifetime is stored; the next matching request is served from that copy.'),
                __('Purge a path or a cache tag when you need the following request to fetch a fresh copy.'),
            ],
        ])
    </section>

    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Cache') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if ($notOnYet)
                    <span class="text-amber-600 dark:text-amber-300">{{ __('The edge cache isn’t on yet, so every request reaches your app.') }}</span>
                    {{ __('Open a setting below and save it to turn it on.') }}
                @elseif ($mode === 'off')
                    {{ __('The edge doesn’t store anything, so every request reaches your app.') }}
                @elseif ($mode === 'standard')
                    {{ __('The edge keeps') }} <span class="text-brand-sage">{{ $what }}</span>{{ __(', for as long as your app says.') }}
                @else
                    {{ __('The edge keeps') }} <span class="text-brand-sage">{{ $what }}</span> {{ __('for') }} <span class="text-brand-sage">{{ $edgeLabel }}</span>,
                    {{ (int) $browserTtl === 0 ? __('and browsers check back on every visit.') : __('and browsers keep them for :t.', ['t' => $browserLabel]) }}
                @endif
                @if ($entries === null)
                    <span class="inline-block h-6 w-48 translate-y-1 rounded-md bg-brand-ink/10 align-baseline motion-safe:animate-pulse" aria-label="{{ __('Loading stored copies…') }}"></span>
                @elseif ($listMessage === '')
                    @if ($entries === [])
                        {{ __('Nothing is stored right now.') }}
                    @else
                        <span class="text-brand-sage">{{ trans_choice(':count copy is|:count copies are', count($entries), ['count' => count($entries)]) }}</span> {{ __('stored right now.') }}
                    @endif
                @endif
            </p>
        </div>

        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('How it’s set') }}</p>
            @foreach ([
                'mode' => [$mode === 'off' ? __('Nothing is stored') : ($mode === 'assets' ? __('Only static assets are stored') : ($mode === 'standard' ? __('Responses your app marks public are stored') : __('Every public page is stored'))), $modes[$mode][0]],
                'edge' => [__('The edge keeps a copy for :t', ['t' => $edgeLabel]), $edgeLabel],
                'browser' => [(int) $browserTtl === 0 ? __('Browsers check for a fresh copy on every visit') : __('Browsers keep their copy for :t', ['t' => $browserLabel]), $browserLabel],
                'query' => [$queryString === 'ignore' ? __('/page?a=1 and /page?a=2 share one copy') : __('/page?a=1 and /page?a=2 are stored separately'), $queryString === 'ignore' ? __('Ignored') : __('In the key')],
            ] as $setting => [$sentence, $value])
                <button type="button" wire:click="editSetting('{{ $setting }}')" class="{{ $row }}" @disabled(! $canEdit)>
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $sentence }}</span>
                    <span class="{{ $state }}">{{ $value }}</span>
                    @if ($canEdit)
                        <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                    @endif
                </button>
            @endforeach
            <p class="pt-3 text-xs text-brand-moss">{{ __('Pages that set a session cookie are never stored.') }}</p>
        </div>

        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Stored copies') }}</p>
            @if ($listMessage !== '')
                <div class="border-b border-brand-ink/10 py-3"><x-sheet.note>{{ $listMessage }}</x-sheet.note></div>
            @else
                <button type="button" x-on:click="$dispatch('open-modal', 'cache-stored')" class="{{ $row }}" @disabled($entries === null)>
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">
                        @if ($entries === null)
                            <span class="inline-block h-4 w-44 rounded bg-brand-ink/10 align-middle motion-safe:animate-pulse"></span>
                        @elseif ($entries === [])
                            {{ __('Nothing stored yet') }}
                        @else
                            {{ trans_choice('See the stored copy|See the :count stored copies', count($entries), ['count' => count($entries)]) }}
                        @endif
                    </span>
                    <span class="{{ $state }}">{{ $entries === null ? '' : count($entries) }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @endif
            @if ($canPurge)
                <button type="button" x-on:click="$dispatch('open-modal', 'cache-purge')" class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ __('Purge one path or a cache tag') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    wire:click="openConfirmActionModal('clearAll', [], {{ Js::from(__('Clear all cache')) }}, {{ Js::from(__('Drop every stored copy for this app. The next visit fetches a fresh response.')) }}, {{ Js::from(__('Clear all')) }}, true)"
                    class="{{ $row }}"
                >
                    <span class="flex-1 text-sm text-rose-600 sm:text-base dark:text-rose-300">{{ __('Clear everything the edge stored') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @endif
        </div>
        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Everything the edge keeps') }}</p>
            @foreach ($layers as [$sentence, $scope])
                <div class="flex min-h-12 items-center gap-3 border-b border-brand-ink/10 py-3">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $sentence }}</span>
                    <span class="{{ $state }}">{{ $scope }}</span>
                </div>
            @endforeach
            <p class="pt-3 text-xs text-brand-moss">{{ __('Each Cloudflare location keeps its own copy, so the first visit in a city fetches it once.') }}</p>
        </div>
        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Where visitors were answered, last 24 hours') }}</p>
            @if (! $locationsLoaded)
                <div class="border-b border-brand-ink/10 py-3"><span class="inline-block h-4 w-64 rounded bg-brand-ink/10 align-middle motion-safe:animate-pulse"></span></div>
            @elseif ($locations === null)
                <div class="border-b border-brand-ink/10 py-3"><x-sheet.note>{{ __('Traffic by location isn’t available right now.') }}</x-sheet.note></div>
            @elseif ($locations === [])
                <p class="border-b border-brand-ink/10 py-3 text-sm text-brand-moss">{{ __('No visits recorded in the last 24 hours.') }}</p>
            @else
                <p class="border-b border-brand-ink/10 py-3 text-sm text-brand-ink">
                    {{ trans_choice(':pct% of :count request was answered at the edge|:pct% of :count requests were answered at the edge', $locTotal, ['pct' => $pct($locEdge, $locTotal), 'count' => number_format($locTotal)]) }},
                    {{ trans_choice('from :n location.|from :n locations.', count($locations), ['n' => count($locations)]) }}
                </p>
                <ul class="divide-y divide-brand-ink/10 border-b border-brand-ink/10">
                    @foreach ($locations as $loc)
                        <li class="grid gap-1.5 py-3 sm:grid-cols-[12rem_1fr_7rem] sm:items-center sm:gap-4" wire:key="cache-loc-{{ $loc['colo'] }}">
                            <span class="truncate text-sm text-brand-ink">{{ $loc['city'] ?? $loc['colo'] }} <span class="font-mono text-xs text-brand-mist">{{ $loc['colo'] }}</span></span>
                            <span class="flex h-2 overflow-hidden rounded-full bg-brand-ink/10" title="{{ __('Cached copy :a% · Deployed files :b% · Your app :c%', ['a' => $pct($loc['cached'], $loc['requests']), 'b' => $pct($loc['files'], $loc['requests']), 'c' => $pct($loc['app'], $loc['requests'])]) }}">
                                <span class="bg-brand-sage" style="width: {{ $pct($loc['cached'], $loc['requests']) }}%"></span>
                                <span class="bg-sky-400" style="width: {{ $pct($loc['files'], $loc['requests']) }}%"></span>
                                <span class="bg-amber-400" style="width: {{ $pct($loc['app'], $loc['requests']) }}%"></span>
                            </span>
                            <span class="font-mono text-xs text-brand-moss sm:text-right">{{ trans_choice(':n request|:n requests', $loc['requests'], ['n' => number_format($loc['requests'])]) }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="flex flex-wrap gap-x-4 gap-y-1 pt-3 text-xs text-brand-moss">
                    <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-brand-sage"></span>{{ __('Cached copy') }}</span>
                    <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-sky-400"></span>{{ __('Deployed files') }}</span>
                    <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-amber-400"></span>{{ __('Your app') }}</span>
                    <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-brand-ink/10"></span>{{ __('Redirects, 404s and other') }}</span>
                </p>
            @endif
        </div>
    </section>

    <x-modal name="cache-setting" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
        <form wire:submit="saveOptions" class="space-y-5 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">
                        {{ match ($editing) { 'edge' => __('How long the edge keeps a copy'), 'browser' => __('How long browsers keep a copy'), 'query' => __('Query strings'), default => __('What the edge keeps') } }}
                    </h2>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Applies on the next request after you save.') }}</p>
                </div>
                {!! $close('wire:click="closeSetting"') !!}
            </div>

            @if ($editing === 'edge' || $editing === 'browser')
                @php $options = $editing === 'edge' ? Cache::EDGE_TTLS : Cache::BROWSER_TTLS; $model = $editing === 'edge' ? 'edgeTtl' : 'browserTtl'; @endphp
                <x-sheet.field :label="$editing === 'edge' ? __('Keep for') : __('Browsers keep for')" for="cache-ttl">
                    <select id="cache-ttl" wire:model="{{ $model }}" class="dply-input mt-0">
                        @foreach ($options as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </select>
                </x-sheet.field>
                <p class="text-xs text-brand-moss">
                    {{ $editing === 'edge'
                        ? __('Used when your response doesn’t set its own lifetime. The edge keeps copies for at most 1 day, so longer values act as 1 day. A purge drops a copy early.')
                        : __('Browsers can’t be purged, so keep this short for anything that changes without a new file name.') }}
                </p>
            @elseif ($editing === 'query')
                <div class="grid gap-2">
                    @foreach (['ignore' => [__('Ignore them'), __('/page?a=1 and /page?a=2 share one copy. Best for tracking parameters like utm_source.')], 'include' => [__('Include them in the key'), __('Each query string gets its own copy. Use when the query changes the page.')]] as $value => [$title, $help])
                        <label @class(['flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3', 'border-brand-sage' => $queryString === $value, 'border-brand-ink/10' => $queryString !== $value])>
                            <input type="radio" wire:model.live="queryString" value="{{ $value }}" class="mt-1 accent-brand-sage">
                            <span><span class="block text-sm font-semibold text-brand-ink">{{ $title }}</span><span class="block text-xs text-brand-moss">{{ $help }}</span></span>
                        </label>
                    @endforeach
                </div>
            @else
                <div class="grid gap-2">
                    @foreach ($modes as $value => [$title, $help])
                        <label @class(['flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3', 'border-brand-sage' => $mode === $value, 'border-brand-ink/10' => $mode !== $value])>
                            <input type="radio" wire:model.live="mode" value="{{ $value }}" class="mt-1 accent-brand-sage">
                            <span><span class="block text-sm font-semibold text-brand-ink">{{ $title }}</span><span class="block text-xs text-brand-moss">{{ $help }}</span></span>
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-brand-moss">{{ __('Pages that set a session cookie are never stored.') }}</p>
            @endif

            <div class="flex justify-end gap-2">
                <x-sheet.button type="button" wire:click="closeSetting">{{ __('Cancel') }}</x-sheet.button>
                <x-sheet.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveOptions">
                    <span wire:loading.remove wire:target="saveOptions">{{ __('Save') }}</span>
                    <span wire:loading wire:target="saveOptions">{{ __('Saving…') }}</span>
                </x-sheet.button>
            </div>
        </form>
    </x-modal>

    <x-modal name="cache-stored" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        <div class="space-y-4 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Stored copies') }}</h2>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('What the edge serves without asking your app.') }}</p>
                </div>
                {!! $close("x-on:click=\"\$dispatch('close-modal', 'cache-stored')\"") !!}
            </div>
            @if (($entries ?? []) === [])
                <p class="text-sm text-brand-moss">{{ __('Nothing stored yet. Cached responses show up here after visitors request them.') }}</p>
            @else
                <ul class="max-h-96 divide-y divide-brand-ink/10 overflow-y-auto border-y border-brand-ink/10">
                    @foreach ($entries as $entry)
                        <li class="flex items-center gap-3 py-2.5" wire:key="cache-entry-{{ md5($entry['path']) }}">
                            <span class="min-w-0 flex-1 truncate font-mono text-xs text-brand-ink" title="{{ $entry['path'] }}">{{ $entry['path'] }}</span>
                            <span class="shrink-0 text-xs text-brand-moss">
                                {{ $entry['expires_at'] ? __('Expires :time', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($entry['expires_at'])->diffForHumans()]) : __('No expiry recorded') }}
                            </span>
                            @if ($canPurge)
                                <x-sheet.button wire:click="purgeStored({{ Js::from($entry['path']) }})" wire:loading.attr="disabled" class="shrink-0">{{ __('Purge') }}</x-sheet.button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </x-modal>

    @if ($canPurge)
        <x-modal name="cache-purge" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
            <div class="space-y-5 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Purge') }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('The next request fetches a fresh copy from your app.') }}</p>
                    </div>
                    {!! $close("x-on:click=\"\$dispatch('close-modal', 'cache-purge')\"") !!}
                </div>
                @foreach ([['purgeByPath', 'purgePath', __('One path'), __('Drop the stored copy for one URL path.'), '/build/assets/app.js'], ['purgeByTag', 'purgeTag', __('A cache tag'), __('Send Cache-Tag: assets on the response, then purge that name. This drops the latest copy stored under the tag.'), 'article-42']] as [$action, $model, $title, $help, $placeholder])
                    <form wire:submit="{{ $action }}" class="grid gap-2">
                        <x-sheet.field :label="$title" :help="$help" for="cache-{{ $model }}">
                            <div class="flex gap-2">
                                <input id="cache-{{ $model }}" type="text" wire:model="{{ $model }}" autocomplete="off" spellcheck="false" placeholder="{{ $placeholder }}" class="dply-input mt-0 min-w-0 flex-1 font-mono text-xs" />
                                <x-sheet.button type="submit" wire:loading.attr="disabled" wire:target="{{ $action }}" class="shrink-0">
                                    <span wire:loading.remove wire:target="{{ $action }}">{{ __('Purge') }}</span>
                                    <span wire:loading wire:target="{{ $action }}">{{ __('Purging…') }}</span>
                                </x-sheet.button>
                            </div>
                        </x-sheet.field>
                        @error($model) <x-sheet.note tone="danger">{{ $message }}</x-sheet.note> @enderror
                    </form>
                @endforeach
            </div>
        </x-modal>
    @endif

    @include('livewire.partials.confirm-action-modal')
</div>
