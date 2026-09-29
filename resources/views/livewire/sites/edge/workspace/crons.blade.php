@php
    $canEdit = auth()->user()?->can('update', $site);
    $repoCount = collect($rows)->where('source', 'repo')->count();
    $dropped = collect($rows)->where('dropped', true);
    $field = 'mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink focus:border-brand-forest focus:ring-brand-forest dark:border-brand-mist/20 dark:bg-zinc-900';
    $presets = [
        '* * * * *' => __('Every minute'),
        '*/5 * * * *' => __('Every 5 minutes'),
        '0 * * * *' => __('Every hour'),
        '0 6 * * *' => __('Every day at 06:00 UTC'),
        '0 6 * * 1' => __('Every Monday at 06:00 UTC'),
        '0 6 1 * *' => __('First of the month at 06:00 UTC'),
    ];
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'scheduled-tasks',
            'what' => $isContainer
                ? __('Run a command in this app on a schedule. Each schedule becomes a Cron Trigger on the app’s Worker, which calls into the container.')
                : __('Call your Worker’s scheduled() handler on a schedule. Your code gets event.cron to tell the schedules apart.'),
            'steps' => $isContainer
                ? [
                    __('Add a schedule and the :command to run.', ['command' => \Illuminate\Support\Str::lower($commandLabel)]),
                    __('Redeploy so the Cron Triggers attach.'),
                    __('Laravel apps can also turn on the scheduler (schedule:run every minute) on Overview → Resources.'),
                ]
                : [
                    __('Export scheduled() from your middleware or SSR Worker — see the example below.'),
                    __('Add a schedule here or in dply.yaml.'),
                    __('Redeploy so the Cron Triggers attach.'),
                ],
            'tips' => [
                __('Schedules are UTC, not your browser’s timezone.'),
                __('Cloudflare allows :n schedules per Worker. Several commands can share one schedule.', ['n' => $maxSchedules]),
            ],
        ])
    </section>

    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Scheduled tasks') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if ($rows === [] && ! $scheduler)
                    {{ $isContainer ? __('Nothing runs on a schedule in this app yet.') : __('Your Worker isn’t called on a schedule yet.') }}
                @else
                    @if ($scheduler)
                        {{ __('Laravel’s scheduler runs every minute') }}{{ $schedulerInWorker ? __(' inside a queue worker') : '' }}{{ $rows === [] ? '.' : ',' }}
                        @if ($rows !== []) {{ __('plus') }} @endif
                    @endif
                    @if ($rows !== [])
                        <span class="text-brand-sage">{{ trans_choice(':count scheduled task|:count scheduled tasks', count($rows)) }}</span>
                        {{ $isContainer ? __('run in this app.') : __('call your Worker’s scheduled() handler.') }}
                    @endif
                    @if ($dropped->isNotEmpty())
                        <span class="text-amber-600 dark:text-amber-300">{{ trans_choice(':count won’t run: Cloudflare allows :max schedules per Worker.|:count won’t run: Cloudflare allows :max schedules per Worker.', $dropped->count(), ['max' => $maxSchedules]) }}</span>
                    @endif
                @endif
            </p>
        </div>

        <div>
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 pb-2">
                <p class="text-sm font-semibold text-brand-ink">
                    {{ __('Schedules') }}
                    <span class="ml-1 font-normal text-brand-mist">{{ __(':used of :max used', ['used' => $usedSchedules, 'max' => $maxSchedules]) }}</span>
                </p>
                @if ($canEdit)
                    <button type="button" wire:click="newCron" class="inline-flex min-h-9 items-center gap-1 text-sm font-medium text-brand-sage hover:underline">
                        <x-heroicon-m-plus class="h-4 w-4" aria-hidden="true" />{{ __('Add a schedule') }}
                    </button>
                @endif
            </div>

            @if ($scheduler)
                <div class="flex min-h-14 items-center gap-3 border-b border-brand-ink/10 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm text-brand-ink sm:text-base">{{ __('Every minute, run') }} <span class="font-mono">schedule:run</span></span>
                        <span class="mt-0.5 block text-xs text-brand-moss">
                            {{ $schedulerInWorker ? __('Runs in queue worker 0, so the web container isn’t woken.') : __('Uses one schedule slot on the app’s Worker.') }}
                            {{ __('Turn it off on Overview → Resources.') }}
                        </span>
                    </span>
                    @if ($canRunNow && $canEdit)
                        <button type="button" wire:click="runNow('schedule:run')" class="shrink-0 text-xs font-medium text-brand-sage hover:underline">{{ __('Run now') }}</button>
                    @endif
                </div>
            @endif

            @forelse ($rows as $row)
                <div class="flex min-h-14 items-center gap-3 border-b border-brand-ink/10 py-3" wire:key="cron-row-{{ $loop->index }}-{{ $row['schedule'] }}">
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm text-brand-ink sm:text-base">
                            {{ \Illuminate\Support\Str::ucfirst($row['when']) }}{{ $isContainer ? ',' : '' }}
                            @if ($isContainer)
                                {{ __('run') }} <span class="font-mono">{{ $row['handler'] ?? '—' }}</span>
                            @endif
                        </span>
                        <span class="mt-0.5 block font-mono text-xs text-brand-moss">
                            {{ $row['schedule'] }}
                            @unless ($isContainer) · event.cron @endunless
                            · {{ $row['source'] === 'repo' ? $sourcePath : __('dashboard') }}
                        </span>
                        @if ($row['dropped'])
                            <span class="mt-0.5 block text-xs text-amber-600 dark:text-amber-300">{{ __('Won’t run — over the :max-schedule limit.', ['max' => $maxSchedules]) }}</span>
                        @endif
                    </span>
                    <span class="flex shrink-0 items-center gap-4">
                        @if ($canRunNow && $canEdit && $row['handler'])
                            <button type="button" wire:click="runNow(@js($row['handler']))" class="text-xs font-medium text-brand-sage hover:underline">{{ __('Run now') }}</button>
                        @endif
                        @if ($row['index'] !== null && $canEdit)
                            <button type="button" wire:click="editCron({{ $row['index'] }})" class="inline-flex items-center gap-1 text-xs font-medium text-brand-ink hover:underline">{{ __('Edit') }}<x-heroicon-m-chevron-right class="h-3.5 w-3.5 text-brand-mist" aria-hidden="true" /></button>
                        @elseif ($row['source'] === 'repo')
                            <span class="font-mono text-2xs uppercase text-brand-moss">{{ __('Repo') }}</span>
                        @endif
                    </span>
                </div>
            @empty
                @unless ($scheduler)
                    <p class="border-b border-brand-ink/10 py-4 text-sm text-brand-moss">{{ __('None yet. Changes apply on the next deploy.') }}</p>
                @endunless
            @endforelse
        </div>

        @if ($isContainer && $framework === 'other')
            <div>
                <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('In your app') }}</p>
                <x-edge-yaml-example class="mt-3" file="server.js" :hint="__('Each schedule POSTs here with the handler you set. Check the token: DPLY_QUEUE_TOKEN is in the app’s environment.')">
app.post("/_dply/schedule", express.json(), async (req, res) => {
  if (req.get("x-dply-queue-token") !== process.env.DPLY_QUEUE_TOKEN) {
    return res.status(403).json({ error: "Forbidden" });
  }
  const { handler, cron } = req.body; // e.g. "reports:daily", "0 6 * * *"
  // run the task for `handler` here
  res.json({ output: `ran ${handler}` });
});
                </x-edge-yaml-example>
            </div>
        @endif

        @unless ($isContainer)
            <div>
                <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('In your Worker') }}</p>
                <x-edge-yaml-example class="mt-3" file="src/middleware.ts" :hint="__('Each schedule calls scheduled(); branch on controller.cron.')">
export default {
  async fetch(request, env) {
    return new Response(null, { status: 204, headers: { "X-Dply-Middleware": "continue" } });
  },

  async scheduled(controller, env, ctx) {
    if (controller.cron === "0 6 * * *") {
      // daily work
    }
  },
};
                </x-edge-yaml-example>
            </div>
        @endunless
    </section>

    <details class="group" @if ($repoCount > 0) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
            <span class="inline-flex items-center gap-2">
                {{ __('Advanced') }}
                @if ($repoCount > 0)
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ $repoCount }}</span>
                @endif
            </span>
            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
        </summary>
        <div class="space-y-4 border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</p>
                <a href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}" class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline">
                    <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Generate :file', ['file' => $sourcePath]) }}
                </a>
            </div>
            <p class="text-sm text-brand-moss">{{ __('Repo schedules show above, marked Repo, and can only be changed in the file. Dashboard schedules add to them on deploy.') }}</p>
            <x-edge-yaml-example :file="$sourcePath" :hint="__('Commit schedules in the repo, or add them above.')">
crons:
  - schedule: "0 6 * * *"
    handler: "{{ $isContainer ? 'reports:daily' : 'daily' }}"
            </x-edge-yaml-example>
        </div>
    </details>

    <x-modal name="edge-cron" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
        @if ($editingCron !== null)
            <div class="space-y-5 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ $editingCron === -1 ? __('Add a schedule') : __('Edit schedule') }}</h2>
                    <button type="button" wire:click="closeCron" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                <div>
                    <x-input-label for="cron-schedule" :value="__('When (UTC)')" />
                    <div class="mt-1 flex flex-wrap gap-1.5">
                        @foreach ($presets as $expr => $label)
                            <button type="button" wire:click="$set('new_schedule', @js($expr))" @class(['min-h-8 rounded-full border px-3 text-xs font-medium', 'border-brand-sage bg-brand-sage/10 text-brand-ink' => $new_schedule === $expr, 'border-brand-ink/15 text-brand-moss hover:text-brand-ink' => $new_schedule !== $expr])>{{ $label }}</button>
                        @endforeach
                    </div>
                    <input id="cron-schedule" type="text" wire:model.live.debounce.300ms="new_schedule" placeholder="*/5 * * * *" autocomplete="off" class="{{ $field }} mt-2 font-mono" />
                    <p class="mt-1 text-xs text-brand-moss">{{ \Illuminate\Support\Str::ucfirst(\App\Livewire\Sites\Edge\Workspace\Crons::describe($new_schedule)) }}</p>
                    @error('new_schedule') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                @if ($isContainer)
                    <div>
                        <x-input-label for="cron-handler" :value="$commandLabel" />
                        <input id="cron-handler" type="text" wire:model="new_handler" placeholder="reports:daily" autocomplete="off" class="{{ $field }} font-mono" />
                        <p class="mt-1 text-xs text-brand-moss">
                            @if ($framework === 'laravel')
                                {{ __('Runs in the live container, like php artisan :example.', ['example' => 'reports:daily']) }}
                            @elseif ($framework === 'rails')
                                {{ __('Runs in the live container, like rake :example.', ['example' => 'reports:daily']) }}
                            @else
                                {{ __('Sent to your app as the handler in POST /_dply/schedule — your app decides what it means.') }}
                            @endif
                        </p>
                    </div>
                @else
                    <p class="rounded-lg bg-brand-sand/20 px-3 py-2 text-xs text-brand-moss">{{ __('Your Worker’s scheduled() handler runs; controller.cron is this expression.') }}</p>
                @endif

                <div class="flex items-center justify-between gap-2">
                    @if ($editingCron >= 0)
                        <button type="button" wire:click="removeEditingCron" class="text-sm font-semibold text-red-600 hover:underline">{{ __('Remove schedule') }}</button>
                    @else
                        <span></span>
                    @endif
                    <span class="flex gap-2">
                        <button type="button" wire:click="closeCron" class="rounded-lg border border-brand-ink/15 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                        <x-primary-button type="button" wire:click="saveCron" wire:loading.attr="disabled" wire:target="saveCron">{{ __('Save') }}</x-primary-button>
                    </span>
                </div>
            </div>
        @endif
    </x-modal>

    <x-modal name="edge-cron-run" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        <div class="space-y-4 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Run') }} <span class="font-mono">{{ $runCommand }}</span></h2>
                <button type="button" x-on:click="$dispatch('close-modal', 'edge-cron-run')" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                    <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
            @if ($runOutput === null)
                <p class="inline-flex items-center gap-2 text-sm text-brand-moss"><x-spinner size="sm" variant="muted" />{{ __('Running in the live app…') }}</p>
            @else
                <pre class="max-h-80 overflow-auto rounded-lg bg-brand-sand/15 p-3 font-mono text-xs leading-relaxed text-brand-ink dark:bg-zinc-950">{{ $runOutput }}</pre>
            @endif
        </div>
    </x-modal>

    @include('livewire.partials.confirm-action-modal')
</div>
