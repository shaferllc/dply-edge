{{--
  Usage tab: daily spend (stacked compute / builds / delivery) and usage per
  app. Data from Show::usageByApp(); app rows + "Not per app" = the header
  number (billingState->usageLineCents()). Footer: credit, plan, estimated
  charge and the projected period total from Show::costForecast().
--}}
@php
    $usage = $this->usageByApp;
    $state = $this->billingState;
    $forecast = $this->costForecast;
    $cell = fn (int $cents): string => $cents === 0 ? '—' : ($cents < 0 ? '−' : '').'$'.number_format(abs($cents) / 100, 2);
    $daily = $usage['daily'];
    $maxDay = max(0.0001, collect($daily)->map(fn (array $d): float => $d['compute'] + $d['builds'] + $d['delivery'])->max() ?? 0);
    $hasSpend = collect($daily)->contains(fn (array $d): bool => $d['compute'] + $d['builds'] + $d['delivery'] > 0);
    $series = [
        'compute' => [__('Compute'), 'bg-amber-500/70'],
        'builds' => [__('Builds'), 'bg-brand-sage'],
        'delivery' => [__('Delivery'), 'bg-sky-500/70'],
    ];
    $otherCol = $usage['has_other_column'];
    $delta = $forecast['delta_vs_thirty_days_cents'] ?? null;
    $lastIdx = count($daily) - 1;
@endphp

<section class="dply-card p-5 sm:p-6" aria-labelledby="billing-daily-heading">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="billing-daily-heading" class="text-sm font-semibold text-brand-ink">{{ __('Daily spend') }}</h2>
        <div class="flex flex-wrap gap-3.5 text-xs text-brand-moss">
            @foreach ($series as [$label, $color])
                <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm {{ $color }}" aria-hidden="true"></span>{{ $label }}</span>
            @endforeach
        </div>
    </div>
    @if ($hasSpend)
        <div class="mt-4 flex h-36 items-end gap-1" role="img" aria-label="{{ __('Daily spend this period, stacked by compute, builds and delivery') }}">
            @foreach ($daily as $d)
                @php $dayTotal = $d['compute'] + $d['builds'] + $d['delivery']; @endphp
                <div class="flex h-full min-w-0 flex-1 flex-col justify-end gap-px"
                     title="{{ $d['label'] }} · ${{ number_format($dayTotal / 100, 2) }} — {{ __('compute') }} ${{ number_format($d['compute'] / 100, 2) }}, {{ __('builds') }} ${{ number_format($d['builds'] / 100, 2) }}, {{ __('delivery') }} ${{ number_format($d['delivery'] / 100, 2) }}">
                    @foreach (['builds', 'compute', 'delivery'] as $key)
                        @if ($d[$key] > 0)
                            <div @class([$series[$key][1], 'rounded-t-sm' => $key === 'builds' || ($key === 'compute' && $d['builds'] <= 0)])
                                 style="height: {{ max(2, round($d[$key] / $maxDay * 100, 2)) }}%"></div>
                        @endif
                    @endforeach
                </div>
            @endforeach
        </div>
        <div class="mt-2 flex justify-between text-2xs text-brand-mist">
            <span>{{ $daily[0]['label'] }}</span>
            @if ($lastIdx > 1)
                <span>{{ $daily[intdiv($lastIdx, 2)]['label'] }}</span>
            @endif
            @if ($lastIdx > 0)
                <span>{{ __('Today') }}</span>
            @endif
        </div>
    @else
        <p class="mt-4 py-6 text-center text-sm text-brand-moss">{{ __('No metered usage yet this period.') }}</p>
    @endif
</section>

<section class="dply-card overflow-hidden p-0" aria-labelledby="billing-apps-heading">
    <h2 id="billing-apps-heading" class="sr-only">{{ __('Usage by app') }}</h2>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">
                    <th scope="col" class="px-5 py-3 text-left font-semibold sm:px-6">
                        {{ __('App') }}
                        <span class="ml-1 normal-case tracking-normal font-normal">{{ trans_choice('(:count live)|(:count live)', $state->edgeCount, ['count' => $state->edgeCount]) }}</span>
                    </th>
                    <th scope="col" class="px-3 py-3 text-right font-semibold">{{ __('Compute') }}</th>
                    <th scope="col" class="px-3 py-3 text-right font-semibold">{{ __('Builds') }}</th>
                    <th scope="col" class="px-3 py-3 text-right font-semibold">{{ __('Delivery') }}</th>
                    @if ($otherCol)
                        <th scope="col" class="px-3 py-3 text-right font-semibold">{{ __('Other') }}</th>
                    @endif
                    <th scope="col" class="px-5 py-3 text-right font-semibold sm:px-6">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-ink/8 border-t border-brand-ink/8">
                @forelse ($usage['apps'] as $app)
                    <tr>
                        <td class="px-5 py-3 sm:px-6">
                            @if ($app['url'])
                                <a href="{{ $app['url'] }}" wire:navigate class="font-semibold text-brand-ink hover:text-brand-sage">{{ $app['name'] }}</a>
                            @else
                                <span class="font-semibold text-brand-ink">{{ $app['name'] }}</span>
                            @endif
                            <span class="block text-xs text-brand-moss">{{ $app['runtime'] }}</span>
                        </td>
                        <td class="px-3 py-3 text-right font-mono tabular-nums text-brand-ink">{{ $cell($app['compute']) }}</td>
                        <td class="px-3 py-3 text-right font-mono tabular-nums text-brand-ink">{{ $cell($app['builds']) }}</td>
                        <td class="px-3 py-3 text-right font-mono tabular-nums text-brand-ink">{{ $cell($app['delivery']) }}</td>
                        @if ($otherCol)
                            <td class="px-3 py-3 text-right font-mono tabular-nums text-brand-ink" title="{{ __('Valkey and realtime') }}">{{ $cell($app['other']) }}</td>
                        @endif
                        <td class="px-5 py-3 text-right font-mono font-bold tabular-nums text-brand-ink sm:px-6">{{ $cell($app['total']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $otherCol ? 6 : 5 }}" class="px-5 py-6 text-center text-sm text-brand-moss sm:px-6">{{ __('No live apps yet.') }}</td>
                    </tr>
                @endforelse
                @if ($usage['other']['total'] !== 0 || $usage['other']['detail'] !== [])
                    <tr>
                        <td class="px-5 py-3 sm:px-6">
                            <span class="font-semibold text-brand-ink">{{ __('Not per app') }}</span>
                            <span class="block text-xs text-brand-moss">{{ implode(' · ', $usage['other']['detail']) }}</span>
                        </td>
                        <td class="px-3 py-3 text-right font-mono tabular-nums text-brand-ink">{{ $cell($usage['other']['compute']) }}</td>
                        <td class="px-3 py-3 text-right font-mono tabular-nums text-brand-ink">{{ $cell($usage['other']['builds']) }}</td>
                        <td class="px-3 py-3 text-right font-mono tabular-nums text-brand-ink">{{ $cell($usage['other']['delivery']) }}</td>
                        @if ($otherCol)
                            <td class="px-3 py-3 text-right font-mono tabular-nums text-brand-ink">{{ $cell($usage['other']['other']) }}</td>
                        @endif
                        <td class="px-5 py-3 text-right font-mono font-bold tabular-nums text-brand-ink sm:px-6">{{ $cell($usage['other']['total']) }}</td>
                    </tr>
                @endif
            </tbody>
            <tfoot class="border-t border-brand-ink/15 bg-brand-sand/25">
                @php $span = $otherCol ? 5 : 4; @endphp
                <tr>
                    <th scope="row" colspan="{{ $span }}" class="px-5 py-2.5 text-left font-semibold text-brand-ink sm:px-6">{{ __('All usage this period') }}</th>
                    <td class="px-5 py-2.5 text-right font-mono font-bold tabular-nums text-brand-ink sm:px-6">${{ number_format($usage['total_cents'] / 100, 2) }}</td>
                </tr>
                @if ($state->creditAppliedCents() > 0)
                    <tr>
                        <th scope="row" colspan="{{ $span }}" class="px-5 py-1.5 text-left font-normal text-brand-moss sm:px-6">{{ __('Included usage credit (:plan includes :credit)', ['plan' => $state->planLabel, 'credit' => '$'.number_format($state->usageCreditCents / 100, 2)]) }}</th>
                        <td class="px-5 py-1.5 text-right font-mono tabular-nums text-brand-ink sm:px-6">−${{ number_format($state->creditAppliedCents() / 100, 2) }}</td>
                    </tr>
                @endif
                @if ($state->planPriceCents > 0)
                    <tr>
                        <th scope="row" colspan="{{ $span }}" class="px-5 py-1.5 text-left font-normal text-brand-moss sm:px-6">{{ __(':plan plan', ['plan' => $state->planLabel]) }}</th>
                        <td class="px-5 py-1.5 text-right font-mono tabular-nums text-brand-ink sm:px-6">${{ number_format($state->planPriceCents / 100, 2) }}</td>
                    </tr>
                @endif
                @if ($state->extraSeatCount > 0)
                    <tr>
                        <th scope="row" colspan="{{ $span }}" class="px-5 py-1.5 text-left font-normal text-brand-moss sm:px-6">{{ trans_choice(':count extra seat|:count extra seats', $state->extraSeatCount, ['count' => $state->extraSeatCount]) }}</th>
                        <td class="px-5 py-1.5 text-right font-mono tabular-nums text-brand-ink sm:px-6">${{ number_format($state->extraSeatSubtotalCents / 100, 2) }}</td>
                    </tr>
                @endif
                <tr class="border-t border-brand-ink/10">
                    <th scope="row" colspan="{{ $span }}" class="px-5 py-2.5 text-left font-semibold text-brand-ink sm:px-6">
                        {{ __('Estimated charge so far') }}
                        <span class="block text-xs font-normal text-brand-moss">{{ __('Usage is invoiced after the period ends, less the included credit.') }}</span>
                    </th>
                    <td class="px-5 py-2.5 text-right font-mono font-bold tabular-nums text-brand-ink sm:px-6">${{ number_format($state->monthlyTotalCents / 100, 2) }}</td>
                </tr>
                <tr>
                    <th scope="row" colspan="{{ $span }}" class="px-5 py-2.5 text-left font-semibold text-brand-ink sm:px-6">
                        {{ __('Projected this period') }}
                        <span class="block text-xs font-normal text-brand-moss">
                            {{ __('Usage so far run out to :date.', ['date' => \Illuminate\Support\Carbon::parse((string) ($forecast['period_end'] ?? now()->addMonth()->toDateString()))->format('M j')]) }}
                            @if (is_int($delta))
                                {{ __(':delta vs 30 days ago.', ['delta' => ($delta >= 0 ? '+' : '−').'$'.number_format(abs($delta) / 100, 2)]) }}
                            @endif
                        </span>
                    </th>
                    <td class="px-5 py-2.5 text-right font-mono font-bold tabular-nums text-brand-ink sm:px-6">${{ number_format(((int) ($forecast['projected_month_end_cents'] ?? 0)) / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>
