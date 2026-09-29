<x-sheet name="resources-sleep" :show="$panel === 'sleep'" maxWidth="lg" focusable>
    <form wire:submit="saveRuntime" class="contents">
        <x-sheet.header :eyebrow="__('App')" :title="__('Sleep, scaling, region')" close-wire="openPanel('')">
            {{ __('These apply on the next deploy. Size and instance count stay on the canvas.') }}
        </x-sheet.header>

        <x-sheet.body>
            @php $trialCap = \App\Modules\Edge\Support\EdgeTrialLimits::applies($site->organization); @endphp
            <x-sheet.field :label="__('Sleep after idle')" :help="$trialCap ? __('During the trial an app sleeps after 5 minutes idle.') : null">
                <x-sheet.segmented>
                    @foreach (\App\Modules\Edge\Support\EdgeContainerSettings::SLEEP_AFTER as $option)
                        <x-sheet.segment wire:click="$set('sleepAfter', '{{ $option }}')" :active="$sleepAfter === $option" :disabled="$trialCap && $option !== \App\Modules\Edge\Support\EdgeTrialLimits::SLEEP_AFTER" :title="$trialCap && $option !== \App\Modules\Edge\Support\EdgeTrialLimits::SLEEP_AFTER ? __('Available after your trial') : null">{{ $option }}</x-sheet.segment>
                    @endforeach
                </x-sheet.segmented>
                <x-input-error :messages="$errors->get('sleepAfter')" />
            </x-sheet.field>

            <x-sheet.field :label="__('Run only in')">
                <x-sheet.options>
                    @foreach (['' => [__('Anywhere (fastest)'), __('The app wakes in the nearest region. That is the fastest start after sleep.')], 'eu' => [__('EU only'), __('The app wakes only in Europe. Use this when data has to stay in the EU. Visitors outside Europe wait longer for a cold start.')], 'fedramp' => [__('US FedRAMP only'), __('The app wakes only in the US FedRAMP locations. Use this when the workload has to stay inside that boundary.')]] as $value => [$title, $description])
                        <x-sheet.option wire:click="$set('jurisdiction', '{{ $value }}')" :selected="$jurisdiction === $value" :title="$title" :description="$description" />
                    @endforeach
                </x-sheet.options>
                <x-input-error :messages="$errors->get('jurisdiction')" />
            </x-sheet.field>

            @php $nearData = $jurisdiction === '' && $regions === [] ? \App\Modules\Edge\Support\EdgeContainerSettings::dataRegion($site) : null; @endphp
            <x-sheet.section :title="__('Regions')">
                <p class="text-2xs leading-4 text-brand-mist">
                    @if ($nearData)
                        {{ __('Unchecked, this app runs in :region, next to its dply database and Valkey: each query is a short round trip instead of one across the continent. Check regions to choose yourself.', ['region' => $nearData.' · '.__(\App\Modules\Edge\Support\EdgeContainerSettings::REGIONS[$nearData])]) }}
                    @else
                        {{ __('Leave all unchecked to use every region inside the choice above.') }}
                    @endif
                </p>
                <div class="grid gap-1.5 sm:grid-cols-2">
                    @foreach (\App\Modules\Edge\Support\EdgeContainerSettings::REGIONS as $code => $label)
                        @continue($jurisdiction !== '' && ! in_array($code, \App\Modules\Edge\Support\EdgeContainerSettings::JURISDICTION_REGIONS[$jurisdiction] ?? [], true))
                        @php $on = in_array($code, $regions, true); @endphp
                        <label @class([
                            'flex cursor-pointer items-center gap-2 rounded-lg border px-2.5 py-2 text-xs transition',
                            'border-brand-forest bg-brand-forest/5 text-brand-ink' => $on,
                            'border-brand-ink/10 text-brand-moss hover:border-brand-ink/30 dark:border-brand-mist/20' => ! $on,
                        ])>
                            <input type="checkbox" value="{{ $code }}" wire:model.live="regions" class="rounded border-brand-ink/20 text-brand-forest focus:ring-brand-forest/40" />
                            <span class="font-mono font-semibold">{{ $code }}</span>
                            <span class="truncate">{{ __($label) }}</span>
                        </label>
                    @endforeach
                </div>
            </x-sheet.section>

            <x-sheet.section :title="__('Rollout')">
                <x-sheet.field :label="__('How a deploy replaces instances')">
                    <x-sheet.segmented>
                        @foreach (['gradual' => __('Gradual'), 'immediate' => __('Immediate'), 'none' => __('None')] as $mode => $label)
                            <x-sheet.segment wire:click="$set('rolloutMode', '{{ $mode }}')" :active="$rolloutMode === $mode">{{ $label }}</x-sheet.segment>
                        @endforeach
                    </x-sheet.segmented>
                    <p class="text-2xs leading-4 text-brand-mist">
                        @if ($rolloutMode === 'immediate')
                            {{ __('Every instance moves to the new image in one step. A replaced instance is asked to stop and has 15 minutes to exit.') }}
                        @elseif ($rolloutMode === 'none')
                            {{ __('The next deploy updates Worker code only. Running instances keep the current image until you pick Gradual or Immediate.') }}
                        @else
                            {{ __('Instances move to the new image in steps. One extra instance is reserved so the new image can start before an old one stops. A replaced instance is asked to stop and has 15 minutes to exit.') }}
                        @endif
                    </p>
                </x-sheet.field>
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-sheet.field :label="__('Steps')" for="res-rollout-steps" :help="__('Leave blank for the default: 100 when you run one instance, otherwise 10 then 100. Each number is the percent of instances on the new image. The last step must be 100.')">
                        <input id="res-rollout-steps" type="text" wire:model.live.debounce.600ms="rolloutSteps" placeholder="10, 100" class="dply-input mt-0 font-mono" />
                        <x-input-error :messages="$errors->get('rolloutSteps')" />
                    </x-sheet.field>
                    <x-sheet.field :label="__('Wait before replacing (seconds)')" for="res-rollout-grace" :help="__('0 replaces an instance as soon as the rollout reaches it. A higher number leaves it alone until it has been connected that long.')">
                        <input id="res-rollout-grace" type="number" min="0" max="3600" wire:model.live.debounce.600ms="rolloutGraceSeconds" class="dply-input mt-0" />
                        <x-input-error :messages="$errors->get('rolloutGraceSeconds')" />
                    </x-sheet.field>
                </div>
            </x-sheet.section>

            <x-sheet.section :title="__('Always awake and scaling windows')">
                <x-sheet.field :label="__('Always awake')" for="sheet-min-instances" :help="$minInstances > 0 ? __('The first :count never sleep, so they never cold start. Billed while awake.', ['count' => $minInstances]) : __('0: every instance sleeps when idle.')">
                    <input id="sheet-min-instances" type="number" min="0" max="{{ $draftMaxInstances }}" wire:model.live.debounce.400ms="minInstances" class="dply-input mt-0 w-24" @disabled($trialCap) />
                    <x-input-error :messages="$errors->get('minInstances')" />
                </x-sheet.field>
                <div class="space-y-2">
                    <p class="text-2xs leading-4 text-brand-mist">{{ __('Different always-awake and instance counts at set times, like business hours or a launch. A single date wins over a weekday, which wins over weekdays or weekends, which win over daily.') }}</p>
                    @foreach ($schedules as $i => $window)
                        @php $oneDate = ! in_array($window['days'] ?? '', \App\Modules\Edge\Support\EdgeContainerSettings::SCHEDULE_DAYS, true); @endphp
                        <div wire:key="sheet-window-{{ $i }}" class="grid grid-cols-2 gap-2 rounded-lg border border-brand-ink/10 p-2.5 sm:grid-cols-6">
                            <select wire:model.live="schedules.{{ $i }}.days" class="dply-input mt-0 text-xs sm:col-span-2" aria-label="{{ __('Days') }}">
                                @foreach (\App\Modules\Edge\Support\EdgeContainerSettings::SCHEDULE_DAYS as $day)
                                    <option value="{{ $day }}">{{ ucfirst($day) }}</option>
                                @endforeach
                                <option value="{{ $oneDate ? $window['days'] : now()->toDateString() }}">{{ __('One date') }}</option>
                            </select>
                            <input type="time" wire:model.live.debounce.500ms="schedules.{{ $i }}.start" class="dply-input mt-0 text-xs" aria-label="{{ __('From') }}" />
                            <input type="time" wire:model.live.debounce.500ms="schedules.{{ $i }}.end" class="dply-input mt-0 text-xs" aria-label="{{ __('Until') }}" />
                            <input type="number" min="0" max="20" wire:model.live.debounce.500ms="schedules.{{ $i }}.min" class="dply-input mt-0 text-xs" aria-label="{{ __('Always awake') }}" title="{{ __('Always awake') }}" />
                            <input type="number" min="1" max="20" wire:model.live.debounce.500ms="schedules.{{ $i }}.max" class="dply-input mt-0 text-xs" aria-label="{{ __('Instances') }}" title="{{ __('Instances') }}" />
                            @if ($oneDate)
                                <input type="date" wire:model.live="schedules.{{ $i }}.days" class="dply-input mt-0 text-xs sm:col-span-2" aria-label="{{ __('Date') }}" />
                            @endif
                            <input type="text" wire:model.live.debounce.800ms="schedules.{{ $i }}.timezone" class="dply-input mt-0 text-xs sm:col-span-3" aria-label="{{ __('Time zone') }}" placeholder="America/Los_Angeles" />
                            <button type="button" wire:click="removeSchedule({{ $i }})" class="text-xs font-medium text-rose-600 hover:underline sm:col-span-1 dark:text-rose-300">{{ __('Remove') }}</button>
                            @foreach (['days', 'start', 'end', 'timezone', 'min', 'max'] as $field)
                                <x-input-error :messages="$errors->get('schedules.'.$i.'.'.$field)" class="col-span-full" />
                            @endforeach
                        </div>
                    @endforeach
                    <x-sheet.button type="button" wire:click="addSchedule" :disabled="$trialCap">{{ __('Add a window') }}</x-sheet.button>
                </div>
            </x-sheet.section>

            <x-sheet.section :title="__('Behaviour')">
                <div>
                    <x-sheet.toggle wire:model.live="stickySessions" :label="__('Keep a visitor on the same instance')" :help="__('Uses a cookie so sessions and websockets stay on one container. Turn this off to spread every request at random.')" />
                    <x-sheet.toggle wire:model.live="dedicatedJobs" :label="__('Run jobs on their own instance')" :help="__('Off, queues stay on the same instances as the site. On, they use one extra instance. Queue workers run background work on their own.')" />
                    @if ($dedicatedJobs)
                        <x-sheet.toggle wire:model.live="jobsAlwaysOn" :label="__('Keep the jobs instance awake')" :help="__('Off: it sleeps like the app and wakes for each batch. On: long-running workers are never cut off by sleep.')" />
                    @endif
                    @php $workerModeSupported = (bool) ($site->edgeMeta()['worker_mode_supported'] ?? false); @endphp
                    <x-sheet.toggle wire:model.live="workerMode" :disabled="! $workerModeSupported && ! $workerMode" :label="__('Worker mode (Octane on FrankenPHP)')" :help="$workerModeSupported ? __('Keeps the app booted between requests. State carries over between requests, so the app must be Octane-safe. Each worker restarts after 500 requests.') : __('Needs laravel/octane in composer.json and FrankenPHP pinned with extra.dply.php-server set to frankenphp. Checked on each deploy.')" />
                    <x-input-error :messages="$errors->get('workerMode')" />
                    <x-sheet.toggle wire:model.live="scheduler" :label="__('Run the Laravel scheduler every minute')" :help="__('With queue workers it runs in worker-0 (schedule:work) and the app can sleep. Without them, a Cron Trigger calls schedule:run in the app every minute.')" />
                    <x-sheet.toggle wire:model.live="migrateOnBoot" :label="__('Run migrations when a container starts')" :help="__('Laravel: migrate --force --isolated. Rails: db:prepare.')" />
                </div>
            </x-sheet.section>
        </x-sheet.body>

        <x-sheet.footer>
            <span>{{ __('Saved as you change it. Applies on the next deploy.') }}</span>
            <x-sheet.button x-on:click="$dispatch('close-modal', 'resources-sleep')" wire:click="openPanel('')">{{ __('Done') }}</x-sheet.button>
        </x-sheet.footer>
    </form>
</x-sheet>
