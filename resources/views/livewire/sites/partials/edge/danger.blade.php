{{-- Nested inside the Danger zone page card — strips, no second page card. --}}
@php
    $canDelete = auth()->user()?->can('delete', $site);
    $canUpdate = auth()->user()?->can('update', $site);
    $isPreview = $site->isEdgePreview();
    $stepLabels = [
        'webhook' => __('Remove GitHub webhook'),
        'previews' => __('Delete preview sites'),
        'domains' => __('Remove custom domains'),
        'scripts' => __('Delete Workers scripts'),
        'storage' => __('Delete KV store and realtime apps'),
        'deployments' => __('Stop live traffic and delete deployments'),
    ];
    $currentStep = $inventory['teardown']['step'] ?? null;
    $currentIndex = $currentStep !== null ? array_search($currentStep, $teardownSteps, true) : -1;
    $startedAt = isset($inventory['teardown']['started_at']) ? \Illuminate\Support\Carbon::parse($inventory['teardown']['started_at']) : null;
    $rowButton = 'inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 sm:self-center dark:bg-zinc-900';
@endphp

<div class="min-w-0">
    @if ($site->status === \App\Models\Site::STATUS_EDGE_DELETING)
        {{-- Teardown in progress: the job writes meta.edge.teardown.step as it goes; watchTeardown polls. --}}
        <section class="px-5 py-5 sm:px-6">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-rose-700 dark:text-rose-300">
                {{ __('Deleting') }}@if ($startedAt) · {{ __('started :ago', ['ago' => $startedAt->diffForHumans()]) }}@endif
            </p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Removing :name', ['name' => $site->name]) }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('You can leave this page. Teardown keeps running in the background.') }}</p>

            <div class="mt-4 h-1 overflow-hidden rounded-full bg-brand-ink/10" aria-hidden="true">
                <div class="h-full bg-rose-500 transition-all duration-500" style="width: {{ $currentIndex === false || $currentIndex < 0 ? 4 : round(($currentIndex + 0.5) / count($teardownSteps) * 100) }}%"></div>
            </div>

            <ol class="mt-3 divide-y divide-dashed divide-brand-ink/10">
                @foreach ($teardownSteps as $i => $step)
                    @php $state = $currentIndex === false || $currentIndex < 0 ? 'wait' : ($i < $currentIndex ? 'done' : ($i === $currentIndex ? 'run' : 'wait')); @endphp
                    <li class="flex items-center gap-3 py-2 text-sm {{ $state === 'wait' ? 'text-brand-mist' : 'text-brand-ink' }}">
                        <span class="inline-flex h-4 w-4 shrink-0 items-center justify-center">
                            @if ($state === 'done')
                                <x-heroicon-s-check-circle class="h-4 w-4 text-emerald-500" aria-hidden="true" />
                            @elseif ($state === 'run')
                                <x-spinner size="sm" />
                            @else
                                <span class="h-3 w-3 rounded-full border border-brand-ink/20"></span>
                            @endif
                        </span>
                        <span>{{ $stepLabels[$step] ?? $step }}</span>
                        <span class="sr-only">{{ ['done' => __('done'), 'run' => __('in progress'), 'wait' => __('waiting')][$state] }}</span>
                    </li>
                @endforeach
            </ol>
        </section>
    @else
        {{-- Reversible actions first. --}}
        @if ($canUpdate && ! $isPreview)
            <section class="flex flex-col gap-3 border-b border-brand-ink/10 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:px-6">
                <div class="min-w-0">
                    <h3 class="flex flex-wrap items-center gap-2 text-sm font-semibold text-brand-ink">
                        {{ $inventory['paused'] ? __('Site is paused') : __('Pause site') }}
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider ring-1 text-emerald-700 ring-emerald-300 dark:text-emerald-300 dark:ring-raw-emerald-800">{{ __('Reversible') }}</span>
                    </h3>
                    <p class="mt-0.5 max-w-2xl text-sm text-brand-moss">
                        {{ $inventory['paused']
                            ? __('Visitors see a paused page. Resume to serve the live deployment again.')
                            : __('Visitors see a paused page. Deployments, domains and previews stay. Resume anytime.') }}
                    </p>
                </div>
                @if ($inventory['paused'])
                    <button type="button" wire:click="resumeEdgeSite" wire:loading.attr="disabled" class="{{ $rowButton }}">
                        <x-heroicon-o-play class="h-4 w-4" aria-hidden="true" /> {{ __('Resume') }}
                    </button>
                @else
                    <button type="button" wire:click="pauseEdgeSite" wire:loading.attr="disabled" class="{{ $rowButton }}">
                        <x-heroicon-o-pause class="h-4 w-4" aria-hidden="true" /> {{ __('Pause') }}
                    </button>
                @endif
            </section>

            @if ($inventory['webhook'])
                <section class="flex flex-col gap-3 border-b border-brand-ink/10 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:px-6">
                    <div class="min-w-0">
                        <h3 class="flex flex-wrap items-center gap-2 text-sm font-semibold text-brand-ink">
                            {{ __('Disconnect repository') }}
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider ring-1 text-emerald-700 ring-emerald-300 dark:text-emerald-300 dark:ring-raw-emerald-800">{{ __('Reversible') }}</span>
                        </h3>
                        <p class="mt-0.5 max-w-2xl text-sm text-brand-moss">
                            {{ __('Stops automatic deploys from :repo. The live site keeps serving the current deployment. Reconnect under Deploy triggers.', ['repo' => $inventory['repo']]) }}
                        </p>
                    </div>
                    <button type="button" wire:click="disconnectEdgeRepository" wire:loading.attr="disabled" class="{{ $rowButton }}">
                        <x-heroicon-o-link-slash class="h-4 w-4" aria-hidden="true" /> {{ __('Disconnect') }}
                    </button>
                </section>
            @endif
        @endif

        {{-- Delete: what goes, what stays. --}}
        @if ($canDelete)
            <section class="border-t border-rose-200 first:border-t-0 dark:border-raw-rose-800/60">
                <div class="bg-rose-50/60 px-5 py-4 sm:px-6 dark:bg-rose-950/20">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-rose-700 dark:text-rose-300">{{ __('Permanent') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-rose-900 dark:text-raw-rose-100">{{ __('Delete :name', ['name' => $site->name]) }}</h3>
                    <p class="mt-1 max-w-2xl text-sm text-brand-moss">
                        {{ __('Live traffic to :host stops once teardown finishes. This can’t be undone.', ['host' => $site->edgeHostname()]) }}
                    </p>
                </div>

                <div class="grid gap-px bg-brand-ink/10 sm:grid-cols-2">
                    <div class="bg-white px-5 py-4 sm:px-6 dark:bg-zinc-900">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-rose-700 dark:text-rose-300">{{ __('Deleted') }}</p>
                        <dl class="mt-2 space-y-1.5 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-brand-ink">{{ __('Deployments') }}</dt><dd class="font-mono text-xs tabular-nums text-brand-moss">{{ number_format($inventory['deployments']) }}</dd></div>
                            @unless ($isPreview)
                                <div class="flex justify-between gap-4"><dt class="text-brand-ink">{{ __('Preview sites') }}</dt><dd class="font-mono text-xs tabular-nums text-brand-moss">{{ $inventory['previews'] }}</dd></div>
                            @endunless
                            <div class="flex justify-between gap-4"><dt class="text-brand-ink">{{ __('Custom domains') }}</dt><dd class="min-w-0 truncate text-right font-mono text-xs text-brand-moss" title="{{ implode(', ', $inventory['domains']) }}">{{ $inventory['domains'] === [] ? __('none') : implode(', ', $inventory['domains']) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-brand-ink">{{ __('Default KV store') }}</dt><dd class="font-mono text-xs text-brand-moss">1</dd></div>
                            @if ($inventory['webhook'])
                                <div class="flex justify-between gap-4"><dt class="text-brand-ink">{{ __('GitHub webhook') }}</dt><dd class="min-w-0 truncate font-mono text-xs text-brand-moss">{{ $inventory['repo'] }}</dd></div>
                            @endif
                        </dl>
                    </div>
                    <div class="bg-white px-5 py-4 sm:px-6 dark:bg-zinc-900">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-emerald-700 dark:text-emerald-300">{{ __('Kept in your organization') }}</p>
                        <dl class="mt-2 space-y-1.5 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-brand-ink">{{ __('Databases') }}</dt><dd class="font-mono text-xs tabular-nums text-brand-moss">{{ $inventory['databases'] }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-brand-ink">{{ __('Queues') }}</dt><dd class="font-mono text-xs tabular-nums text-brand-moss">{{ $inventory['queues'] }}</dd></div>
                        </dl>
                    </div>
                </div>

                <div class="flex flex-col gap-3 bg-rose-50/60 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:bg-rose-950/20">
                    <p class="text-sm text-rose-900 dark:text-raw-rose-100">{{ __('Billing for this site stops when teardown completes.') }}</p>
                    <button
                        type="button"
                        wire:click="openEdgeTeardownModal"
                        class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg bg-rose-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-700 sm:self-center"
                    >
                        <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                        {{ __('Delete :name…', ['name' => $site->name]) }}
                    </button>
                </div>
            </section>
        @elseif (! $canUpdate)
            <div class="px-5 py-5 sm:px-6">
                <p class="text-sm leading-relaxed text-brand-moss">{{ __('You don’t have permission to change or delete this site.') }}</p>
            </div>
        @endif
    @endif
</div>

<x-modal
    name="edge-teardown-confirmation"
    :show="false"
    maxWidth="lg"
    overlayClass="bg-brand-ink/30"
    panelClass="dply-modal-panel"
    focusable
>
    <div
        x-data="{ name: '', ack: @js($inventory['domains'] === []), expected: @js($site->name) }"
        x-on:close-modal.window="if ($event.detail === 'edge-teardown-confirmation') { name = ''; ack = @js($inventory['domains'] === []) }"
    >
        <div class="border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rose-700 dark:text-rose-300">{{ __('Danger zone') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Delete :name?', ['name' => $site->name]) }}</h2>
            <p class="mt-2 text-sm leading-6 text-brand-moss">{{ __('Your organization’s databases, queues and buckets are not touched.') }}</p>
        </div>

        <div class="space-y-4 px-6 py-5">
            <div class="grid grid-cols-3 gap-2">
                @foreach ([
                    [$inventory['deployments'], trans_choice('deployment|deployments', $inventory['deployments'])],
                    [$inventory['previews'], trans_choice('preview|previews', $inventory['previews'])],
                    [count($inventory['domains']), trans_choice('domain|domains', count($inventory['domains']))],
                ] as [$n, $label])
                    <div class="rounded-lg border border-brand-ink/10 px-3 py-2">
                        <p class="font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ number_format($n) }}</p>
                        <p class="text-xs text-brand-moss">{{ $label }}</p>
                    </div>
                @endforeach
            </div>

            <div>
                <label for="edge-teardown-name" class="text-sm text-brand-moss">
                    {!! __('Type :name to confirm', ['name' => '<code class="rounded border border-brand-ink/10 px-1.5 py-0.5 font-mono text-xs text-brand-ink">'.e($site->name).'</code>']) !!}
                </label>
                <input
                    id="edge-teardown-name"
                    type="text"
                    x-model="name"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="{{ $site->name }}"
                    class="dply-input font-mono"
                    x-on:keydown.enter.prevent="if (name.trim() === expected && ack) $wire.tearDownEdge(name)"
                />
            </div>

            @if ($inventory['domains'] !== [])
                <label class="flex items-start gap-2.5 text-sm text-brand-moss">
                    <input type="checkbox" x-model="ack" class="mt-0.5 rounded border-brand-ink/20 text-rose-600 focus:ring-rose-500" />
                    <span>{{ __(':domains will stop resolving.', ['domains' => implode(', ', $inventory['domains'])]) }}</span>
                </label>
            @endif
        </div>

        <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
            <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'edge-teardown-confirmation')">
                {{ __('Cancel') }}
            </x-secondary-button>
            <x-danger-button
                type="button"
                x-bind:disabled="name.trim() !== expected || ! ack"
                x-on:click="$wire.tearDownEdge(name)"
                wire:loading.attr="disabled"
                wire:target="tearDownEdge"
                class="disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="tearDownEdge">{{ __('Delete :name', ['name' => $site->name]) }}</span>
                <span wire:loading wire:target="tearDownEdge">{{ __('Queueing…') }}</span>
            </x-danger-button>
        </div>
    </div>
</x-modal>
