<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-jobs',
            'what' => __('Edge Jobs wire a default queue binding so middleware or SSR workers can enqueue background work without a separate Cloud app.'),
            'steps' => [
                __('Add a queue binding (name it something like JOBS) — use Manage bindings below, no need to leave this page.'),
                __('Set that name as the default queue binding and enable Jobs.'),
                __('From middleware/SSR code, send messages with await env.JOBS.send({ type: "…", … }) (binding name must match).'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Resources page'),
                    'href' => $bindingsUrl,
                ],
            ],
            'tips' => [
                __('Consumers run in your bound worker scripts — this page configures the default binding name and lets you attach queues.'),
                __('Bindings apply on the next deploy. Creating a resource needs platform Edge credentials.'),
            ],
        ])

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span class="text-sm font-medium text-brand-ink">{{ __('Enable Edge jobs') }}</span>
            </label>

            <div>
                <x-input-label for="default_queue" :value="__('Default queue binding name')" />
                <x-text-input id="default_queue" wire:model="default_queue" type="text" class="mt-1 block w-full font-mono text-sm" :disabled="! $managedDelivery" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Must match a queue binding (e.g. JOBS). Use Manage bindings to create one.') }}</p>
            </div>

            <div class="rounded-xl border border-brand-ink/10 p-3 sm:p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Queue bindings') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Attached queues available as env.NAME in middleware / SSR.') }}</p>
                    </div>
                    @can('update', $site)
                        <button
                            type="button"
                            wire:click="openManageBindingsModal"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                            @disabled(! $managedDelivery)
                        >
                            <x-heroicon-o-cube class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Manage bindings') }}
                        </button>
                    @endcan
                </div>

                @if ($queueBindings === [])
                    <x-empty-state
                        borderless
                        compact
                        icon="heroicon-o-queue-list"
                        :title="__('No queue bindings yet')"
                    />
                @else
                    <ul class="mt-3 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10">
                        @foreach ($queueBindings as $binding)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                                <div class="min-w-0">
                                    <span class="font-mono text-xs font-semibold text-brand-ink">env.{{ $binding['name'] ?? '—' }}</span>
                                    @if (($binding['source'] ?? '') === 'repo')
                                        <span class="ml-1 rounded-full bg-brand-sand/60 px-1.5 py-0.5 font-mono text-2xs uppercase text-brand-moss">{{ __('Repo') }}</span>
                                    @endif
                                    <p class="mt-0.5 font-mono text-xs text-brand-mist">{{ $binding['value'] ?? '' }}</p>
                                </div>
                                @if (($binding['name'] ?? '') === $default_queue)
                                    <span class="text-xs font-semibold text-brand-forest">{{ __('Default') }}</span>
                                @elseif ($managedDelivery)
                                    <button
                                        type="button"
                                        wire:click="useQueueBinding(@js($binding['name'] ?? ''))"
                                        class="text-xs font-semibold text-brand-sage hover:underline"
                                    >
                                        {{ __('Use as default') }}
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="flex justify-end">
                <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" :disabled="! $managedDelivery">
                    <span wire:loading.remove wire:target="save">{{ __('Save') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
        </div>
    </section>

    <x-modal
        name="edge-jobs-bindings-modal"
        :show="false"
        maxWidth="2xl"
        overlayClass="bg-brand-ink/40"
        panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,820px)] flex-col"
        focusable
    >
        <div class="shrink-0 border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Bindings') }}</p>
            <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Queue bindings') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Create or attach a queue, then use its binding name as the default below. Applies on the next deploy.') }}</p>
        </div>

        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4 sm:px-6">
            @unless ($hasWorker)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-raw-amber-900/40 dark:bg-raw-amber-950/40 dark:text-raw-amber-100">
                    {{ __('No worker on this site yet — bindings attach after you add middleware or enable SSR and redeploy.') }}
                </div>
            @endunless

            @can('update', $site)
                <form wire:submit.prevent="addQueueBinding" class="space-y-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3">
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Add queue binding') }}</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label for="jobs-binding-name" :value="__('Binding name')" />
                            <x-text-input
                                id="jobs-binding-name"
                                wire:model="new_name"
                                type="text"
                                class="mt-1 block w-full font-mono text-sm"
                                placeholder="JOBS"
                                autocomplete="off"
                            />
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Becomes env.NAME in your worker.') }}</p>
                            @error('new_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-input-label for="jobs-binding-value" :value="$create_resource ? __('New queue name') : __('Existing queue name')" />
                            <x-text-input
                                id="jobs-binding-value"
                                wire:model="new_value"
                                type="text"
                                class="mt-1 block w-full font-mono text-sm"
                                placeholder="{{ $create_resource ? 'my-site-jobs' : 'existing-queue-name' }}"
                                autocomplete="off"
                            />
                            @error('new_value') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-xs text-brand-moss">
                        <input type="checkbox" wire:model.live="create_resource" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                        {{ __('Create a new queue instead of attaching an existing one') }}
                    </label>
                    <div class="flex justify-end">
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="addQueueBinding">
                            <span wire:loading.remove wire:target="addQueueBinding">{{ $create_resource ? __('Create & attach') : __('Attach') }}</span>
                            <span wire:loading wire:target="addQueueBinding">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            @endcan

            <div>
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Dashboard queues') }}</p>
                @if ($dashboardQueueBindings === [])
                    <p class="mt-2 text-sm text-brand-moss">{{ __('None yet — add one above.') }}</p>
                @else
                    <ul class="mt-2 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10">
                        @foreach ($dashboard_bindings as $index => $entry)
                            @continue(($entry['kind'] ?? '') !== 'queue')
                            <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" wire:key="jobs-binding-{{ $index }}-{{ $entry['name'] }}">
                                <div class="min-w-0">
                                    <p class="font-mono text-sm text-brand-ink">env.{{ $entry['name'] }}</p>
                                    <p class="mt-0.5 font-mono text-xs text-brand-mist">{{ $entry['value'] }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    @if ($entry['name'] !== $default_queue)
                                        <button type="button" wire:click="useQueueBinding(@js($entry['name']))" class="text-xs font-semibold text-brand-sage hover:underline">
                                            {{ __('Use as default') }}
                                        </button>
                                    @endif
                                    @can('update', $site)
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('removeBinding', @js([$index]), @js(__('Detach binding')), @js(__('Detach :name? The queue and its data stay in place.', ['name' => $entry['name']])), @js(__('Detach')), true)"
                                            class="text-xs font-semibold text-rose-700 hover:text-rose-900 dark:text-rose-400"
                                        >
                                            {{ __('Detach') }}
                                        </button>
                                    @endcan
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @php
                $repoQueues = collect($queueBindings)->where('source', 'repo')->values();
            @endphp
            @if ($repoQueues->isNotEmpty())
                <div>
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('From repo') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Read-only — declare in wrangler.toml and redeploy.') }}</p>
                    <ul class="mt-2 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10">
                        @foreach ($repoQueues as $entry)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                <div>
                                    <span class="font-mono text-sm text-brand-ink">env.{{ $entry['name'] }}</span>
                                    <p class="mt-0.5 font-mono text-xs text-brand-mist">{{ $entry['value'] ?? '' }}</p>
                                </div>
                                @if (($entry['name'] ?? '') !== $default_queue)
                                    <button type="button" wire:click="useQueueBinding(@js($entry['name'] ?? ''))" class="text-xs font-semibold text-brand-sage hover:underline">
                                        {{ __('Use as default') }}
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 px-5 py-3 sm:px-6">
            <a href="{{ $bindingsUrl }}" wire:navigate class="text-xs font-semibold text-brand-sage hover:underline">
                {{ __('Open full Bindings page') }}
            </a>
            <button
                type="button"
                wire:click="closeManageBindingsModal"
                class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                {{ __('Done') }}
            </button>
        </div>
    </x-modal>

    @include('livewire.partials.confirm-action-modal')
</div>
