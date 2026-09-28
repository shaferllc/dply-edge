{{-- One site's usage this period, line by line. Callers: site Billing tab, org billing edge-site-billing-card. --}}
<table class="w-full border-t border-brand-ink/8 text-sm">
    <tbody class="divide-y divide-brand-ink/8">
        <tr>
            <td class="py-2 pr-3">
                <p class="font-medium text-brand-ink">{{ __('Delivery') }}</p>
                <p class="text-xs text-brand-mist">
                    {{ __(':r requests · :e GB egress', ['r' => number_format($billing['requests'] ?? 0), 'e' => number_format(($billing['bytes_egress'] ?? 0) / (1024 ** 3), 2)]) }}
                    @if (($billing['r2_storage_bytes'] ?? 0) > 0)
                        · {{ __(':s GB storage', ['s' => number_format($billing['r2_storage_bytes'] / (1024 ** 3), 2)]) }}
                    @endif
                </p>
            </td>
            <td class="py-2 text-right font-mono tabular-nums text-brand-ink">${{ number_format(($billing['delivery_cents'] ?? 0) / 100, 2) }}</td>
        </tr>
        @foreach ($billing['lines'] ?? [] as $line)
            <tr>
                <td class="py-2 pr-3">
                    <p class="font-medium text-brand-ink">{{ $line['label'] }}</p>
                    <p class="text-xs text-brand-mist">{{ $line['detail'] }}</p>
                </td>
                <td class="py-2 text-right font-mono tabular-nums text-brand-ink">${{ number_format($line['cents'] / 100, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
