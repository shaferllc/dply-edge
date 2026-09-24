{{-- Nested inside Edge Settings Danger merged card — strips, no second page card. --}}
<div class="min-w-0">
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-danger',
            'what' => __('Permanently remove this site from dply Edge. Teardown stops live traffic and deletes deployments, CDN entries, and custom domain routing.'),
            'steps' => [
                __('Confirm you no longer need the live URL, previews, or build logs for this site.'),
                __('Click Delete Edge site, then confirm in the modal to queue teardown.'),
            ],
            'tips' => [
                __('This cannot be undone. This site stops counting toward your plan once teardown finishes.'),
                __('Preview deployments for this site are removed with the parent.'),
            ],
        ])
    </section>

    @can('delete', $site)
        <section class="border-b border-rose-200 last:border-b-0">
            <div class="flex flex-col gap-4 bg-rose-50/60 px-5 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-6 dark:bg-rose-950/20">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-rose-100 text-rose-700 ring-rose-200 dark:bg-rose-950/40 dark:text-raw-rose-200 dark:ring-raw-rose-800">
                        <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-rose-700 dark:text-rose-300">{{ __('Destructive') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-rose-900 dark:text-raw-rose-100">{{ __('Delete Edge site') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Removes the site from dply Edge. A background job tears down deployments, CDN/storage artifacts, custom domain routing, and preview child sites. Live traffic stops when teardown completes.') }}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    wire:click="openEdgeTeardownModal"
                    class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm transition hover:bg-rose-100 dark:border-raw-rose-800 dark:bg-zinc-900 dark:text-raw-rose-200 dark:hover:bg-rose-950/40"
                >
                    <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                    {{ __('Delete Edge site') }}
                </button>
            </div>
        </section>
    @else
        <div class="px-5 py-5 sm:px-6">
            <p class="text-sm leading-relaxed text-brand-moss">{{ __('You don’t have permission to delete this Edge site.') }}</p>
        </div>
    @endcan
</div>

<x-modal
    name="edge-teardown-confirmation"
    :show="false"
    maxWidth="lg"
    overlayClass="bg-brand-ink/30"
    panelClass="dply-modal-panel"
    focusable
>
    <div class="border-b border-brand-ink/10 px-6 py-5">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Danger zone') }}</p>
        <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Delete this Edge site?') }}</h2>
        <p class="mt-2 text-sm leading-6 text-brand-moss">
            {{ __('All deployments and edge routing entries for :name will be removed permanently.', ['name' => $site->name]) }}
        </p>
    </div>
    <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'edge-teardown-confirmation')">
            {{ __('Cancel') }}
        </x-secondary-button>
        <x-danger-button type="button" wire:click="tearDownEdge" wire:loading.attr="disabled" wire:target="tearDownEdge">
            <span wire:loading.remove wire:target="tearDownEdge">{{ __('Delete Edge site') }}</span>
            <span wire:loading wire:target="tearDownEdge">{{ __('Queueing…') }}</span>
        </x-danger-button>
    </div>
</x-modal>
