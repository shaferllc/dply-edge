<div @if ($running) wire:poll.750ms @endif>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <h2 class="text-base font-semibold text-brand-ink">{{ __('Console') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Run a command in one of this app\'s containers and watch its output. Each run is recorded in the app\'s activity.') }}</p>
    </section>

    @if (! $flagOn)
        <p class="px-5 py-6 text-sm text-brand-moss sm:px-6">{{ __('The Console isn\'t available for this organization yet.') }}</p>
    @elseif (! $agentWanted)
        <div class="px-5 py-6 text-sm text-brand-moss sm:px-6">
            <p>{{ __('The dply agent is turned off for this app, so the Console can\'t reach its containers.') }}</p>
            @if ($canRun)
                <x-secondary-button size="sm" class="mt-3" wire:click="setAgent(true)">{{ __('Turn it on (applies on the next deploy)') }}</x-secondary-button>
            @endif
        </div>
    @elseif (! $agentLive)
        <p class="px-5 py-6 text-sm text-brand-moss sm:px-6">{{ __('The live deploy was built before the Console existed. Redeploy this app, then come back.') }}</p>
    @else
        <form wire:submit="run" class="space-y-3 border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            <div class="flex flex-wrap items-center gap-2">
                <select wire:model="target" class="dply-input mt-0 w-auto" aria-label="{{ __('Container') }}" @disabled(! $canRun)>
                    @foreach ($targets as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <input type="text" wire:model="command" placeholder="php artisan about" class="dply-input mt-0 min-w-0 flex-1 font-mono text-sm" aria-label="{{ __('Command') }}" autocomplete="off" spellcheck="false" @disabled(! $canRun) />
                <x-primary-button type="submit" size="sm" :disabled="! $canRun || $running">{{ $running ? __('Running…') : __('Run') }}</x-primary-button>
            </div>
            <x-input-error :messages="$errors->get('command')" />
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-brand-moss">
                <label class="inline-flex items-center gap-1.5">
                    <input type="checkbox" wire:model="wake" class="rounded border-brand-ink/20" />
                    {{ __('Start the container if it\'s asleep (it bills while awake)') }}
                </label>
                <label class="inline-flex items-center gap-1.5">
                    {{ __('Stop after') }}
                    <input type="number" wire:model="timeout" min="5" max="3600" class="dply-input mt-0 w-20 py-1 text-xs" />
                    {{ __('seconds') }}
                </label>
            </div>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($suggestions as $suggestion)
                    <button type="button" wire:click="useSuggestion(@js($suggestion))" class="rounded-md border border-brand-ink/10 px-2 py-0.5 font-mono text-2xs text-brand-moss hover:border-brand-sage/50 hover:text-brand-ink">{{ $suggestion }}</button>
                @endforeach
            </div>
        </form>

        @if ($run)
            <div class="px-5 py-4 sm:px-6">
                <p class="font-mono text-xs text-brand-moss">$ {{ $run['command'] }} <span class="text-brand-mist">· {{ $targets[$run['target']] ?? $run['target'] }}</span></p>
                @php($output = collect($run['lines'])->map(fn (array $line): string => isset($line['err']) ? '<span class="text-amber-300">'.e($line['err']).'</span>' : e($line['out'] ?? ''))->implode(''))
                <pre class="mt-2 max-h-[32rem] min-h-24 overflow-auto rounded-lg bg-brand-ink px-4 py-3 font-mono text-2xs leading-relaxed text-brand-cream">{!! $output !!}</pre>
                <p class="mt-2 text-xs">
                    @switch($run['status'])
                        @case('queued')
                        @case('running')
                            <span class="text-brand-moss">{{ __('Running…') }}</span>
                            @break
                        @case('asleep')
                            <span class="text-brand-moss">{{ __('The container is asleep, so nothing ran. Tick "Start the container" to wake it.') }}</span>
                            @break
                        @case('failed')
                            <span class="font-semibold text-rose-700 dark:text-rose-300">{{ $run['error'] }}</span>
                            @break
                        @default
                            @php($result = $run['result'])
                            <span @class(['font-semibold', 'text-emerald-700 dark:text-emerald-300' => $result['exit'] === 0, 'text-rose-700 dark:text-rose-300' => $result['exit'] !== 0])>{{ __('Exit :code', ['code' => $result['exit']]) }}</span>
                            <span class="text-brand-moss">· {{ number_format((float) $result['seconds'], 1) }}s
                                @if ($result['timed_out'] ?? false) · {{ __('stopped at the time limit') }} @endif
                                @if ($result['truncated'] ?? false) · {{ __('output cut at 1 MB') }} @endif
                            </span>
                    @endswitch
                </p>
            </div>
        @endif

        @if ($canRun)
            <p class="border-t border-brand-ink/10 px-5 py-3 text-xs text-brand-mist sm:px-6">
                {{ __('The Console works through the dply agent, a small process dply adds to this app\'s image.') }}
                <button type="button" wire:click="setAgent(false)" wire:confirm="{{ __('Turn the dply agent off? The next deploy builds without it and the Console stops working.') }}" class="underline underline-offset-2 hover:text-brand-ink">{{ __('Turn it off') }}</button>
            </p>
        @endif
    @endif
</div>
