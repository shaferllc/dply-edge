@php
    $kindLabels = [
        'kv' => __('KV'),
        'r2' => __('R2'),
        'd1' => __('D1'),
        'queue' => __('Queue'),
    ];
    $valueLabels = [
        'kv' => __('Namespace ID'),
        'r2' => __('Bucket name'),
        'd1' => __('Database ID'),
        'queue' => __('Queue name'),
    ];
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-bindings',
            'what' => __('Attach KV, R2, D1, and Queues to your Edge Worker as env.NAME. Dashboard rows apply on the next deploy; wrangler.toml wins on name collision.'),
            'steps' => [
                __('Pick a binding name (JS identifier) and type.'),
                __('Attach an existing resource id/name, or create a new one.'),
                __('Redeploy so the Worker upload includes the binding.'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Jobs'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'jobs']),
                ],
            ],
            'tips' => [
                __('Needs middleware or SSR — pure static sites have no Worker env.'),
                __('Detach removes the binding from the site; the resource stays.'),
                __('Jobs can manage queue bindings in a modal without leaving that page.'),
            ],
        ])
    </section>

    @unless ($hasWorker)
        <div class="border-b border-amber-200 bg-amber-50 px-5 py-3 text-xs text-amber-900 sm:px-6 dark:border-raw-amber-900/40 dark:bg-raw-amber-950/40 dark:text-raw-amber-100">
            {{ __('No worker on this site yet — bindings attach after you add middleware or enable SSR and redeploy.') }}
        </div>
    @endunless

    {{-- Primary: dashboard-managed bindings --}}
    <section class="border-b border-brand-ink/10">
        @can('update', $site)
            <form wire:submit.prevent="addBinding" class="grid grid-cols-1 gap-3 border-b border-brand-ink/10 px-5 py-4 sm:grid-cols-[1fr_1fr_1.4fr_auto] sm:items-end sm:px-6">
                <div>
                    <label for="new-binding-name" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Name') }}</label>
                    <input
                        id="new-binding-name"
                        type="text"
                        wire:model="new_name"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                        placeholder="SESSIONS"
                        autocomplete="off"
                    />
                    @error('new_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="new-binding-kind" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Type') }}</label>
                    <select
                        id="new-binding-kind"
                        wire:model.live="new_kind"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    >
                        @foreach ($kinds as $kind)
                            <option value="{{ $kind }}">{{ $kindLabels[$kind] ?? $kind }}</option>
                        @endforeach
                    </select>
                    @error('new_kind') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="new-binding-value" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                        {{ $create_resource ? __('New resource name') : ($valueLabels[$new_kind] ?? __('Identifier')) }}
                    </label>
                    <input
                        id="new-binding-value"
                        type="text"
                        wire:model="new_value"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                        placeholder="{{ $create_resource ? 'my-new-resource' : ($valueLabels[$new_kind] ?? '') }}"
                        autocomplete="off"
                    />
                    @error('new_value') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="addBinding"
                    class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="addBinding">{{ $create_resource ? __('Create & attach') : __('Attach') }}</span>
                    <span wire:loading wire:target="addBinding">{{ __('Saving…') }}</span>
                </button>
            </form>

            <label class="flex items-center gap-2 border-b border-brand-ink/10 px-5 py-3 text-xs text-brand-moss sm:px-6">
                <input type="checkbox" wire:model.live="create_resource" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                {{ __('Create a new resource instead of attaching an existing one') }}
            </label>
        @endcan

        @if ($dashboard_bindings === [])
            <div class="px-5 py-5 text-center text-sm text-brand-moss sm:px-6">
                {{ __('No bindings yet.') }}
                <p class="mt-1 text-xs">{{ __('Applied on the next deploy. Names collide in favour of the repo.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($dashboard_bindings as $index => $entry)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 sm:px-6" wire:key="binding-{{ $index }}-{{ $entry['name'] }}">
                        <div class="min-w-0">
                            <p class="font-mono text-sm text-brand-ink">env.{{ $entry['name'] }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss">
                                {{ $kindLabels[$entry['kind']] ?? $entry['kind'] }}
                                <span class="text-brand-mist">·</span>
                                <span class="font-mono">{{ $entry['value'] }}</span>
                            </p>
                        </div>
                        @can('update', $site)
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('removeBinding', @js([$index]), @js(__('Detach binding')), @js(__('Detach :name? The resource and its data stay in place.', ['name' => $entry['name']])), @js(__('Detach')), true)"
                                class="text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400"
                            >
                                {{ __('Detach') }}
                            </button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <details class="group border-b border-brand-ink/10" @if ($repoBindings !== []) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
            <span class="inline-flex items-center gap-2">
                {{ __('From repo') }}
                @if ($repoBindings !== [])
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                        {{ count($repoBindings) }}
                    </span>
                @endif
            </span>
            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
        </summary>

        <div class="border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <p class="text-xs text-brand-moss">{{ __('Read-only — declare in wrangler.toml and redeploy.') }}</p>
            @if ($repoBindings !== [])
                <ul class="mt-3 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10">
                    @foreach ($repoBindings as $entry)
                        <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-3 py-2 text-xs">
                            <span class="font-mono font-semibold text-brand-ink">env.{{ $entry['name'] }}</span>
                            <span class="text-brand-moss">{{ $kindLabels[$entry['kind']] ?? $entry['kind'] }}</span>
                            <span class="min-w-0 break-all font-mono text-brand-mist">{{ $entry['value'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 text-sm text-brand-moss">{{ __('None shipped from the repo yet.') }}</p>
            @endif
        </div>
    </details>

    @include('livewire.partials.confirm-action-modal')
</div>
