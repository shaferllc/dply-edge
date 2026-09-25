<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-containers',
            'what' => __('Your app runs on Dply Edge. Pick how big each container is, how many can run at once, and how long an idle one stays warm.'),
            'steps' => [
                __('Choose an instance size that fits your app’s memory.'),
                __('Set max instances: traffic is spread across up to this many containers.'),
                __('Save and redeploy to roll the new settings out. Deploys are gradual — the previous container keeps serving until the new one is up.'),
            ],
            'tips' => [
                __('Compute is billed per second while containers run; they sleep when idle and the meter stops.'),
                __('A longer sleep timeout means fewer cold starts but more billed time.'),
                __('Queues: install dply/laravel or dply-rails and add a queue under Jobs.'),
            ],
        ])

        @if ($health)
            <div @class([
                'mt-4 rounded-xl border px-3 py-2 text-sm',
                'border-emerald-300 bg-emerald-50 text-emerald-900' => $health['ok'],
                'border-red-300 bg-red-50 text-red-900' => ! $health['ok'],
            ])>
                {{ $health['ok'] ? __('Healthy after last deploy') : __('Unhealthy after last deploy') }}
                · {{ $health['status'] !== null ? 'HTTP '.$health['status'] : ($health['error'] ?? __('no response')) }}
                · {{ __(':ms ms', ['ms' => number_format($health['ms'])]) }}
                · {{ \Illuminate\Support\Carbon::parse($health['checked_at'])->diffForHumans() }}
            </div>
        @endif

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 dark:bg-zinc-900">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Compute this month') }}</p>
                <p class="mt-1 font-mono text-lg font-semibold text-brand-ink">${{ number_format($monthCents / 100, 2) }}</p>
                <p class="text-xs text-brand-moss">{{ __(':cpu vCPU-h · :mem GiB-h', ['cpu' => number_format($cpuHours, 1), 'mem' => number_format($memoryGibHours, 1)]) }}</p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 dark:bg-zinc-900">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Per container, per minute') }}</p>
                <p class="mt-1 font-mono text-lg font-semibold text-brand-ink">${{ number_format($perMinute, 5) }}</p>
                <p class="text-xs text-brand-moss">{{ __('with every vCPU busy') }}</p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 dark:bg-zinc-900">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Worst case, per month') }}</p>
                <p class="mt-1 font-mono text-lg font-semibold text-brand-ink">${{ number_format($maxPerMonth, 2) }}</p>
                <p class="text-xs text-brand-moss">{{ trans_choice(':count instance always on|:count instances always on', $max_instances) }}</p>
                @if ($min_instances > 0)
                    <p class="text-xs text-brand-moss">{{ __('Always-on floor: $:amount/mo', ['amount' => number_format($minPerMonth, 2)]) }}</p>
                @endif
            </div>
        </div>

        <div class="mt-4 space-y-4">
            <div>
                <x-input-label :value="__('Instance size')" />
                <div class="mt-2 grid gap-2 sm:grid-cols-3" role="radiogroup">
                    @foreach ($instanceTypes as $type => [$vcpu, $memory, $disk])
                        <label @class([
                            'flex cursor-pointer flex-col rounded-xl border px-3 py-2 text-sm',
                            'border-brand-sage/50 bg-brand-sage/5' => $instance_type === $type,
                            'border-brand-ink/10 bg-white dark:bg-zinc-900' => $instance_type !== $type,
                        ])>
                            <span class="flex items-center gap-2">
                                <input type="radio" wire:model.live="instance_type" value="{{ $type }}" class="text-brand-sage" />
                                <span class="font-mono font-semibold text-brand-ink">{{ $type }}</span>
                            </span>
                            <span class="mt-0.5 text-xs text-brand-moss">{{ $vcpu < 1 ? '1/'.(int) round(1 / $vcpu) : $vcpu }} vCPU · {{ $memory }} GiB · {{ $disk }} GB</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <x-input-label for="ctr-min" :value="__('Min instances')" />
                    <x-text-input id="ctr-min" wire:model.live.debounce.400ms="min_instances" type="number" min="0" :max="$max_instances" class="mt-1 block w-full text-sm" />
                    <x-input-error :messages="$errors->get('min_instances')" class="mt-1" />
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ $min_instances > 0 ? __('Always awake. No cold starts for the first :count.', ['count' => $min_instances]) : __('0 scales to zero when idle.') }}
                    </p>
                </div>
                <div>
                    <x-input-label for="ctr-max" :value="__('Max instances')" />
                    <x-text-input id="ctr-max" wire:model.live.debounce.400ms="max_instances" type="number" min="1" max="20" class="mt-1 block w-full text-sm" />
                    <x-input-error :messages="$errors->get('max_instances')" class="mt-1" />
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('Another instance starts when each running one has :count requests in flight. The first start runs only this many. A later deploy can briefly run one extra so the new version starts before the current one stops.', ['count' => $requestsPerInstance]) }}
                    </p>
                </div>
                <div>
                    <x-input-label for="ctr-sleep" :value="__('Sleep after idle')" />
                    <select id="ctr-sleep" wire:model="sleep_after" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900">
                        @foreach ($sleepOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="ctr-jurisdiction" :value="__('Run only in')" />
                    <select id="ctr-jurisdiction" wire:model.live="jurisdiction" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900">
                        <option value="">{{ __('Anywhere (fastest)') }}</option>
                        <option value="eu">{{ __('EU only') }}</option>
                        <option value="fedramp">{{ __('US FedRAMP only') }}</option>
                    </select>
                    <p class="mt-1 text-xs text-brand-moss">
                        @if ($jurisdiction === 'eu')
                            {{ __('The app wakes only in Europe. Use this when data has to stay in the EU. Visitors outside Europe wait longer for a cold start.') }}
                        @elseif ($jurisdiction === 'fedramp')
                            {{ __('The app wakes only in the US FedRAMP locations. Use this when the workload has to stay inside that boundary.') }}
                        @else
                            {{ __('The app wakes in the nearest region. That is the fastest start after sleep.') }}
                        @endif
                    </p>
                </div>
            </div>

            <fieldset class="space-y-2">
                <div class="flex items-center justify-between gap-3">
                    <legend class="text-xs font-semibold text-brand-ink">{{ __('Scaling windows') }}</legend>
                    <x-secondary-button type="button" wire:click="addSchedule">{{ __('Add window') }}</x-secondary-button>
                </div>
                <p class="text-xs text-brand-moss">{{ __('Different min and max instances at set times, like business hours or a launch. A single date wins over a weekday, which wins over weekdays or weekends, which win over daily. Outside every window the numbers above apply.') }}</p>
                @foreach ($schedules as $i => $window)
                    <div wire:key="window-{{ $i }}" class="grid items-end gap-2 rounded-xl border border-brand-ink/10 bg-white p-3 sm:grid-cols-7 dark:bg-zinc-900">
                        <div>
                            <x-input-label :for="'win-days-'.$i" :value="__('Days')" />
                            @php($oneDate = ! in_array($window['days'] ?? '', \App\Modules\Edge\Support\EdgeContainerSettings::SCHEDULE_DAYS, true))
                            <select id="win-days-{{ $i }}" wire:model.live="schedules.{{ $i }}.days" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-2 text-sm dark:bg-zinc-900">
                                @foreach (\App\Modules\Edge\Support\EdgeContainerSettings::SCHEDULE_DAYS as $day)
                                    <option value="{{ $day }}">{{ ucfirst($day) }}</option>
                                @endforeach
                                <option value="{{ $oneDate ? $window['days'] : now()->toDateString() }}">{{ __('One date') }}</option>
                            </select>
                            @if ($oneDate)
                                <x-text-input :id="'win-date-'.$i" wire:model.live="schedules.{{ $i }}.days" type="date" class="mt-1 block w-full text-sm" />
                            @endif
                        </div>
                        <div>
                            <x-input-label :for="'win-start-'.$i" :value="__('From')" />
                            <x-text-input :id="'win-start-'.$i" wire:model="schedules.{{ $i }}.start" type="time" class="mt-1 block w-full text-sm" />
                        </div>
                        <div>
                            <x-input-label :for="'win-end-'.$i" :value="__('Until')" />
                            <x-text-input :id="'win-end-'.$i" wire:model="schedules.{{ $i }}.end" type="time" class="mt-1 block w-full text-sm" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-input-label :for="'win-tz-'.$i" :value="__('Time zone')" />
                            <x-text-input :id="'win-tz-'.$i" wire:model="schedules.{{ $i }}.timezone" list="ctr-timezones" type="text" class="mt-1 block w-full text-sm" />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <x-input-label :for="'win-min-'.$i" :value="__('Min')" />
                                <x-text-input :id="'win-min-'.$i" wire:model="schedules.{{ $i }}.min" type="number" min="0" max="20" class="mt-1 block w-full text-sm" />
                            </div>
                            <div>
                                <x-input-label :for="'win-max-'.$i" :value="__('Max')" />
                                <x-text-input :id="'win-max-'.$i" wire:model="schedules.{{ $i }}.max" type="number" min="1" max="20" class="mt-1 block w-full text-sm" />
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="button" wire:click="removeSchedule({{ $i }})" class="rounded-lg px-2 py-2 text-xs font-medium text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30">{{ __('Remove') }}</button>
                        </div>
                        @foreach (['days', 'start', 'end', 'timezone', 'min', 'max'] as $field)
                            <x-input-error :messages="$errors->get('schedules.'.$i.'.'.$field)" class="sm:col-span-7" />
                        @endforeach
                    </div>
                @endforeach
                @if ($schedules !== [])
                    <datalist id="ctr-timezones">
                        @foreach (timezone_identifiers_list() as $tz)
                            <option value="{{ $tz }}"></option>
                        @endforeach
                    </datalist>
                @endif
            </fieldset>

            <fieldset>
                <legend class="text-xs font-semibold text-brand-ink">{{ __('Regions') }}</legend>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Leave all unchecked to use every region inside the choice above.') }}</p>
                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                    @foreach (\App\Modules\Edge\Support\EdgeContainerSettings::REGIONS as $code => $label)
                        @continue($jurisdiction !== '' && ! in_array($code, \App\Modules\Edge\Support\EdgeContainerSettings::JURISDICTION_REGIONS[$jurisdiction] ?? [], true))
                        <label class="flex items-center gap-2 text-xs text-brand-ink">
                            <input type="checkbox" value="{{ $code }}" wire:model="regions" class="rounded border-brand-ink/20 text-brand-sage" />
                            <span>{{ $code }} · {{ __($label) }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="space-y-3">
                <legend class="text-xs font-semibold text-brand-ink">{{ __('Rollout') }}</legend>
                <div>
                    <x-input-label for="ctr-rollout" :value="__('How a deploy replaces instances')" />
                    <select id="ctr-rollout" wire:model.live="rollout_mode" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900">
                        <option value="gradual">{{ __('Gradual') }}</option>
                        <option value="immediate">{{ __('Immediate') }}</option>
                        <option value="none">{{ __('None') }}</option>
                    </select>
                    <p class="mt-1 text-xs text-brand-moss">
                        @if ($rollout_mode === 'immediate')
                            {{ __('Every instance moves to the new image in one step. A replaced instance is asked to stop and has 15 minutes to exit.') }}
                        @elseif ($rollout_mode === 'none')
                            {{ __('The next deploy updates Worker code only. Running instances keep the current image until you pick Gradual or Immediate.') }}
                        @else
                            {{ __('Instances move to the new image in steps. One extra instance is reserved so the new image can start before an old one stops. A replaced instance is asked to stop and has 15 minutes to exit.') }}
                        @endif
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <x-input-label for="ctr-rollout-steps" :value="__('Steps')" />
                        <x-text-input id="ctr-rollout-steps" wire:model="rollout_steps" type="text" placeholder="10, 100" class="mt-1 block w-full text-sm" />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Leave blank for the default: 100 when you run one instance, otherwise 10 then 100. The last step must be 100.') }}</p>
                        <x-input-error :messages="$errors->get('rollout_steps')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="ctr-rollout-grace" :value="__('Wait before replacing (seconds)')" />
                        <x-text-input id="ctr-rollout-grace" wire:model="rollout_active_grace_period" type="number" min="0" max="3600" class="mt-1 block w-full text-sm" />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('0 replaces an instance as soon as the rollout reaches it.') }}</p>
                    </div>
                </div>
            </fieldset>

            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="migrate_on_boot" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" />
                <span class="text-sm">
                    <span class="font-medium text-brand-ink">{{ __('Run migrations when a container starts') }}</span>
                    <span class="block text-xs text-brand-moss">{{ __('Laravel: migrate --force --isolated. Rails: db:prepare. Generated Dockerfiles only.') }}</span>
                </span>
            </label>

            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="scheduler" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" />
                <span class="text-sm">
                    <span class="font-medium text-brand-ink">{{ __('Run the Laravel scheduler every minute') }}</span>
                    <span class="block text-xs text-brand-moss">{{ __('Calls schedule:run through dply/laravel. For other jobs add a cron under Crons with an artisan command or rake task as the handler.') }}</span>
                </span>
            </label>

            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="dedicated_jobs" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" />
                <span class="text-sm">
                    <span class="font-medium text-brand-ink">{{ __('Run queued jobs and scheduled tasks on their own instance') }}</span>
                    <span class="block text-xs text-brand-moss">{{ __('Jobs never slow down web requests. Adds one instance to the bill while it runs.') }}</span>
                </span>
            </label>

            @if ($dedicated_jobs)
                <label class="ml-7 flex items-start gap-3">
                    <input type="checkbox" wire:model="jobs_always_on" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" />
                    <span class="text-sm">
                        <span class="font-medium text-brand-ink">{{ __('Keep the jobs instance awake') }}</span>
                        <span class="block text-xs text-brand-moss">{{ __('Off: it sleeps like the app and wakes for each batch. On: long-running workers are never cut off by sleep.') }}</span>
                    </span>
                </label>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="font-mono text-xs text-brand-mist">{{ $scriptName }}</p>
                <x-primary-button type="button" wire:click="save(true)" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ __('Save and redeploy') }}</span>
                    <span wire:loading wire:target="save">{{ __('Deploying…') }}</span>
                </x-primary-button>
            </div>
        </div>
    </section>

    <section class="px-5 py-4 sm:px-6">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Logs') }}</h3>
            <x-secondary-button type="button" wire:click="loadLogs" wire:loading.attr="disabled" wire:target="loadLogs">
                {{ $logs === null ? __('Load last 15 minutes') : __('Refresh') }}
            </x-secondary-button>
        </div>
        @if ($logsError)
            <p class="mt-2 text-sm text-red-700">{{ __('Could not load logs: :error', ['error' => $logsError]) }}</p>
        @elseif ($logs !== null)
            <div class="mt-2 max-h-96 overflow-auto rounded-xl border border-brand-ink/10 bg-zinc-950 p-3 font-mono text-xs leading-5 text-zinc-100">
                @forelse ($logs as $line)
                    <div @class(['text-red-300' => in_array($line['level'], ['error', 'fatal'], true), 'text-amber-200' => $line['level'] === 'warn'])>
                        <span class="text-zinc-500">{{ $line['at'] ? \Illuminate\Support\Carbon::parse($line['at'])->format('H:i:s') : '' }}</span>
                        {{ $line['message'] }}
                    </div>
                @empty
                    <p class="text-zinc-400">{{ __('No log lines in the last 15 minutes. Containers log to stdout/stderr; logs appear after the next deploy enables them.') }}</p>
                @endforelse
            </div>
        @endif
    </section>
</div>
