@php
    $guardrail = $site->edgeGuardrail();
@endphp

@if ($guardrail !== null && in_array(($guardrail['state'] ?? 'ok'), ['warn', 'over'], true))
    @php
        $isOver = ($guardrail['state'] ?? 'ok') === 'over';
        $reqPct = (int) ($guardrail['requests_percent'] ?? 0);
        $bytesPct = (int) ($guardrail['bytes_percent'] ?? 0);
        $worstPct = max($reqPct, $bytesPct);
        $tone = $isOver
            ? ['border' => 'border-red-300', 'bg' => 'bg-red-50', 'icon' => 'text-red-700', 'title' => 'text-red-900', 'body' => 'text-red-800', 'badge' => 'bg-red-100 text-red-800']
            : ['border' => 'border-amber-300', 'bg' => 'bg-amber-50', 'icon' => 'text-amber-700', 'title' => 'text-amber-900', 'body' => 'text-amber-800', 'badge' => 'bg-amber-100 text-amber-800'];
    @endphp
    <div class="border-b {{ $tone['border'] }} {{ $tone['bg'] }} px-5 py-3 sm:px-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 items-start gap-3">
                @if ($isOver)
                    <x-heroicon-s-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0 {{ $tone['icon'] }}" />
                @else
                    <x-heroicon-o-exclamation-circle class="mt-0.5 h-5 w-5 shrink-0 {{ $tone['icon'] }}" />
                @endif
                <div class="min-w-0">
                    <p class="text-sm font-semibold {{ $tone['title'] }}">
                        @if ($isOver)
                            {{ __('Over monthly usage quota — this site has crossed its included limits.') }}
                        @else
                            {{ __('Approaching monthly usage quota — currently :pct% of the cap.', ['pct' => $worstPct]) }}
                        @endif
                    </p>
                    <p class="mt-0.5 text-xs {{ $tone['body'] }}">
                        {{ __('Requests :reqPct% · Bandwidth :bytesPct% of monthly cap.', ['reqPct' => $reqPct, 'bytesPct' => $bytesPct]) }}
                    </p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span class="rounded-full px-2.5 py-1 text-2xs font-semibold uppercase tracking-wide {{ $tone['badge'] }}">
                    {{ $isOver ? __('Over') : __('Warn') }}
                </span>
                <a
                    href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'billing']) }}"
                    wire:navigate
                    class="text-xs font-semibold {{ $tone['title'] }} underline hover:no-underline"
                >
                    {{ __('View usage →') }}
                </a>
            </div>
        </div>
    </div>
@endif
