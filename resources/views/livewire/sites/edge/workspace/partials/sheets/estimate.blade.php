@if (is_array($quote) && is_array($settings))
    <x-sheet name="resources-estimate" :show="$panel === 'estimate'" maxWidth="lg" focusable>
        <x-sheet.header :eyebrow="__('App')" :title="__('Cost estimate')" close-wire="openPanel('')">
            {{ __('While it is running, for :count. This is an estimate, not your bill. Monthly figures assume a typical 25% of each vCPU busy; the running rate assumes all of it. Sleeping time is not billed, and changing the hours does not change the app.', ['count' => trans_choice(':count instance|:count instances', $settings['max_instances'])]) }}
        </x-sheet.header>

        <x-sheet.body>
            <x-sheet.field :label="__('Hours awake each day')" for="estimate-awake">
                <input id="estimate-awake" type="number" min="0" max="24" wire:model.live="awakeHours" class="dply-input mt-0 max-w-32" />
            </x-sheet.field>

            <div class="grid gap-2 sm:grid-cols-2">
                <x-sheet.cost :label="__('This schedule')">{{ __(':price/mo', ['price' => $quote['awakeMonth']]) }}</x-sheet.cost>
                <x-sheet.cost :label="__('Saved by sleeping')">{{ __(':price/mo', ['price' => $quote['saved']]) }}</x-sheet.cost>
            </div>

            <x-sheet.section :title="__('Running rate')">
                <div>
                    <x-sheet.stat :label="__('Per second')">{{ $quote['second'] }}</x-sheet.stat>
                    <x-sheet.stat :label="__('Per minute')">{{ $quote['minute'] }}</x-sheet.stat>
                    <x-sheet.stat :label="__('Per hour')">{{ $quote['hour'] }}</x-sheet.stat>
                    <x-sheet.stat :label="__('Per day')">{{ $quote['day'] }}</x-sheet.stat>
                    <x-sheet.stat :label="__('Always on, typical')">{{ __(':price/mo', ['price' => $quote['month']]) }}</x-sheet.stat>
                    <x-sheet.stat :label="__('Monthly cap')">{{ __(':price/mo', ['price' => $quote['cap']]) }}</x-sheet.stat>
                    <x-sheet.stat :label="__('Instances')">{{ $settings['max_instances'] }}</x-sheet.stat>
                </div>
            </x-sheet.section>

            <x-sheet.note>{{ __('Always on is 720 hours a month at typical CPU. This schedule is that price times the hours awake out of 24. Saved by sleeping is the difference. However busy the CPU, an instance never bills more than the monthly cap.') }}</x-sheet.note>
        </x-sheet.body>
    </x-sheet>
@endif
