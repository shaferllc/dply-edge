@php
    $vectorsConnection = collect($connections)->first(fn ($c) => $c['host'] === $resourceHost && $c['kind'] === 'vectors');
    $vectorsName = is_array($vectorsConnection) ? $vectorsConnection['name'] : 'SEARCH';
    $vectorsHostName = is_array($vectorsConnection) ? $vectorsConnection['host'] : '';
    $vectorsLabel = $vectorsHostName !== '' ? \App\Modules\Edge\Support\EdgeContainerConnections::resourceLabel($vectorsHostName) : '';
    $vectorsDims = (int) ($vectorsInfo['dimensions'] ?? 0) ?: 768;
    $vectorsModel = \App\Livewire\Sites\Edge\Workspace\Resources::VECTORS_DEMO_MODELS[$vectorsDims] ?? null;
    $vectorsAddress = $isWorker ? 'env.'.$vectorsName : 'http://'.$vectorsHostName;
    $vectorsCode = 'block whitespace-pre-wrap break-all rounded-xl bg-brand-sand/40 p-3.5 pe-16 font-mono text-xs leading-5 text-brand-ink dark:bg-zinc-950';
    $vectorsCopy = 'absolute end-2 top-2 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-2xs font-semibold text-brand-ink hover:border-brand-ink/40 dark:border-brand-mist/25 dark:bg-zinc-900';
    // Code for the call builder, per app type.
    $vectorsSnippets = $isWorker ? [
        'search' => [
            'JavaScript' => "const { matches } = await env.{$vectorsName}.query(embedding, {\n  topK: 5,\n  returnMetadata: 'all',\n});",
            'With Workers AI' => "const { data } = await env.AI.run('".($vectorsModel ?? '@cf/baai/bge-base-en-v1.5')."', { text: [question] });\nconst { matches } = await env.{$vectorsName}.query(data[0], { topK: 5, returnMetadata: 'all' });",
        ],
        'store' => [
            'JavaScript' => "await env.{$vectorsName}.upsert([\n  { id: 'doc-1', values: embedding, metadata: { title: 'Hello' } },\n]);",
            'With Workers AI' => "const { data } = await env.AI.run('".($vectorsModel ?? '@cf/baai/bge-base-en-v1.5')."', { text: docs.map((d) => d.text) });\nawait env.{$vectorsName}.upsert(docs.map((d, i) => ({ id: d.id, values: data[i], metadata: { text: d.text } })));",
        ],
    ] : [
        'search' => [
            'curl' => "curl -X POST http://{$vectorsHostName}/query \\\n  -H 'content-type: application/json' \\\n  -d '{\"vector\": [0.12, -0.03, …], \"topK\": 5}'",
            'PHP' => "\$matches = Http::post('http://{$vectorsHostName}/query', [\n    'vector' => \$embedding,\n    'topK' => 5,\n])->json('matches');",
            'Node' => "const res = await fetch('http://{$vectorsHostName}/query', {\n  method: 'POST',\n  headers: { 'content-type': 'application/json' },\n  body: JSON.stringify({ vector: embedding, topK: 5 }),\n});\nconst { matches } = await res.json();",
        ],
    ];
@endphp
<x-sheet name="resources-vectors" maxWidth="3xl" focusable>
    <x-sheet.header :eyebrow="__('Vector search')" :title="$vectorsLabel !== '' ? $vectorsLabel : __('Vector search')">
        {{ __('Stores embeddings and finds the ones closest to what you send. Use it for semantic search, recommendations and retrieval for AI answers.') }}
    </x-sheet.header>

    <x-sheet.body x-on:resource-opened.window="$event.detail.kind === 'vectors' && $wire.$island('resources-vectors').loadVectors()">
        @if (! is_array($vectorsConnection))
            <p class="text-xs text-brand-moss">{{ __('Loading the index…') }}</p>
        @else
            @if ($vectorsConnection['asleep'])
                <x-sheet.note>
                    {{ __('Asleep. The app does not get this index until you wake it and deploy. Stored vectors are kept and still billed.') }}
                    @can('update', $site)
                        <button type="button" wire:click="sleepConnection({{ \Illuminate\Support\Js::from($vectorsHostName) }}, false)" wire:island="resources-vectors" class="font-semibold underline">{{ __('Wake it') }}</button>
                    @endcan
                </x-sheet.note>
            @endif

            {{-- Where the app reaches it. --}}
            <div class="flex items-center justify-between gap-3 rounded-xl border border-brand-ink/10 px-3.5 py-2.5 dark:border-brand-mist/15" x-data="{ copied: false }">
                <div class="min-w-0">
                    <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $isWorker ? __('Binding') : __('Address') }}</p>
                    <p class="truncate font-mono text-sm font-semibold text-brand-ink" title="{{ $vectorsAddress }}">{{ $vectorsAddress }}</p>
                    <p class="text-2xs text-brand-moss">{{ $isWorker ? __('Your Worker code reads it as env.:name after the next deploy.', ['name' => $vectorsName]) : __('Only this app can reach it. Works after the next deploy.') }}</p>
                </div>
                <x-sheet.button x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($vectorsAddress) }}); copied = true; setTimeout(() => copied = false, 1500)">
                    <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></span>
                </x-sheet.button>
            </div>

            {{-- The index at a glance. --}}
            <x-sheet.section :title="__('Index')">
                @if ($vectorsError)
                    <x-sheet.note tone="warn">{{ $vectorsError }}</x-sheet.note>
                @elseif ($vectorsInfo === null)
                    <p class="text-xs text-brand-moss" wire:loading.remove wire:target="loadVectors">{{ __('Not loaded yet.') }}</p>
                    <p class="text-xs text-brand-moss" wire:loading wire:target="loadVectors">{{ __('Reading the index…') }}</p>
                @else
                    <x-sheet.metrics :cols="3">
                        <x-sheet.metric :label="__('Dimensions')" :note="__('Numbers in each vector. Fixed.')">{{ $vectorsInfo['dimensions'] }}</x-sheet.metric>
                        <x-sheet.metric :label="__('Distance')" :note="__('How closeness is measured. Fixed.')">{{ $vectorsInfo['metric'] !== '' ? $vectorsInfo['metric'] : '—' }}</x-sheet.metric>
                        <x-sheet.metric :label="__('Vectors')" :note="__('New ones show up a few seconds after they are written.')">{{ number_format($vectorsInfo['count']) }}</x-sheet.metric>
                    </x-sheet.metrics>
                @endif
                <div class="flex flex-wrap items-center gap-3">
                    <x-sheet.button wire:click="loadVectors" wire:island="resources-vectors">{{ __('Refresh') }}</x-sheet.button>
                    <span class="font-mono text-2xs text-brand-mist">{{ __('Cloudflare index :name', ['name' => $vectorsConnection['target']]) }}</span>
                </div>
            </x-sheet.section>

            {{-- Try it: real embeddings, a real search. --}}
            @can('update', $site)
                <x-sheet.section :title="__('Try it')">
                    @if ($vectorsModel === null)
                        <x-sheet.note>{{ __('No built-in embedding model makes :n-number vectors, so there is no question demo for this index. Search with a vector below instead.', ['n' => $vectorsDims]) }}</x-sheet.note>
                    @else
                        <div class="grid gap-3 rounded-xl border border-brand-ink/10 p-3.5 dark:border-brand-mist/15" x-data="{
                            question: 'How do I change my password?',
                            busy: '',
                            seeded: null,
                            result: null,
                            error: '',
                            async call(what, fn) {
                                this.busy = what; this.error = '';
                                try { const r = await fn(); if (! r.ok) this.error = r.error; return r; }
                                catch (e) { this.error = String(e); return { ok: false }; }
                                finally { this.busy = ''; }
                            },
                            async seed() { const r = await this.call('seed', () => $wire.$island('resources-vectors').seedVectorsDemo()); if (r.ok) this.seeded = true; },
                            async unseed() { const r = await this.call('unseed', () => $wire.$island('resources-vectors').removeVectorsDemo()); if (r.ok) { this.seeded = false; this.result = null; } },
                            async search() { this.result = null; const r = await this.call('search', () => $wire.$island('resources-vectors').runVectorsDemo(this.question)); if (r.ok) this.result = r; },
                        }">
                            <ol class="grid gap-3 text-xs">
                                <li class="grid gap-2">
                                    <p class="font-semibold text-brand-ink">{{ __('1. Add sample documents') }}</p>
                                    <p class="text-brand-moss">{{ __('Six short help-center answers, embedded with :model and stored with ids starting dply-demo-. They also show up in your app’s own searches until you remove them.', ['model' => $vectorsModel]) }}</p>
                                    <div class="flex flex-wrap gap-2">
                                        <x-sheet.button x-on:click="seed()" x-bind:disabled="busy !== ''">
                                            <span x-text="busy === 'seed' ? @js(__('Adding…')) : (seeded ? @js(__('Added. Add again')) : @js(__('Add sample documents')))"></span>
                                        </x-sheet.button>
                                        <x-sheet.button x-on:click="unseed()" x-bind:disabled="busy !== ''">
                                            <span x-text="busy === 'unseed' ? @js(__('Removing…')) : (seeded === false ? @js(__('Removed')) : @js(__('Remove sample documents')))"></span>
                                        </x-sheet.button>
                                    </div>
                                    <p x-show="seeded" x-cloak class="text-2xs text-brand-moss">{{ __('Give them a few seconds to show up in searches.') }}</p>
                                </li>
                                <li class="grid gap-2">
                                    <p class="font-semibold text-brand-ink">{{ __('2. Ask a question') }}</p>
                                    <p class="text-brand-moss">
                                        @if ($isContainer)
                                            {{ __('dply turns it into a vector with the same model, then your live app searches the index. The result is what your app gets.') }}
                                        @else
                                            {{ __('dply turns it into a vector with the same model and searches the index from here.') }}
                                        @endif
                                    </p>
                                    <form class="flex gap-2" x-on:submit.prevent="search()">
                                        <input type="text" x-model="question" maxlength="500" class="dply-input mt-0 min-w-0 flex-1" aria-label="{{ __('Question') }}" />
                                        <x-sheet.button variant="primary" type="submit" x-bind:disabled="busy !== '' || question.trim() === ''">
                                            <span x-text="busy === 'search' ? @js(__('Searching…')) : @js(__('Search'))"></span>
                                        </x-sheet.button>
                                    </form>
                                </li>
                            </ol>

                            <template x-if="error">
                                <x-sheet.note tone="warn"><span x-text="error"></span></x-sheet.note>
                            </template>

                            <template x-if="result">
                                <div class="grid gap-2">
                                    <p class="flex flex-wrap gap-x-4 gap-y-1 font-mono text-2xs text-brand-mist">
                                        <span x-text="result.via === 'app' ? @js(__('searched by your live app')) : @js(__('searched from the dashboard'))"></span>
                                        <span><b class="text-brand-ink" x-text="result.ms + ' ms'"></b></span>
                                        <span><b class="text-brand-ink" x-text="result.dims"></b> {{ __('numbers from') }} <span x-text="result.model"></span></span>
                                    </p>
                                    <template x-if="result.matches.length === 0">
                                        <x-sheet.empty :message="__('No matches. Add the sample documents first, or wait a few seconds after adding them.')" />
                                    </template>
                                    <ul class="grid gap-1.5">
                                        <template x-for="(match, i) in result.matches" :key="match.id">
                                            <li class="grid gap-1 rounded-lg border border-brand-ink/10 px-3 py-2 dark:border-brand-mist/15" :class="i === 0 && 'border-brand-forest/50 bg-brand-forest/5'">
                                                <div class="flex items-center justify-between gap-3">
                                                    <span class="min-w-0 text-xs text-brand-ink" x-text="match.text || match.id"></span>
                                                    <span class="shrink-0 font-mono text-2xs tabular-nums text-brand-mist" x-text="match.score.toFixed(3)"></span>
                                                </div>
                                                <span class="block h-1 rounded-full bg-brand-ink/10 dark:bg-brand-mist/15"><span class="block h-1 rounded-full bg-brand-forest" :style="`width: ${Math.max(2, Math.min(100, match.score * 100))}%`"></span></span>
                                            </li>
                                        </template>
                                    </ul>
                                    <p class="text-2xs text-brand-moss">{{ __('Higher scores are closer. With cosine, 1 is identical.') }}</p>
                                </div>
                            </template>

                            <p class="border-t border-brand-ink/10 pt-2 text-2xs text-brand-moss dark:border-brand-mist/15">{{ __('Billed like your app’s own usage: the embeddings as AI, each search as queried dimensions, and stored samples as stored dimensions until removed.') }}</p>
                        </div>
                    @endif

                    {{-- Search with a raw vector (reads the index directly, for any size). --}}
                    <details class="group rounded-xl border border-brand-ink/10 dark:border-brand-mist/15">
                        <summary class="cursor-pointer px-3.5 py-2.5 text-xs font-semibold text-brand-ink">{{ __('Search with a vector') }}</summary>
                        <div class="grid gap-3 border-t border-brand-ink/10 p-3.5 dark:border-brand-mist/15">
                            <p class="text-xs text-brand-moss">{{ __('Paste :n numbers. This reads the index directly and does not call the app.', ['n' => $vectorsDims]) }}</p>
                            <textarea wire:model="vectorsQuery" rows="3" spellcheck="false" placeholder="[0.12, -0.03, 0.44, …]" class="dply-input mt-0 font-mono" aria-label="{{ __('Vector (JSON)') }}"></textarea>
                            <div class="flex items-center gap-2">
                                <input type="number" min="1" max="50" wire:model="vectorsTopK" class="dply-input mt-0 w-20" aria-label="{{ __('Results') }}" />
                                <x-sheet.button wire:click="runVectorsQuery" wire:island="resources-vectors" wire:loading.attr="disabled" wire:target="runVectorsQuery">{{ __('Search') }}</x-sheet.button>
                            </div>
                            <x-input-error :messages="$errors->get('vectorsQuery')" />
                            @if ($vectorsMatches === [])
                                <x-sheet.empty :message="__('No matches. An empty index, or vectors written in the last few seconds, return nothing.')" />
                            @elseif (is_array($vectorsMatches))
                                <x-sheet.table>
                                    <table>
                                        <thead><tr><th>{{ __('Id') }}</th><th>{{ __('Score') }}</th><th>{{ __('Metadata') }}</th></tr></thead>
                                        <tbody>
                                            @foreach ($vectorsMatches as $match)
                                                <tr>
                                                    <td class="font-mono">{{ $match['id'] }}</td>
                                                    <td class="font-mono tabular-nums">{{ number_format($match['score'], 4) }}</td>
                                                    <td class="max-w-xs truncate font-mono" title="{{ $match['metadata'] }}">{{ $match['metadata'] !== '' ? $match['metadata'] : '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </x-sheet.table>
                            @endif
                        </div>
                    </details>
                </x-sheet.section>
            @endcan

            {{-- Code: pick the call and the language, copy it. --}}
            <x-sheet.section :title="__('Use it from your app')">
                <div class="grid gap-3 rounded-xl border border-brand-ink/10 p-3.5 dark:border-brand-mist/15" x-data="{
                    snippets: @js($vectorsSnippets),
                    call: 'search',
                    lang: @js(array_key_first($vectorsSnippets['search'])),
                    copied: false,
                    get langs() { return Object.keys(this.snippets[this.call]); },
                    get code() { return this.snippets[this.call][this.lang] ?? this.snippets[this.call][this.langs[0]]; },
                }">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        @if (count($vectorsSnippets) > 1)
                            <div class="flex gap-1 rounded-lg bg-brand-sand/30 p-0.5 dark:bg-zinc-800/60">
                                <button type="button" x-on:click="call = 'search'" :class="call === 'search' ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:text-brand-ink'" class="rounded-md px-2.5 py-1 text-xs font-semibold">{{ __('Search') }}</button>
                                <button type="button" x-on:click="call = 'store'" :class="call === 'store' ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:text-brand-ink'" class="rounded-md px-2.5 py-1 text-xs font-semibold">{{ __('Store') }}</button>
                            </div>
                        @endif
                        <div class="flex gap-1 rounded-lg bg-brand-sand/30 p-0.5 dark:bg-zinc-800/60">
                            <template x-for="l in langs" :key="l">
                                <button type="button" x-on:click="lang = l" :class="lang === l ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:text-brand-ink'" class="rounded-md px-2.5 py-1 text-xs font-semibold" x-text="l"></button>
                            </template>
                        </div>
                    </div>
                    <div class="relative">
                        <pre class="{{ $vectorsCode }}" x-text="code"></pre>
                        <button type="button" x-on:click="navigator.clipboard.writeText(code); copied = true; setTimeout(() => copied = false, 1500)" class="{{ $vectorsCopy }}" x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
                    </div>
                    @unless ($isWorker)
                        <p class="text-2xs text-brand-moss">{{ __('The reply is {"matches": [{"id", "score"}]}, closest first. From a container app the index can only be searched. Store vectors from a Worker app that binds the same index.') }}</p>
                    @endunless
                </div>
            </x-sheet.section>

            @include('livewire.sites.edge.workspace.partials.sheets.metered-usage', ['service' => 'vectors'])

            @can('update', $site)
                {{-- Sleep: like the other resources. --}}
                <x-sheet.section :title="__('Sleep')">
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 px-3.5 py-2.5 dark:border-brand-mist/15">
                        <p class="min-w-0 flex-1 text-xs text-brand-moss">
                            {{ $vectorsConnection['asleep']
                                ? __('Asleep. Wake it, then deploy, to search again.')
                                : __('Sleeping takes the index off the app on the next deploy, so no searches run or bill. Stored vectors are kept and still billed.') }}
                        </p>
                        <x-sheet.button wire:click="sleepConnection({{ \Illuminate\Support\Js::from($vectorsHostName) }}, {{ $vectorsConnection['asleep'] ? 'false' : 'true' }})" wire:island="resources-vectors">{{ $vectorsConnection['asleep'] ? __('Wake') : __('Sleep') }}</x-sheet.button>
                    </div>
                </x-sheet.section>

                <x-sheet.danger :title="__('Remove')">
                    <div class="grid gap-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ __('Detach: this app stops using the index on the next deploy. The index and its vectors are kept, and still billed, for other apps or later.') }}</p>
                            <x-sheet.button wire:click="removeConnection({{ \Illuminate\Support\Js::from($vectorsHostName) }})" x-on:click="$dispatch('close-modal', 'resources-vectors')">{{ __('Detach') }}</x-sheet.button>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ __('Delete: the index and every vector in it are deleted, if this organization created it. This cannot be undone.') }}</p>
                            <x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($vectorsHostName) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Delete') }}</x-sheet.button>
                        </div>
                    </div>
                </x-sheet.danger>
            @endcan
        @endif
    </x-sheet.body>
</x-sheet>
