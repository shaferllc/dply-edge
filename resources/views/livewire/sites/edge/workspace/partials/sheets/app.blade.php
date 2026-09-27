@if ($isContainer && is_array($settings))
    @php
        $customSelected = $settings['instance_type'] === 'custom';
        $chosen = collect($sizes)->firstWhere('key', $settings['instance_type']);
        $detail = $customSelected ? $customQuote : $chosen;
        $smaller = \App\Modules\Edge\Support\EdgeContainerSettings::sizeSuggestion($site);
        $placement = $site->edgeMeta()['placement'] ?? null;
        $trial = \App\Modules\Edge\Support\EdgeTrialLimits::applies($site->organization);
    @endphp
    <x-sheet name="resources-app" maxWidth="lg">
        <x-sheet.header :eyebrow="$map['framework'] ?? __('Container')" :title="__('App')" />

        <x-sheet.body>
            <x-sheet.field :label="__('Size')" :help="$trial ? __('Applies on the next deploy. During the trial an app runs the smallest size, one instance, and sleeps after 5 minutes idle.') : __('Applies on the next deploy.')">
                <x-sheet.options>
                    @foreach ($sizes as $size)
                        @php $capped = $trial && ! \App\Modules\Edge\Support\EdgeTrialLimits::allowsContainerType($size['key']); @endphp
                        <x-sheet.option
                            wire:click="$set('draftInstanceType', '{{ $size['key'] }}')"
                            :selected="$draftInstanceType === $size['key']"
                            :disabled="$capped"
                            :title="$size['label']"
                            :description="$size['memory'].' · '.__(':second/s awake', ['second' => $size['second']])"
                            :meta="$capped ? __('Available after your trial') : __('~:price typical · :cap cap', ['price' => $size['price'], 'cap' => $size['cap']])"
                        />
                    @endforeach
                    <x-sheet.option
                        wire:click="$set('draftInstanceType', 'custom')"
                        :selected="$draftInstanceType === 'custom'"
                        :disabled="$trial"
                        :title="__('Custom')"
                        :description="__('1–4 vCPU · up to 12 GiB')"
                        :meta="$trial ? __('Available after your trial') : null"
                    />
                </x-sheet.options>
            </x-sheet.field>

            @if ($customSelected)
                <form wire:submit="saveCustom" class="grid gap-3 rounded-xl border border-brand-ink/10 p-3.5 dark:border-brand-mist/20">
                    <p class="text-2xs text-brand-mist">{{ __('At least 3 GiB of memory per vCPU. Disk at most 2 GB per GiB of memory.') }}</p>
                    <div class="grid grid-cols-3 gap-2">
                        <x-sheet.field :label="__('vCPU')" for="custom-vcpu">
                            <input id="custom-vcpu" type="number" min="1" max="4" wire:model.live.debounce.600ms="customVcpu" class="dply-input mt-0" />
                        </x-sheet.field>
                        <x-sheet.field :label="__('Memory GiB')" for="custom-memory">
                            <input id="custom-memory" type="number" min="3" max="12" wire:model.live.debounce.600ms="customMemoryGib" class="dply-input mt-0" />
                        </x-sheet.field>
                        <x-sheet.field :label="__('Disk GB')" for="custom-disk">
                            <input id="custom-disk" type="number" min="1" max="20" wire:model.live.debounce.600ms="customDiskGb" class="dply-input mt-0" />
                        </x-sheet.field>
                    </div>
                    @error('custom')
                        <x-sheet.note tone="danger">{{ $message }}</x-sheet.note>
                    @enderror
                    <div><x-sheet.button type="submit" variant="primary">{{ __('Save custom size') }}</x-sheet.button></div>
                </form>
            @endif

            @if ($smaller && $draftInstanceType !== $smaller['type'])
                <x-sheet.note tone="ok">
                    {{ __('Peak memory this week: :peak MB. :size fits with room to spare and saves $:save an hour while awake.', ['peak' => round($smaller['peak_mb']), 'size' => \App\Modules\Edge\Support\EdgeSizeLadder::containerLabel($smaller['type']), 'save' => rtrim(rtrim(number_format($smaller['save_per_hour'], 4), '0'), '.')]) }}
                    <button type="button" wire:click="selectSize('{{ $smaller['type'] }}')" class="font-semibold underline">{{ __('Use it') }}</button>
                </x-sheet.note>
            @endif

            <x-sheet.field :label="__('Instances')" :help="__('The first start runs only this many, together. A later deploy can briefly run one extra instance so the new version starts before the current one stops. Queues run on these same instances.')">
                <x-sheet.segmented>
                    @foreach ($instanceCounts as $count)
                        <x-sheet.segment wire:click="selectInstances({{ $count }})" :active="$settings['max_instances'] === $count" :disabled="$trial && $count > 1" :title="$trial && $count > 1 ? __('Available after your trial') : null">{{ $count }}</x-sheet.segment>
                    @endforeach
                </x-sheet.segmented>
            </x-sheet.field>

            <x-sheet.field :label="__('Sleeps after')">
                <x-sheet.segmented>
                    @foreach (\App\Modules\Edge\Support\EdgeContainerSettings::SLEEP_AFTER as $option)
                        <x-sheet.segment wire:click="$set('sleepAfter', '{{ $option }}')" :active="$sleepAfter === $option" :disabled="$trial && $option !== \App\Modules\Edge\Support\EdgeTrialLimits::SLEEP_AFTER" :title="$trial && $option !== \App\Modules\Edge\Support\EdgeTrialLimits::SLEEP_AFTER ? __('Available after your trial') : null">{{ $option }}</x-sheet.segment>
                    @endforeach
                </x-sheet.segmented>
            </x-sheet.field>

            @if (is_array($detail))
                <x-sheet.section :title="__('This size')">
                    <div>
                        <x-sheet.stat :label="__('vCPU')">{{ $detail['vcpu'] }}</x-sheet.stat>
                        <x-sheet.stat :label="__('Memory')">{{ $detail['memory'] }}</x-sheet.stat>
                        <x-sheet.stat :label="__('Disk')">{{ $detail['disk'] }}</x-sheet.stat>
                        <x-sheet.stat :label="__('Always on, typical')">{{ __('~:price', ['price' => $detail['price']]) }}</x-sheet.stat>
                        @isset($detail['cap'])
                            <x-sheet.stat :label="__('Monthly cap')">{{ $detail['cap'] }}</x-sheet.stat>
                        @endisset
                    </div>
                </x-sheet.section>
            @endif

            @if (is_array($quote))
                <button type="button" wire:click="openPanel('estimate')" x-on:click="$dispatch('open-modal', 'resources-estimate')" class="text-left">
                    <x-sheet.cost :label="__('Cost estimate')" :sub="__('At the awake hours you set · open for the breakdown')">{{ __(':price/mo', ['price' => $quote['awakeMonth']]) }}</x-sheet.cost>
                </button>
            @endif

            @if (is_array($placement) && ($placement['location'] ?? '') !== '')
                @php $far = ($placement['rtt_ms'] ?? 0) > \App\Modules\Edge\Services\Containers\EdgeContainerDeployer::FAR_FROM_DATABASE_MS; @endphp
                <x-sheet.note :tone="$far ? 'warn' : 'info'">
                    {{ __('Running in :location (:region), :ms ms to the database.', ['location' => $placement['location'], 'region' => $placement['region'], 'ms' => rtrim(rtrim(number_format((float) $placement['rtt_ms'], 1), '0'), '.')]) }}
                    @if ($far) {{ __('That is far: redeploy to be placed again.') }} @endif
                </x-sheet.note>
            @endif

            <x-sheet.row
                wire:click="openPanel('sleep')"
                x-on:click="$dispatch('open-modal', 'resources-sleep')"
                :title="__('Sleep, region, scheduler')"
                :hint="collect([$jurisdiction === '' ? __('Any region') : strtoupper($jurisdiction), __('rollout :mode', ['mode' => $rolloutMode]), $scheduler ? __('scheduler on') : __('scheduler off')])->implode(' · ')"
            />
        </x-sheet.body>

        <x-sheet.footer>
            <span>{{ __('Changes save as you make them and apply on the next deploy.') }}</span>
        </x-sheet.footer>
    </x-sheet>
@endif
