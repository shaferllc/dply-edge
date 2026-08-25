@php
    $presets = [
        ['label' => 'Solo dev', 'hint' => '1 server', 'servers' => 1, 'edge' => 0, 'cloud' => 0, 'serverless' => 0],
        ['label' => 'Side project', 'hint' => '2 servers + 1 Edge site', 'servers' => 2, 'edge' => 1, 'cloud' => 0, 'serverless' => 0],
        ['label' => 'Small team', 'hint' => '5 servers + 2 Cloud apps', 'servers' => 5, 'edge' => 0, 'cloud' => 2, 'serverless' => 0],
        ['label' => 'Growing fleet', 'hint' => '12 servers, mixed managed', 'servers' => 12, 'edge' => 3, 'cloud' => 2, 'serverless' => 4],
    ];

    // Managed surfaces that aren't live yet render as "coming soon": the row is
    // shown but its stepper is disabled, and presets won't pre-fill a count for it.
    $surfaceAvailable = [
        'edge' => \Laravel\Pennant\Feature::active('surface.edge'),
        'cloud' => \Laravel\Pennant\Feature::active('surface.cloud'),
        'serverless' => \Laravel\Pennant\Feature::active('surface.serverless'),
    ];
@endphp

<section class="pb-16 px-4 sm:px-6 lg:px-8">
    <div class="mx-auto max-w-5xl border border-edge-line bg-edge-panel overflow-hidden">
        {{-- HERO: total at the top --}}
        <div class="px-8 py-6 bg-edge-void border-b border-edge-line">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-edge-lime">Estimate your bill</h2>
                    <div class="mt-2 flex items-baseline gap-2">
                        <span class="text-5xl font-bold tracking-tight text-edge-text" x-text="fmt(billedTotal)"></span>
                        <span class="text-lg text-edge-mute" x-text="annual ? '/yr' : '/mo'"></span>
                    </div>
                    <p class="mt-2 text-sm text-edge-mute">
                        You're on the <span class="font-semibold text-edge-text" x-text="effectivePlan.label"></span> plan.
                        <span x-show="needsPaidForManaged" x-cloak class="text-edge-lime">Managed products need a paid plan, so Free is bumped up.</span>
                    </p>
                    <p class="mt-1 text-sm text-edge-mute" x-show="!annual" x-cloak>
                        <span class="font-semibold text-edge-lime" x-text="fmt(monthlyTotal * 12 * annualPct / 100)"></span>
                        / yr saved if you switch to annual billing.
                    </p>
                    <p class="mt-1 text-sm text-edge-mute" x-show="annual" x-cloak>
                        <span x-text="fmt(billedTotal / 12)"></span> / mo effective ({{ $annualPct }}% off monthly).
                    </p>
                </div>
                <div class="inline-flex items-center gap-1 p-1 border border-edge-line bg-edge-panel">
                    <button type="button" @click="annual = false" :class="!annual ? 'bg-edge-lime text-edge-void' : 'text-edge-mute'" class="px-4 py-1.5 text-xs font-semibold transition">Monthly</button>
                    <button type="button" @click="annual = true" :class="annual ? 'bg-edge-lime text-edge-void' : 'text-edge-mute'" class="px-4 py-1.5 text-xs font-semibold transition">Yearly</button>
                </div>
            </div>
        </div>

        {{-- Presets --}}
        <div class="px-8 py-4 border-b border-edge-line flex flex-wrap items-center gap-2">
            <span class="text-xs font-semibold uppercase tracking-wider text-edge-text mr-2">Quick picks</span>
            @foreach ($presets as $preset)
                <button type="button"
                        @click="servers = {{ $preset['servers'] }}; edge = {{ $surfaceAvailable['edge'] ? $preset['edge'] : 0 }}; cloud = {{ $surfaceAvailable['cloud'] ? $preset['cloud'] : 0 }}; serverless = {{ $surfaceAvailable['serverless'] ? $preset['serverless'] : 0 }}"
                        class="inline-flex flex-col items-start border border-edge-line bg-edge-panel px-3 py-1.5 hover:border-edge-line hover:bg-edge-void transition-colors text-left">
                    <span class="text-xs font-semibold text-edge-text">{{ $preset['label'] }}</span>
                    <span class="text-2xs text-edge-mute">{{ $preset['hint'] }}</span>
                </button>
            @endforeach
            <button type="button"
                    @click="servers = 1; edge = 0; cloud = 0; serverless = 0"
                    class="inline-flex items-center px-3 py-1.5 text-xs text-edge-mute hover:text-edge-text transition-colors ml-auto">
                Reset
            </button>
        </div>

        {{-- Stepper rows --}}
        <div class="px-8 py-6 space-y-2">
            {{-- Servers drive the plan --}}
            <div class="flex items-center gap-4 bg-edge-void px-3 py-3">
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-semibold text-edge-text">BYO servers</div>
                    <div class="text-xs text-edge-mute">Sets your plan — <span x-text="effectivePlan.label"></span></div>
                </div>
                <div class="text-sm font-semibold text-edge-text tabular-nums w-24 text-right" x-text="planPrice > 0 ? fmt(planPrice) + '/mo' : 'Free'"></div>
                <div class="inline-flex items-center gap-1">
                    <button type="button"
                            @click="servers = Math.max(1, servers - 1)"
                            class="inline-flex items-center justify-center w-8 h-8 border border-edge-line bg-edge-panel text-edge-text hover:border-edge-line hover:bg-edge-void transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                            :disabled="servers <= 1">
                        <span class="text-lg leading-none">−</span>
                    </button>
                    <input type="number" min="1" step="1"
                           x-model.number="servers"
                           class="w-14 border border-edge-line bg-edge-panel px-2 py-1.5 text-sm text-center tabular-nums focus:border-edge-line focus:ring-1 focus:ring-edge-line focus:outline-none">
                    <button type="button"
                            @click="servers = servers + 1"
                            class="inline-flex items-center justify-center w-8 h-8 border border-edge-line bg-edge-panel text-edge-text hover:border-edge-line hover:bg-edge-void transition-colors">
                        <span class="text-lg leading-none">+</span>
                    </button>
                </div>
            </div>

            {{-- Managed products --}}
            @foreach ([
                ['key' => 'edge', 'label' => 'dply Edge sites', 'priceVar' => 'edgePrice', 'unit' => 'per site'],
                ['key' => 'cloud', 'label' => 'dply Cloud apps', 'priceVar' => 'cloudPrice', 'unit' => 'per app'],
                ['key' => 'serverless', 'label' => 'Serverless functions', 'priceVar' => 'serverlessPrice', 'unit' => 'per function'],
            ] as $row)
                @php $comingSoon = ! ($surfaceAvailable[$row['key']] ?? false); @endphp
                <div @class([
                    'flex items-center gap-4  transition-colors px-3 py-2',
                    'hover:bg-edge-void' => ! $comingSoon,
                    'opacity-70' => $comingSoon,
                ])>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-edge-text">{{ $row['label'] }}</span>
                            @if ($comingSoon)
                                <span class="shrink-0 rounded-full bg-edge-lime/10 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-edge-lime ring-1 ring-inset ring-edge-line">{{ __('Coming soon') }}</span>
                            @endif
                        </div>
                        <div class="text-xs text-edge-mute"><span x-text="fmt({{ $row['priceVar'] }})"></span> {{ $row['unit'] }} / mo</div>
                    </div>
                    @if ($comingSoon)
                        <div class="text-sm text-edge-mute tabular-nums w-24 text-right">—</div>
                        <div class="inline-flex items-center gap-1 opacity-50">
                            <button type="button" disabled aria-disabled="true"
                                    class="inline-flex items-center justify-center w-8 h-8 border border-edge-line bg-edge-panel text-edge-text cursor-not-allowed">
                                <span class="text-lg leading-none">−</span>
                            </button>
                            <input type="number" value="0" disabled aria-disabled="true"
                                   class="w-14 border border-edge-line bg-edge-void px-2 py-1.5 text-sm text-center tabular-nums text-edge-text cursor-not-allowed">
                            <button type="button" disabled aria-disabled="true"
                                    class="inline-flex items-center justify-center w-8 h-8 border border-edge-line bg-edge-panel text-edge-text cursor-not-allowed">
                                <span class="text-lg leading-none">+</span>
                            </button>
                        </div>
                    @else
                        <div class="text-sm font-semibold text-edge-text tabular-nums w-24 text-right" x-text="fmt(({{ $row['key'] }} || 0) * {{ $row['priceVar'] }})"></div>
                        <div class="inline-flex items-center gap-1">
                            <button type="button"
                                    @click="{{ $row['key'] }} = Math.max(0, ({{ $row['key'] }} || 0) - 1)"
                                    class="inline-flex items-center justify-center w-8 h-8 border border-edge-line bg-edge-panel text-edge-text hover:border-edge-line hover:bg-edge-void transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    :disabled="({{ $row['key'] }} || 0) === 0">
                                <span class="text-lg leading-none">−</span>
                            </button>
                            <input type="number" min="0" step="1"
                                   x-model.number="{{ $row['key'] }}"
                                   class="w-14 border border-edge-line bg-edge-panel px-2 py-1.5 text-sm text-center tabular-nums focus:border-edge-line focus:ring-1 focus:ring-edge-line focus:outline-none">
                            <button type="button"
                                    @click="{{ $row['key'] }} = ({{ $row['key'] }} || 0) + 1"
                                    class="inline-flex items-center justify-center w-8 h-8 border border-edge-line bg-edge-panel text-edge-text hover:border-edge-line hover:bg-edge-void transition-colors">
                                <span class="text-lg leading-none">+</span>
                            </button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Breakdown footer --}}
        <div class="px-8 py-5 bg-edge-void border-t border-edge-line text-sm">
            <div class="flex items-center justify-between">
                <span class="text-edge-mute"><span x-text="effectivePlan.label"></span> plan (<span x-text="servers"></span> <span x-text="servers === 1 ? 'server' : 'servers'"></span>)</span>
                <span class="tabular-nums font-semibold text-edge-text" x-text="planPrice > 0 ? fmt(planPrice) : 'Free'"></span>
            </div>
            <div class="flex items-center justify-between mt-1.5">
                <span class="text-edge-mute">Managed products</span>
                <span class="font-semibold text-edge-text tabular-nums" x-text="fmt(managedTotal)"></span>
            </div>
            <div x-show="annual" x-cloak class="flex items-center justify-between mt-1.5 text-edge-lime">
                <span>Annual discount ({{ $annualPct }}%)</span>
                <span class="font-semibold tabular-nums" x-text="'−' + fmt(monthlyTotal * 12 * annualPct / 100)"></span>
            </div>
            <p class="mt-3 text-xs text-edge-mute">Plus metered Edge delivery usage where applicable. Servers under one day old aren't counted.</p>
        </div>
    </div>
</section>
