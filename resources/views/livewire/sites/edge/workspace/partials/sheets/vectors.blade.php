@php
    $vectorsConnection = collect($connections)->first(fn ($c) => $c['host'] === $resourceHost && $c['kind'] === 'vectors');
    $vectorsName = is_array($vectorsConnection) ? $vectorsConnection['name'] : 'SEARCH';
    $vectorsHostName = is_array($vectorsConnection) ? $vectorsConnection['host'] : '';
    $vectorsDims = (int) ($vectorsInfo['dimensions'] ?? 0) ?: 768;
@endphp
<x-sheet name="resources-vectors" maxWidth="3xl" focusable>
    <x-sheet.header :eyebrow="$vectorsHostName !== '' ? $vectorsHostName : null" :title="__('Vector search')" />

    <x-sheet.body x-on:resource-opened.window="$event.detail.kind === 'vectors' && $wire.$island('resources-vectors').loadVectors()">
        @if (! is_array($vectorsConnection))
            <p class="text-xs text-brand-moss">{{ __('Loading the index…') }}</p>
        @else
            <p class="text-xs leading-5 text-brand-moss">{{ __('Finds the stored vectors closest to one you send, for search and recommendations. Store embeddings, then query with the embedding of a question.') }}</p>
            <div class="grid content-start gap-4" x-data="{ tab: 'overview' }">
                <x-sheet.tabs>
                    <button type="button" role="tab" x-on:click="tab = 'overview'" :aria-selected="tab === 'overview' ? 'true' : 'false'">{{ __('Overview') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'connect'" :aria-selected="tab === 'connect' ? 'true' : 'false'">{{ __('Connect') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'query'" :aria-selected="tab === 'query' ? 'true' : 'false'">{{ __('Query') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'settings'" :aria-selected="tab === 'settings' ? 'true' : 'false'">{{ __('Settings') }}</button>
                </x-sheet.tabs>

                <div x-show="tab === 'overview'" class="grid gap-3">
                    @if ($vectorsError)
                        <x-sheet.note tone="warn">{{ $vectorsError }}</x-sheet.note>
                    @elseif ($vectorsInfo === null)
                        <p class="text-xs text-brand-moss" wire:loading.remove wire:target="loadVectors">{{ __('Not loaded yet.') }}</p>
                        <p class="text-xs text-brand-moss" wire:loading wire:target="loadVectors">{{ __('Reading the index…') }}</p>
                    @else
                        <x-sheet.metrics :cols="3">
                            <x-sheet.metric :label="__('Dimensions')">{{ $vectorsInfo['dimensions'] }}</x-sheet.metric>
                            <x-sheet.metric :label="__('Distance')">{{ $vectorsInfo['metric'] !== '' ? $vectorsInfo['metric'] : '—' }}</x-sheet.metric>
                            <x-sheet.metric :label="__('Vectors')" :note="__('New vectors show up a few seconds after they are written.')">{{ number_format($vectorsInfo['count']) }}</x-sheet.metric>
                        </x-sheet.metrics>
                    @endif
                    <div><x-sheet.button wire:click="loadVectors">{{ __('Refresh') }}</x-sheet.button></div>
                    @include('livewire.sites.edge.workspace.partials.sheets.metered-usage', ['service' => 'vectors'])
                    @if ($vectorsConnection['asleep'])
                        <x-sheet.note>{{ __('Asleep. The app does not get this index until you wake it.') }}</x-sheet.note>
                    @endif
                </div>

                <div x-show="tab === 'connect'" x-cloak class="grid gap-5">
                    @if ($isWorker)
                        <x-sheet.section :title="__('Worker')">
                            <p class="text-xs text-brand-moss">{{ __('The next deploy binds the index as env.:name.', ['name' => $vectorsName]) }}</p>
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "await env.{$vectorsName}.upsert([{ id: 'doc-1', values: embedding, metadata: { title: 'Hello' } }]);\n\nconst { matches } = await env.{$vectorsName}.query(embedding, { topK: 5, returnMetadata: 'all' });" }}</pre>
                        </x-sheet.section>
                    @else
                        <p class="text-xs text-brand-moss">{{ __('POST a vector to http://:host/query after the next deploy. The reply lists the closest matches with their scores.', ['host' => $vectorsHostName]) }}</p>
                        <x-sheet.section :title="__('PHP')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "\$matches = Http::post('http://{$vectorsHostName}/query', [\n    'vector' => \$embedding,\n    'topK' => 5,\n])->json('matches');" }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('Node')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "const res = await fetch('http://{$vectorsHostName}/query', {\n  method: 'POST',\n  headers: { 'content-type': 'application/json' },\n  body: JSON.stringify({ vector: embedding, topK: 5 }),\n});\nconst { matches } = await res.json();" }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('curl')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X POST http://{$vectorsHostName}/query \\\n  -H 'content-type: application/json' \\\n  -d '{\"vector\": [0.1, 0.2, ...], \"topK\": 5}'" }}</pre>
                        </x-sheet.section>
                        <x-sheet.note>{{ __('The app address only queries for now. Writing vectors from a container app is not supported yet.') }}</x-sheet.note>
                    @endif
                </div>

                <div x-show="tab === 'query'" x-cloak class="grid gap-3">
                    <p class="text-xs text-brand-moss">{{ __('Query this index from here with a vector of :n numbers. This does not call the app.', ['n' => $vectorsDims]) }}</p>
                    <x-sheet.field :label="__('Vector (JSON)')" for="vectors-query">
                        <textarea id="vectors-query" wire:model="vectorsQuery" rows="4" spellcheck="false" placeholder="[0.12, -0.03, 0.44, …]" class="dply-input mt-0 font-mono"></textarea>
                    </x-sheet.field>
                    <x-sheet.field :label="__('Results')" for="vectors-topk">
                        <input id="vectors-topk" type="number" min="1" max="50" wire:model="vectorsTopK" class="dply-input mt-0 w-24" />
                    </x-sheet.field>
                    <div><x-sheet.button variant="primary" wire:click="runVectorsQuery" wire:loading.attr="disabled" wire:target="runVectorsQuery">{{ __('Query') }}</x-sheet.button></div>
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

                <div x-show="tab === 'settings'" x-cloak class="grid gap-3">
                    <x-sheet.stat :label="__('Index')">{{ $vectorsConnection['target'] }}</x-sheet.stat>
                    @can('update', $site)
                        <x-sheet.danger :title="__('Delete this index')">
                            <p class="text-xs text-brand-moss">{{ __('Deletes the index and every vector in it, if this organization created it. Otherwise it is only detached from this app. This cannot be undone.') }}</p>
                            <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($vectorsConnection['host']) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Delete') }}</x-sheet.button></div>
                        </x-sheet.danger>
                    @endcan
                </div>
            </div>
        @endif
    </x-sheet.body>
</x-sheet>
