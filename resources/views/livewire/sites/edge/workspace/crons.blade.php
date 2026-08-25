<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-crons',
            'what' => __('Schedule your Edge Worker on a UTC cron expression. Dashboard rows merge with dply.yaml on the next deploy — Cloudflare then calls scheduled() in your middleware (or SSR) Worker.'),
            'steps' => [
                __('Add middleware (src/middleware.ts) that exports scheduled — see Use in code below.'),
                __('Add a schedule (5-field cron, UTC) here or in dply.yaml.'),
                __('Redeploy so cron triggers attach and your handler starts running.'),
            ],
            'tips' => [
                __('Expressions are UTC — not your browser timezone.'),
                __('v1 calls the Worker’s scheduled export for every trigger; the Handler field is reserved for later.'),
                __('Needs a Worker (middleware and/or SSR); pure static sites have nothing to schedule.'),
            ],
        ])

        <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3 sm:p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Use in code') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                        {{ __('Add :file (or middleware.ts at the repo root). Cron triggers run on that Worker after deploy — not in the dashboard alone.', ['file' => 'src/middleware.ts']) }}
                    </p>
                </div>
                <button
                    type="button"
                    x-on:click="$dispatch('open-modal', 'edge-crons-code-example')"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 dark:bg-zinc-900"
                >
                    <x-heroicon-o-code-bracket class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Full example') }}
                </button>
            </div>
            <x-edge-yaml-example class="mt-3" file="src/middleware.ts" :hint="__('Pass-through fetch keeps static/SSR serving; scheduled runs on each cron.')">
export default {
  async fetch(request, env) {
    // Continue to static / hybrid origin
    return new Response(null, {
      status: 204,
      headers: { "X-Dply-Middleware": "continue" },
    });
  },

  async scheduled(controller, env, ctx) {
    // controller.cron is the matching UTC expression
    console.log("cron", controller.cron);
    // e.g. await env.JOBS?.send({ type: "cleanup" });
  },
};
            </x-edge-yaml-example>
        </div>

        <div class="mt-5 flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Schedules') }}</p>
                <p class="mt-1 text-sm text-brand-moss">{{ __('UTC cron expressions. Applied on the next deploy.') }}</p>
            </div>
            <span wire:loading.inline-flex wire:target="addCron,removeCron,confirmActionModal" class="inline-flex items-center gap-1.5 text-xs text-brand-moss">
                <x-spinner size="sm" variant="muted" />
                {{ __('Saving…') }}
            </span>
        </div>

        @if ($dashboard_crons === [])
            <x-empty-state
                borderless
                compact
                icon="heroicon-o-clock"
                :title="__('No dashboard schedules yet')"
            />
        @else
            <ul class="mt-3 divide-y divide-brand-ink/8 rounded-xl border border-brand-ink/10">
                @foreach ($dashboard_crons as $index => $entry)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="cron-{{ $index }}-{{ $entry['schedule'] }}">
                        <div class="min-w-0 font-mono text-sm">
                            <span class="text-brand-ink">{{ $entry['schedule'] }}</span>
                            <span class="text-brand-moss"> · {{ $entry['handler'] !== '' ? $entry['handler'] : __('default') }}</span>
                        </div>
                        @can('update', $site)
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('removeCron', @js([$index]), @js(__('Remove schedule')), @js(__('Remove :schedule?', ['schedule' => $entry['schedule']])), @js(__('Remove')), true)"
                                class="text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400"
                            >
                                {{ __('Remove') }}
                            </button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif

        @can('update', $site)
            <form wire:submit.prevent="addCron" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <div>
                    <label for="new-schedule" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Schedule') }}</label>
                    <input
                        id="new-schedule"
                        type="text"
                        wire:model="new_schedule"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900"
                        placeholder="*/5 * * * *"
                        autocomplete="off"
                    />
                    @error('new_schedule') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="new-handler" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Handler') }}</label>
                    <input
                        id="new-handler"
                        type="text"
                        wire:model="new_handler"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900"
                        placeholder="scheduled"
                        autocomplete="off"
                    />
                </div>
                <button type="submit" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90">
                    {{ __('Add') }}
                </button>
            </form>
        @endcan
    </section>

    <details class="group" @if ($repoCrons !== []) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
            <span class="inline-flex items-center gap-2">
                {{ __('Advanced') }}
                @if ($repoCrons !== [])
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                        {{ count($repoCrons) }}
                    </span>
                @endif
            </span>
            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
        </summary>

        <div class="space-y-4 border-t border-brand-ink/10 px-5 py-4 sm:px-6">
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

            @if ($repoCrons !== [])
                <ul class="divide-y divide-brand-ink/8 rounded-xl border border-brand-ink/10">
                    @foreach ($repoCrons as $entry)
                        <li class="px-4 py-2.5 font-mono text-xs">
                            <span class="text-brand-ink">{{ $entry['schedule'] }}</span>
                            <span class="text-brand-moss"> · {{ $entry['handler'] ?: __('default') }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="text-xs text-brand-mist">{{ __('Repo schedules are read-only here. Dashboard rows merge with them on deploy.') }}</p>
            @else
                <p class="text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $sourcePath]) }}</p>
            @endif

            <x-edge-yaml-example :file="$sourcePath" :hint="__('Commit schedules in the repo, or add them above in the dashboard.')">
crons:
  - schedule: "*/5 * * * *"
    handler: "scheduled"
  - schedule: "0 3 * * *"
    handler: "daily"
            </x-edge-yaml-example>
        </div>
    </details>

    <x-modal
        name="edge-crons-code-example"
        :show="false"
        maxWidth="2xl"
        overlayClass="bg-brand-ink/40"
        panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,720px)] flex-col"
        focusable
    >
        <div class="shrink-0 border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Use in code') }}</p>
            <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Middleware cron handler') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('1) Commit middleware. 2) Add schedules above (or in dply.yaml). 3) Redeploy. Cloudflare invokes scheduled() on each match.') }}
            </p>
        </div>
        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4 sm:px-6">
            <x-edge-yaml-example file="src/middleware.ts">
export default {
  async fetch(request, env) {
    return new Response(null, {
      status: 204,
      headers: { "X-Dply-Middleware": "continue" },
    });
  },

  async scheduled(controller, env, ctx) {
    console.log("cron", controller.cron);
    // Optional: enqueue via a queue binding from Jobs / Bindings
    // await env.JOBS.send({ type: "nightly-cleanup" });
  },
};
            </x-edge-yaml-example>
            <x-edge-yaml-example file="dply.yaml" :hint="__('Optional — same schedules can live only in the dashboard.')">
crons:
  - schedule: "*/5 * * * *"
  - schedule: "0 3 * * *"
            </x-edge-yaml-example>
        </div>
        <div class="flex shrink-0 items-center justify-end border-t border-brand-ink/10 px-5 py-3 sm:px-6">
            <button
                type="button"
                x-on:click="$dispatch('close-modal', 'edge-crons-code-example')"
                class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                {{ __('Close') }}
            </button>
        </div>
    </x-modal>

    @include('livewire.partials.confirm-action-modal')
</div>
