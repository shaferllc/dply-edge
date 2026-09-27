{{-- AI, Browser and vector search usage this billing period vs the org's cap (EdgeMeter). Expects $service: ai|browser|vectors. --}}
@php
    $meterOrg = $site->organization;
    $meterSpent = $meterOrg ? \App\Modules\Edge\Support\EdgeMeter::spent($meterOrg) : ['cents' => 0, 'services' => []];
    $meterCap = $meterOrg ? \App\Modules\Edge\Support\EdgeMeter::effectiveCapCents($meterOrg) : 0;
    $meterMoney = static fn (int $cents): string => '$'.number_format($cents / 100, 2);
@endphp
<x-sheet.metrics :cols="2">
    <x-sheet.metric :label="__('This period')" :note="__('This organization, all apps. Billed with usage.')">{{ $meterMoney((int) ($meterSpent['services'][$service] ?? 0)) }}</x-sheet.metric>
    <x-sheet.metric :label="__('Limit')" :note="__('AI, browser and vector search together: :used used. Owners change it on the billing page.', ['used' => $meterMoney($meterSpent['cents'])])">{{ $meterMoney($meterCap) }}</x-sheet.metric>
</x-sheet.metrics>
@if ($meterCap > 0 && $meterSpent['cents'] >= $meterCap)
    <x-sheet.note tone="warn">{{ __('The limit is reached. Calls are refused until the next billing period or until an owner raises it.') }}</x-sheet.note>
@elseif (! \App\Modules\Edge\Support\EdgeMeter::enabled($service))
    <x-sheet.note tone="warn">{{ __('Turned off on dply for now. Calls are refused.') }}</x-sheet.note>
@endif
