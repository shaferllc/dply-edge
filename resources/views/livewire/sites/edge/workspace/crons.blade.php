{{-- Scheduled tasks on Overview: the sheets behind the map's "Scheduled tasks"
     box (Resources draws the box). Everything here is teleported sheets. --}}
@php
    $canEdit = auth()->user()?->can('update', $site);
    $dropped = collect($rows)->where('dropped', true);
@endphp

<div>
    <x-sheet name="edge-crons" maxWidth="lg">
        <x-sheet.header :eyebrow="__('Resources')" :title="__('Scheduled tasks')">
            {{ $isContainer
                ? __('Each schedule becomes a Cron Trigger on the app’s Worker, which runs the command in the container.')
                : __('Each schedule calls your Worker’s scheduled() handler; event.cron tells them apart.') }}
            @if ($canEdit)
                <x-slot:actions>
                    <x-sheet.button variant="primary" wire:click="newCron">{{ __('Add') }}</x-sheet.button>
                </x-slot:actions>
            @endif
        </x-sheet.header>

        <x-sheet.body>
            <p class="text-sm text-brand-ink">
                @if ($rows === [] && ! $scheduler)
                    {{ $isContainer ? __('Nothing runs on a schedule in this app yet.') : __('Your Worker isn’t called on a schedule yet.') }}
                @else
                    {{ $isContainer
                        ? trans_choice(':count task|:count tasks', $usedSchedules).' · '.__('checked every minute, up to :max', ['max' => $maxSchedules])
                        : __(':used of :max schedules used', ['used' => $usedSchedules, 'max' => $maxSchedules]) }} · {{ __('times are UTC, changes apply on the next deploy.') }}
                @endif
                @if ($dropped->isNotEmpty())
                    <span class="block text-amber-600 dark:text-amber-300">{{ trans_choice(':count won’t run. See the marked row.|:count won’t run. See the marked rows.', $dropped->count()) }}</span>
                @endif
            </p>

            <div class="grid gap-1.5">
                @if ($scheduler)
                    <div class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3.5 py-2.5 dark:border-brand-mist/20 dark:bg-zinc-800/40">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-brand-ink">{{ __('Every minute, run') }} <span class="font-mono">schedule:run</span></span>
                            <span class="block text-2xs text-brand-mist">
                                {{ $schedulerInWorker ? __('Laravel scheduler, in queue worker 0 so the web container isn’t woken.') : __('Laravel scheduler. The app wakes only when one of its tasks is due.') }}
                            </span>
                        </span>
                        @if ($canRunNow && $canEdit)
                            <x-sheet.button x-on:click="$dispatch('open-modal', 'edge-cron-run')" wire:click="runNow('schedule:run')">{{ __('Run now') }}</x-sheet.button>
                            <x-sheet.button variant="danger" x-on:click="$dispatch('edge-scheduler-remove')">{{ __('Remove') }}</x-sheet.button>
                        @endif
                    </div>
                @endif

                @foreach ($rows as $row)
                    <div class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3.5 py-2.5 dark:border-brand-mist/20 dark:bg-zinc-800/40" wire:key="cron-row-{{ $loop->index }}-{{ $row['schedule'] }}">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-brand-ink">
                                {{ \Illuminate\Support\Str::ucfirst($row['when']) }}@if ($isContainer), {{ __('run') }} <span class="font-mono">{{ $row['handler'] ?? '—' }}</span>@endif
                            </span>
                            <span class="block truncate font-mono text-2xs text-brand-mist">
                                {{ $row['schedule'] }}@unless ($isContainer) · event.cron @endunless · {{ $row['source'] === 'repo' ? $sourcePath : __('dashboard') }}
                            </span>
                            @if ($row['dropped'])
                                <span class="block text-2xs text-amber-600 dark:text-amber-300">{{ $row['dropped_reason'] === 'unsupported'
                                    ? __('Won’t run: use numbers, *, ranges, lists, steps and names like MON. L, W, # and ? aren’t supported.')
                                    : ($isContainer
                                        ? __('Won’t run: an app runs up to :max scheduled tasks.', ['max' => $maxSchedules])
                                        : __('Won’t run: Cloudflare allows :max schedules per Worker.', ['max' => $maxSchedules])) }}</span>
                            @endif
                        </span>
                        <span class="flex shrink-0 items-center gap-1.5">
                            @if ($canRunNow && $canEdit && $row['handler'])
                                <x-sheet.button x-on:click="$dispatch('open-modal', 'edge-cron-run')" wire:click="runNow({{ \Illuminate\Support\Js::from($row['handler']) }})">{{ __('Run now') }}</x-sheet.button>
                            @endif
                            @if ($row['index'] !== null && $canEdit)
                                <x-sheet.button wire:click="editCron({{ $row['index'] }})">{{ __('Edit') }}</x-sheet.button>
                            @elseif ($row['source'] === 'repo')
                                <span class="font-mono text-2xs uppercase text-brand-moss" title="{{ __('Change it in :file', ['file' => $sourcePath]) }}">{{ __('Repo') }}</span>
                            @endif
                        </span>
                    </div>
                @endforeach

                @if ($rows === [] && ! $scheduler)
                    <x-sheet.empty :message="__('None yet.')">
                        @if ($canEdit)
                            <x-sheet.button wire:click="newCron">{{ __('Add a scheduled task') }}</x-sheet.button>
                        @endif
                    </x-sheet.empty>
                @endif
            </div>

            @if ($isContainer && $framework === 'other')
                <x-sheet.section :title="__('In your app')">
                    <x-edge-yaml-example file="server.js" :hint="__('Each schedule POSTs here with the handler you set. Check the token: DPLY_QUEUE_TOKEN is in the app’s environment.')">
app.post("/_dply/schedule", express.json(), async (req, res) => {
  if (req.get("x-dply-queue-token") !== process.env.DPLY_QUEUE_TOKEN) {
    return res.status(403).json({ error: "Forbidden" });
  }
  const { handler, cron } = req.body; // e.g. "reports:daily", "0 6 * * *"
  // run the task for `handler` here
  res.json({ output: `ran ${handler}` });
});
                    </x-edge-yaml-example>
                </x-sheet.section>
            @endif

            @unless ($isContainer)
                <x-sheet.section :title="__('In your Worker')">
                    <x-edge-yaml-example file="src/middleware.ts" :hint="__('Each schedule calls scheduled(); branch on controller.cron.')">
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
                </x-sheet.section>
            @endunless

            <x-sheet.section :title="__('From :file', ['file' => $sourcePath])">
                <x-edge-yaml-example :file="$sourcePath" :hint="__('Repo schedules show above, marked Repo. Dashboard schedules add to them on deploy.')">
crons:
  - schedule: "0 6 * * *"
    handler: "{{ $isContainer ? 'reports:daily' : 'daily' }}"
                </x-edge-yaml-example>
                <a href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}" class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline">
                    <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />{{ __('Generate :file', ['file' => $sourcePath]) }}
                </a>
            </x-sheet.section>

            <x-docs-link slug="scheduled-tasks" />
        </x-sheet.body>
    </x-sheet>

    <x-sheet name="edge-cron" maxWidth="lg" focusable>
        @if ($editingCron !== null)
            <x-sheet.header :eyebrow="__('Scheduled tasks')" :title="$editingCron === -1 ? __('Add a scheduled task') : __('Edit scheduled task')" close-wire="closeCron" />

            <form wire:submit="saveCron" class="contents">
                <x-sheet.body>
                    @if ($editingCron === -1 && $isContainer && $framework === 'laravel' && ! $scheduler)
                        <button type="button" wire:click="closeCron" x-on:click="$dispatch('edge-scheduler-add')" class="flex w-full items-center gap-3 rounded-xl border border-brand-forest/40 bg-brand-forest/5 px-3.5 py-2.5 text-left transition hover:border-brand-forest">
                            <x-heroicon-o-clock class="h-5 w-5 shrink-0 text-brand-forest dark:text-brand-sage" aria-hidden="true" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-brand-ink">{{ __('Run Laravel’s scheduler') }}</span>
                                <span class="block text-2xs text-brand-mist">{{ __('schedule:run every minute, so everything in routes/console.php runs. Usually all a Laravel app needs.') }}</span>
                            </span>
                            <span class="text-lg leading-none text-brand-mist" aria-hidden="true">›</span>
                        </button>
                        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Or a command on its own schedule') }}</p>
                    @endif
                    <x-sheet.field :label="__('When (UTC)')" for="cron-schedule" :help="\Illuminate\Support\Str::ucfirst(\App\Livewire\Sites\Edge\Workspace\Crons::describe($new_schedule))">
                        {{-- Builder: writes new_schedule; typing an expression below re-reads it. --}}
                        <div class="grid gap-2" x-data="{
                            mode: 'daily', every: 5, minute: 0, hour: 6, dow: 1, dom: 1,
                            init() { this.parse($wire.new_schedule); $wire.$watch('new_schedule', (v) => this.parse(v)); },
                            get time() { return String(this.hour).padStart(2, '0') + ':' + String(this.minute).padStart(2, '0'); },
                            set time(v) { const [h, m] = (v || '00:00').split(':'); this.hour = +h; this.minute = +m; },
                            parse(v) {
                                const f = (v || '').trim().split(/\s+/), n = (x) => /^\d+$/.test(x);
                                if (f.length !== 5) { this.mode = 'custom'; return; }
                                const [mi, h, dm, mo, dw] = f, rest = h + dm + mo + dw, m = mi.match(/^\*\/(\d+)$/);
                                if (mi === '*' && rest === '****') { this.mode = 'minutes'; this.every = 1; }
                                else if (m && rest === '****') { this.mode = 'minutes'; this.every = +m[1]; }
                                else if (n(mi) && rest === '****') { this.mode = 'hourly'; this.minute = +mi; }
                                else if (n(mi) && n(h) && dm + mo + dw === '***') { this.mode = 'daily'; this.minute = +mi; this.hour = +h; }
                                else if (n(mi) && n(h) && n(dw) && dm + mo === '**') { this.mode = 'weekly'; this.minute = +mi; this.hour = +h; this.dow = +dw % 7; }
                                else if (n(mi) && n(h) && n(dm) && mo + dw === '**') { this.mode = 'monthly'; this.minute = +mi; this.hour = +h; this.dom = +dm; }
                                else { this.mode = 'custom'; }
                            },
                            apply() {
                                const t = this.minute + ' ' + this.hour;
                                const expr = {
                                    minutes: +this.every === 1 ? '* * * * *' : '*/' + this.every + ' * * * *',
                                    hourly: this.minute + ' * * * *',
                                    daily: t + ' * * *',
                                    weekly: t + ' * * ' + this.dow,
                                    monthly: t + ' ' + this.dom + ' * *',
                                }[this.mode];
                                if (expr && expr !== $wire.new_schedule) $wire.$set('new_schedule', expr);
                            },
                        }">
                            <div role="group" class="flex flex-wrap gap-0.5 rounded-lg border border-brand-ink/10 p-0.5 dark:border-brand-mist/20">
                                @foreach (['minutes' => __('Minutes'), 'hourly' => __('Hourly'), 'daily' => __('Daily'), 'weekly' => __('Weekly'), 'monthly' => __('Monthly'), 'custom' => __('Custom')] as $mode => $label)
                                    <button type="button" x-on:click="mode = @js($mode); apply()" :aria-pressed="mode === @js($mode)" :class="mode === @js($mode) ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:text-brand-ink'" class="min-w-0 flex-1 whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-semibold transition">{{ $label }}</button>
                                @endforeach
                            </div>

                            <div class="flex flex-wrap items-center gap-2 text-xs text-brand-moss" x-show="mode !== 'custom'">
                                <template x-if="mode === 'minutes'">
                                    <label class="flex items-center gap-2">{{ __('Every') }}
                                        <select x-model.number="every" x-on:change="apply()" class="dply-input mt-0 w-auto py-1">
                                            @foreach ([1, 2, 5, 10, 15, 20, 30] as $n)
                                                <option value="{{ $n }}">{{ trans_choice(':count minute|:count minutes', $n) }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </template>
                                <template x-if="mode === 'hourly'">
                                    <label class="flex items-center gap-2">{{ __('At minute') }}
                                        <input type="number" min="0" max="59" x-model.number="minute" x-on:change="apply()" class="dply-input mt-0 w-20 py-1" />
                                        {{ __('past every hour') }}
                                    </label>
                                </template>
                                <template x-if="mode === 'weekly'">
                                    <label class="flex items-center gap-2">{{ __('On') }}
                                        <select x-model.number="dow" x-on:change="apply()" class="dply-input mt-0 w-auto py-1">
                                            @foreach ([1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 0 => __('Sunday')] as $n => $day)
                                                <option value="{{ $n }}">{{ $day }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </template>
                                <template x-if="mode === 'monthly'">
                                    <label class="flex items-center gap-2">{{ __('On day') }}
                                        <select x-model.number="dom" x-on:change="apply()" class="dply-input mt-0 w-auto py-1">
                                            @foreach (range(1, 28) as $n)
                                                <option value="{{ $n }}">{{ $n }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </template>
                                <template x-if="['daily', 'weekly', 'monthly'].includes(mode)">
                                    <label class="flex items-center gap-2">{{ __('at') }}
                                        <input type="time" step="60" x-model="time" x-on:change="apply()" class="dply-input mt-0 w-auto py-1" />
                                        UTC
                                    </label>
                                </template>
                            </div>
                        </div>
                        <input id="cron-schedule" type="text" wire:model.live.debounce.300ms="new_schedule" placeholder="*/5 * * * *" autocomplete="off" aria-label="{{ __('Cron expression') }}" class="dply-input font-mono" />
                        @error('new_schedule') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    </x-sheet.field>

                    @if ($isContainer)
                        <x-sheet.field :label="$commandLabel" for="cron-handler" :help="match ($framework) {
                            'laravel' => __('Runs in the live container from the app root. An artisan command like :artisan, or any shell command like :shell.', ['artisan' => 'reports:daily', 'shell' => 'php scripts/cleanup.php']),
                            'rails' => __('Runs in the live container from the app root. A rake task like :rake, or any shell command like :shell.', ['rake' => 'reports:daily', 'shell' => 'bin/rails runner Cleanup.call']),
                            default => __('Sent to your app as the handler in POST /_dply/schedule — your app decides what it means.'),
                        }">
                            <input id="cron-handler" type="text" wire:model.live.debounce.300ms="new_handler" placeholder="reports:daily" autocomplete="off" list="cron-app-commands" class="dply-input font-mono" />
                            @if (in_array($framework, ['laravel', 'rails'], true))
                                @if ($appCommands === null)
                                    <button type="button" wire:click="loadAppCommands" wire:loading.attr="disabled" wire:target="loadAppCommands" class="justify-self-start text-xs font-semibold text-brand-sage hover:underline disabled:opacity-50">
                                        <span wire:loading.remove wire:target="loadAppCommands">{{ $framework === 'rails' ? __('Pick from the app’s rake tasks') : __('Pick from the app’s artisan commands') }}</span>
                                        <span wire:loading wire:target="loadAppCommands">{{ __('Asking the app… (wakes it if asleep)') }}</span>
                                    </button>
                                    @if ($appCommandsError)
                                        <p class="text-xs text-rose-600">{{ $appCommandsError }}</p>
                                    @endif
                                @else
                                    @php $ownCommands = collect($appCommands)->where('app', true); @endphp
                                    <datalist id="cron-app-commands">
                                        @foreach ($appCommands as $c)
                                            <option value="{{ $c['name'] }}">{{ $c['description'] }}</option>
                                        @endforeach
                                    </datalist>
                                    @if ($ownCommands->isNotEmpty())
                                        <div class="grid gap-1">
                                            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Defined in your app') }}</p>
                                            <div class="grid max-h-48 gap-1 overflow-y-auto">
                                                @foreach ($ownCommands as $c)
                                                    <button type="button" wire:click="$set('new_handler', @js($c['name']))" @class(['flex items-baseline gap-2 rounded-lg border px-2.5 py-1.5 text-left', 'border-brand-sage bg-brand-sage/10' => $new_handler === $c['name'], 'border-brand-ink/10 hover:border-brand-ink/25 dark:border-brand-mist/20' => $new_handler !== $c['name']])>
                                                        <span class="shrink-0 font-mono text-xs text-brand-ink">{{ $c['name'] }}</span>
                                                        <span class="min-w-0 truncate text-2xs text-brand-mist">{{ $c['description'] }}</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                    <p class="text-2xs text-brand-mist">{{ trans_choice(':count command from the app; start typing to see all of them.|:count commands from the app; start typing to see all of them.', count($appCommands)) }}</p>
                                @endif
                            @endif
                        </x-sheet.field>
                    @else
                        <x-sheet.note>{{ __('Your Worker’s scheduled() handler runs; controller.cron is this expression.') }}</x-sheet.note>
                    @endif
                </x-sheet.body>

                <x-sheet.footer>
                    @if ($editingCron >= 0)
                        <x-sheet.button variant="danger" wire:click="removeEditingCron">{{ __('Remove') }}</x-sheet.button>
                    @else
                        <span>{{ __('Save, then Add for the next one. Applies on the next deploy.') }}</span>
                    @endif
                    <span class="flex gap-2">
                        <x-sheet.button wire:click="closeCron">{{ __('Cancel') }}</x-sheet.button>
                        <x-sheet.button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="saveCron">{{ __('Save') }}</x-sheet.button>
                    </span>
                </x-sheet.footer>
            </form>
        @endif
    </x-sheet>

    <x-sheet name="edge-cron-run" maxWidth="xl">
        <x-sheet.header :eyebrow="__('Scheduled tasks')" :title="__('Run :command', ['command' => (string) $runCommand])" />
        <x-sheet.body>
            {{-- The sheet opens on click; this shows until the app answers (a sleeping app wakes first). --}}
            <p wire:loading.flex wire:target="runNow,runWithArgs" class="items-center gap-2 text-sm text-brand-moss"><x-spinner size="sm" variant="muted" />{{ __('Running in the live app… (wakes it if asleep)') }}</p>
            <div wire:loading.remove wire:target="runNow,runWithArgs">
                @if ($runOutput === null)
                    <p class="inline-flex items-center gap-2 text-sm text-brand-moss"><x-spinner size="sm" variant="muted" />{{ __('Running in the live app…') }}</p>
                @else
                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap rounded-lg bg-brand-sand/15 p-3 font-mono text-xs leading-relaxed text-brand-ink dark:bg-zinc-950 dark:text-raw-zinc-200">{{ $runOutput }}</pre>
                @endif
            </div>

            {{-- The command said which arguments it is missing: ask for them. --}}
            @if ($runArgs !== [])
                <form wire:submit="runWithArgs" class="mt-4 grid gap-3">
                    <p class="text-sm text-brand-ink">{{ __(':command needs these to run:', ['command' => $runCommand]) }}</p>
                    @foreach (array_keys($runArgs) as $argName)
                        <x-sheet.field :label="$argName" :for="'run-arg-'.$argName">
                            <input id="run-arg-{{ $argName }}" type="text" wire:model="runArgs.{{ $argName }}" autocomplete="off" class="dply-input font-mono" @if ($loop->first) autofocus @endif />
                        </x-sheet.field>
                    @endforeach
                    <div class="flex flex-wrap items-center gap-2">
                        <x-sheet.button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="runWithArgs">{{ __('Run with these') }}</x-sheet.button>
                        @if ($runIsDashboardTask)
                            <x-sheet.button type="button" wire:click="saveArgsToTask">{{ __('Save to the task') }}</x-sheet.button>
                            <span class="text-2xs text-brand-mist">{{ __('so the scheduled run has them too') }}</span>
                        @endif
                    </div>
                </form>
            @endif
        </x-sheet.body>
    </x-sheet>
</div>
