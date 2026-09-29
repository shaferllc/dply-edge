@php
    $billing = $edgeSiteBilling ?? null;
    $showBilling = ($edgeUsageBillingEnabled ?? false) || (($edgeManagedFee ?? 0) > 0) || $billing !== null;
    $money = static fn (int|float $cents): string => '$'.number_format($cents / 100, 2);
@endphp

@if (! $showBilling)
    <p class="px-5 py-10 text-center text-sm text-brand-moss sm:px-6">{{ __('Billing details for this Edge site are not available yet.') }}</p>
@else
    <div>
        @if ($billing !== null)
            @php
                // Plain-language summary: this site's usage, then how it sits against the
                // workspace's included usage credit (org-wide, applied at invoice time).
                $orgState = app(\App\Modules\Billing\Services\OrganizationBillingStateComputer::class)->compute($site->organization);
                $siteCents = (int) ($billing['usage_cents'] ?? 0);
                $orgCents = max($siteCents, $orgState->usageLineCents());
                $creditCents = $orgState->usageCreditCents;
                $periodStart = $orgState->edgeUsageEstimate['period_start'] ?? null;
                $periodEnd = $orgState->edgeUsageEstimate['period_end'] ?? null;

                $items = collect([[
                    'label' => __('Delivery'),
                    'detail' => __(':r requests · :e GB egress', ['r' => number_format($billing['requests'] ?? 0), 'e' => number_format(($billing['bytes_egress'] ?? 0) / (1024 ** 3), 2)])
                        .(($billing['r2_storage_bytes'] ?? 0) > 0 ? ' · '.__(':s GB storage', ['s' => number_format($billing['r2_storage_bytes'] / (1024 ** 3), 2)]) : ''),
                    'cents' => (int) ($billing['delivery_cents'] ?? 0),
                ]])->concat($billing['lines'] ?? []);

                $top = $items->sortByDesc('cents')->first();
                $mix = match (true) {
                    $siteCents <= 0 || $top === null => '',
                    $top['cents'] >= $siteCents => __(' — all of it :label', ['label' => \Illuminate\Support\Str::lower($top['label'])]),
                    $top['cents'] * 2 > $siteCents => __(' — mostly :label', ['label' => \Illuminate\Support\Str::lower($top['label'])]),
                    default => '',
                };
                $creditSentence = match (true) {
                    $creditCents <= 0 => __('Usage is billed on the organization plan.'),
                    $orgCents <= $creditCents => __('Your :plan plan’s usage credit covers it, so you won’t be charged for it.', ['plan' => $orgState->planLabel]),
                    default => __('Your workspace is past its :plan usage credit, so usage from here is billed at the end of the period.', ['plan' => $orgState->planLabel]),
                };
                $sitePct = $creditCents > 0 ? min(100, $siteCents / $creditCents * 100) : 0;
                $othersPct = $creditCents > 0 ? min(100 - $sitePct, ($orgCents - $siteCents) / $creditCents * 100) : 0;

                $guardrail = $site->edgeGuardrail();
                $billingDaily = $billing['daily'] ?? [];
            @endphp

            <section class="space-y-8 px-5 py-8 sm:px-10 sm:py-10">
                <div>
                    <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">
                        {{ __('Usage this period') }}
                        @if ($periodStart && $periodEnd)
                            · {{ \Illuminate\Support\Carbon::parse($periodStart)->format('M j') }} – {{ \Illuminate\Support\Carbon::parse($periodEnd)->format('M j') }}
                        @endif
                    </p>
                    <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                        {{ __('This site has used') }} <span class="text-brand-sage">{{ $money($siteCents) }}</span>{{ __(' so far') }}{{ $mix }}.
                        {{ $creditSentence }}
                    </p>
                </div>

                @if ($creditCents > 0)
                    <div>
                        <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                            <span class="text-brand-moss">{{ __(':plan usage credit, whole workspace', ['plan' => $orgState->planLabel]) }}</span>
                            <span class="font-mono tabular-nums text-brand-ink">{{ $money($orgCents) }} <span class="text-brand-mist">{{ __('of :credit', ['credit' => $money($creditCents)]) }}</span></span>
                        </div>
                        <div class="mt-2 flex h-3 overflow-hidden rounded-full bg-brand-sand/80">
                            <span class="h-full bg-brand-sage" style="width: {{ $siteCents > 0 ? max(0.6, $sitePct) : 0 }}%"></span>
                            <span class="h-full bg-brand-sage/35" style="width: {{ $othersPct }}%"></span>
                        </div>
                        <div class="mt-2 flex gap-5 text-xs text-brand-moss">
                            <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm bg-brand-sage"></span>{{ __('This site') }}</span>
                            <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm bg-brand-sage/35"></span>{{ __('Other sites and projects') }}</span>
                        </div>
                    </div>
                @endif

                <div class="border-t border-brand-ink/10">
                    @foreach ($items as $item)
                        <div class="flex min-h-14 items-center gap-3 border-b border-brand-ink/10 py-3">
                            <span class="flex-1 font-medium text-brand-ink">{{ $item['label'] }}</span>
                            <span class="hidden text-sm text-brand-moss sm:inline">{{ $item['detail'] }}</span>
                            <span class="w-20 text-right font-mono tabular-nums text-brand-ink">{{ $money($item['cents']) }}</span>
                        </div>
                    @endforeach

                    <details class="group border-b border-brand-ink/10">
                        <summary class="flex min-h-14 cursor-pointer list-none items-center gap-3 py-3 [&::-webkit-details-marker]:hidden">
                            <span class="flex-1 font-medium text-brand-ink">{{ __('Monthly quota') }}</span>
                            @if ($guardrail === null)
                                <span class="text-sm text-brand-moss">{{ __('Not checked yet — runs daily') }}</span>
                            @else
                                @php
                                    $quotaState = $guardrail['state'] ?? 'ok';
                                @endphp
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                                    'bg-red-100 text-red-800 dark:bg-raw-red-950/40 dark:text-red-300' => $quotaState === 'over',
                                    'bg-amber-100 text-amber-800 dark:bg-raw-amber-950/40 dark:text-amber-300' => $quotaState === 'warn',
                                    'bg-emerald-100 text-emerald-800 dark:bg-raw-emerald-950/40 dark:text-emerald-300' => ! in_array($quotaState, ['over', 'warn'], true),
                                ])>{{ match ($quotaState) { 'over' => __('Over'), 'warn' => __('Warn'), default => __('OK') } }}</span>
                            @endif
                            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
                        </summary>
                        <div class="pb-5">
                            @include('livewire.sites.partials.edge.guardrail-card')
                        </div>
                    </details>

                    @if ($billingDaily !== [] || ($billing['daily_compute'] ?? []) !== [])
                        <details class="group border-b border-brand-ink/10">
                            <summary class="flex min-h-14 cursor-pointer list-none items-center gap-3 py-3 [&::-webkit-details-marker]:hidden">
                                <span class="flex-1 font-medium text-brand-ink">{{ __('Daily activity') }}</span>
                                <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
                            </summary>
                            <div class="space-y-6 pb-5">
                                @if ($billingDaily !== [])
                                    @include('livewire.sites.partials.edge.billing-daily-charts', ['billingDaily' => $billingDaily])
                                @endif
                                @if (($billing['daily_compute'] ?? []) !== [])
                                    <div>@include('livewire.billing.partials.edge-site-daily-compute', ['billing' => $billing])</div>
                                @endif
                            </div>
                        </details>
                    @endif
                </div>

                <p class="text-xs text-brand-moss">
                    @if ($billingDaily === [] && ! ($billing['has_snapshots'] ?? false))
                        {{ __('Daily charts appear after the first nightly collection.') }}
                    @endif
                    {{ __('Prices are before the included usage credit. Databases bill per project, on the org billing page.') }}
                </p>
            </section>
        @else
            <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Pricing') }}</p>
                <p class="mt-2 text-sm text-brand-ink">
                    {{ __('Sites have no fee. Usage is metered, and your plan’s included usage credit pays for it first.') }}
                </p>
                @if ($edgeUsageBillingEnabled ?? false)
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Delivery is metered on requests and bandwidth.') }}</p>
                    <ul class="mt-2 space-y-1 text-xs text-brand-mist">
                        @if (($edgeUsageRates['requests_per_million'] ?? 0) > 0)
                            <li>{{ __(':price / million requests', ['price' => $edgeUsageRates['requests_per_million_label']]) }}</li>
                        @endif
                        @if (($edgeUsageRates['egress_per_gb'] ?? 0) > 0)
                            <li>{{ __(':price / GB bandwidth', ['price' => $edgeUsageRates['egress_per_gb_label']]) }}</li>
                        @endif
                    </ul>
                @endif
            </section>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 px-5 py-3.5 sm:px-6">
            {{--
              Callers: Edge workspace Billing tab (sites/partials/edge/billing).
              Points at merged org billing.show — analytics + invoices live there.
            --}}
            <p class="text-xs text-brand-moss">{{ __('Compare all Edge sites and invoices for the workspace.') }}</p>
            <a
                href="{{ route('billing.show', $site->organization_id) }}"
                wire:navigate
                class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline"
            >
                {{ __('Open org billing') }}
                <x-heroicon-o-arrow-right class="h-3.5 w-3.5" />
            </a>
        </div>
    </div>
@endif
