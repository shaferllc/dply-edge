{{-- Valkey size and sleep, edited in the resources-valkey sheet. Needs $connection. --}}
@php
    $vkClass = \App\Modules\Edge\Support\EdgeValkey::CLASSES[$connection['plan']] ?? \App\Modules\Edge\Support\EdgeValkey::CLASSES[\App\Modules\Edge\Support\EdgeValkey::DEFAULT_CLASS];
    $vkSleep = (int) ($site->edgeMeta()['valkey_sleep'][$connection['target']] ?? ($vkClass['sleeps'] ? \App\Modules\Edge\Support\EdgeValkey::DEFAULT_SLEEP : 0));
@endphp
@php
    $vkPlan = isset(\App\Modules\Edge\Support\EdgeValkey::CLASSES[$connection['plan']]) ? $connection['plan'] : \App\Modules\Edge\Support\EdgeValkey::DEFAULT_CLASS;
@endphp
<div class="grid gap-5" wire:key="valkey-card-{{ md5($connection['host']) }}">
    <x-sheet.field :label="__('Size')">
        <x-sheet.options id="valkey-size-sheet">
            @foreach (\App\Modules\Edge\Support\EdgeValkey::offered() as $classId => $class)
                <x-sheet.option
                    wire:click="saveValkey('{{ $connection['host'] }}', '{{ $classId }}', {{ $vkSleep }})"
                    wire:loading.attr="disabled"
                    wire:target="saveValkey"
                    :selected="$vkPlan === $classId"
                    :title="__($class['label'])"
                    :meta="__('up to $:price/mo', ['price' => number_format($class['cap_cents'] / 100, 0)])"
                />
            @endforeach
        </x-sheet.options>
    </x-sheet.field>
    <x-sheet.field :label="__('Sleep')">
        @if ($vkClass['sleeps'])
            <x-sheet.segmented id="valkey-sleep-sheet">
                @foreach (\App\Modules\Edge\Support\EdgeValkey::SLEEPS as $seconds => $label)
                    <x-sheet.segment
                        wire:click="saveValkey('{{ $connection['host'] }}', '{{ $vkPlan }}', {{ $seconds }})"
                        wire:loading.attr="disabled"
                        wire:target="saveValkey"
                        :active="$vkSleep === $seconds"
                    >{{ $seconds > 0 ? __('After :time idle', ['time' => __($label)]) : __($label) }}</x-sheet.segment>
                @endforeach
            </x-sheet.segmented>
        @else
            <p class="text-sm font-semibold text-brand-ink">{{ __('Stays on (Pro sizes do not sleep)') }}</p>
        @endif
    </x-sheet.field>
</div>
