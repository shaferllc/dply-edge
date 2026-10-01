{{-- A small live stage inside a WHAT_RUNS_HERE card, run by
     resources/js/welcome-motion.js. Decorative — the card text already says what
     it shows — so the canvas stages are simply blank under reduced motion. --}}
<div aria-hidden="true" class="font-terminal mt-5 h-36 overflow-hidden border border-edge-line bg-edge-panel p-3 text-[10px] leading-4 text-edge-dim">
    @switch($stage)
        @case('SITES')
            <div data-motion="sites" class="space-y-1.5">
                @foreach ([
                    ['⎇', 'main → storefront.com', 'production', 'text-edge-lime'],
                    ['⎇', 'feat/checkout', 'preview', 'text-edge-lime'],
                    ['↺', 'v42 · v41 · v40', 'v42 live', 'text-edge-faint'],
                ] as [$icon, $text, $pill, $tone])
                    <div data-row class="flex justify-between gap-2 border border-edge-line px-2 py-1.5 transition-colors">
                        <span class="truncate text-edge-text">{{ $icon }} <span data-text>{{ $text }}</span></span>
                        <span data-pill class="shrink-0 {{ $tone }}">{{ $pill }}</span>
                    </div>
                @endforeach
            </div>
            @break

        @case('SERVER_APPS')
            <div data-motion="apps" class="flex h-full flex-col gap-2">
                <div class="flex justify-between"><span><span data-rps class="tabular-nums text-edge-text">612</span> req/s</span><span data-inst class="text-edge-text">3 running</span></div>
                <canvas class="min-h-0 w-full flex-1"></canvas>
                <div class="flex gap-1">
                    @foreach (range(1, 5) as $n)
                        <span data-pod class="h-3 flex-1 border border-edge-line transition-colors duration-500" @if ($n <= 3) style="background: var(--color-edge-lime)" @endif></span>
                    @endforeach
                </div>
            </div>
            @break

        @case('DATA')
            <div data-motion="data" class="flex h-full flex-col gap-2">
                <p class="min-h-8 break-words"><span class="text-edge-lime">›</span> <span data-query class="text-edge-text">select status, count(*) from orders group by 1;</span></p>
                <ul data-result class="flex-1 space-y-0.5">
                    <li class="flex justify-between"><span>paid</span><span class="tabular-nums text-edge-text">18,204</span></li>
                    <li class="flex justify-between"><span>refunded</span><span class="tabular-nums text-edge-text">311</span></li>
                    <li class="flex justify-between"><span>pending</span><span class="tabular-nums text-edge-text">97</span></li>
                </ul>
                <div>
                    <div class="flex justify-between"><span>point-in-time</span><span data-at>now</span></div>
                    <div class="relative mt-1.5 h-px bg-edge-line">
                        <span data-knob class="absolute -top-1 size-2 -translate-x-1/2 bg-edge-lime transition-[left] duration-1000 ease-in-out" style="left: 100%"></span>
                    </div>
                </div>
            </div>
            @break

        @case('WORKERS')
            <div data-motion="workers" class="flex h-full flex-col gap-2">
                <span data-workers class="text-edge-text">4 waiting · 2/5 workers</span>
                <canvas class="min-h-0 w-full flex-1"></canvas>
                <span data-note>scheduler · every minute</span>
            </div>
            @break
    @endswitch
</div>
