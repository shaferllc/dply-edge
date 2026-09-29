<x-sheet name="resources-worker-logs" :show="$panel === 'worker-logs'" maxWidth="4xl" focusable>
    <x-sheet.header :eyebrow="__('Queue workers')" :title="__('Worker logs')" close-wire="openPanel('')">
        {{ __('The last hour of queue worker output, newest first: each job as it runs and finishes, and each worker starting, restarting and stopping. Logs reach here about a minute after they happen.') }}
    </x-sheet.header>

    <x-sheet.body>
        @if ($workerLogsError)
            <x-sheet.note tone="danger">{{ $workerLogsError }}</x-sheet.note>
        @elseif (is_array($workerLogs))
            @if ($workerLogs === [])
                <p class="rounded-xl border border-dashed border-brand-ink/15 px-4 py-6 text-center text-xs text-brand-moss dark:border-brand-mist/20">{{ __('No worker output in the last hour.') }}</p>
            @else
                <ol class="overflow-x-auto rounded-xl bg-zinc-950 p-3 font-mono text-2xs leading-relaxed text-raw-zinc-200">
                    @foreach ($workerLogs as $line)
                        <li @class(['whitespace-pre-wrap break-words', 'text-red-400' => str_ends_with(rtrim($line['message']), 'FAIL') || $line['level'] === 'error', 'text-emerald-400' => str_ends_with(rtrim($line['message']), 'DONE'), 'text-sky-300' => str_starts_with($line['message'], '[dply-worker')])><span class="text-zinc-500">{{ $line['at'] ? \Illuminate\Support\Carbon::parse($line['at'])->format('H:i:s') : '' }}</span> {{ $line['message'] }}</li>
                    @endforeach
                </ol>
            @endif
        @endif
    </x-sheet.body>

    <x-sheet.footer>
        <span wire:loading wire:target="loadWorkerLogs,openWorkerLogs">{{ __('Loading…') }}</span>
        <x-sheet.button wire:click="loadWorkerLogs" wire:loading.attr="disabled" wire:target="loadWorkerLogs,openWorkerLogs" class="ms-auto">{{ __('Refresh') }}</x-sheet.button>
    </x-sheet.footer>
</x-sheet>
