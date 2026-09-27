<x-sheet name="resources-failed-jobs" :show="$panel === 'failed-jobs'" maxWidth="3xl" focusable>
    <x-sheet.header :eyebrow="__('Queue workers')" :title="__('Failed jobs')" close-wire="openPanel('')">
        {{ __('From the app\'s own failed-job store. Retry puts a job back on its queue for the workers; Delete removes it for good.') }}
    </x-sheet.header>

    <x-sheet.body>
        @if ($failedJobsNotice)
            <x-sheet.note tone="ok">{{ $failedJobsNotice }}</x-sheet.note>
        @endif
        @if ($failedJobsError)
            <x-sheet.note tone="danger">{{ $failedJobsError }}</x-sheet.note>
        @elseif (is_array($failedJobs))
            @if ($failedJobs['jobs'] === [])
                <p class="rounded-xl border border-dashed border-brand-ink/15 px-4 py-6 text-center text-xs text-brand-moss dark:border-brand-mist/20">{{ __('No failed jobs.') }}</p>
            @else
                @if ($failedJobs['total'] > count($failedJobs['jobs']))
                    <p class="text-xs text-brand-moss">{{ __('Showing the newest :shown of :total.', ['shown' => count($failedJobs['jobs']), 'total' => $failedJobs['total']]) }}</p>
                @endif
                <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 text-xs dark:divide-brand-mist/15 dark:border-brand-mist/15">
                    @foreach ($failedJobs['jobs'] as $job)
                        <li class="grid gap-1.5 px-3.5 py-3" wire:key="failed-job-{{ $job['id'] }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="min-w-0 truncate font-mono font-semibold text-brand-ink">{{ $job['name'] ?: __('Unknown job') }}</p>
                                <div class="flex shrink-0 gap-1.5">
                                    <x-sheet.button wire:click="retryFailedJobs('{{ $job['id'] }}')" wire:loading.attr="disabled">{{ __('Retry') }}</x-sheet.button>
                                    <x-sheet.button variant="danger" wire:click="forgetFailedJob('{{ $job['id'] }}')" wire:loading.attr="disabled">{{ __('Delete') }}</x-sheet.button>
                                </div>
                            </div>
                            <p class="text-brand-moss">{{ implode(' · ', array_filter([$job['queue'] ?? '', $job['connection'] ?? '', ($job['failed_at'] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($job['failed_at'])->diffForHumans() : '', ($job['attempts'] ?? 0) > 0 ? trans_choice(':count attempt|:count attempts', $job['attempts']) : ''])) }}</p>
                            <p class="break-words text-rose-700 dark:text-rose-300">{{ $job['error'] }}</p>
                            <details>
                                <summary class="cursor-pointer text-brand-moss">{{ __('Stack trace') }}</summary>
                                <pre class="mt-1 max-h-64 overflow-auto whitespace-pre-wrap rounded-xl bg-zinc-950 p-3 font-mono text-2xs text-zinc-200">{{ $job['trace'] }}</pre>
                            </details>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </x-sheet.body>

    <x-sheet.footer>
        <span wire:loading wire:target="loadFailedJobs,retryFailedJobs,forgetFailedJob,flushFailedJobs,openFailedJobs">{{ __('Asking the app…') }}</span>
        <span class="ms-auto flex flex-wrap gap-1.5">
            <x-sheet.button wire:click="loadFailedJobs" wire:loading.attr="disabled" wire:target="loadFailedJobs,retryFailedJobs,forgetFailedJob,flushFailedJobs">{{ __('Refresh') }}</x-sheet.button>
            @if (($failedJobs['total'] ?? 0) > 0)
                <x-sheet.button wire:click="retryFailedJobs" wire:loading.attr="disabled" wire:target="loadFailedJobs,retryFailedJobs,forgetFailedJob,flushFailedJobs">{{ __('Retry all') }}</x-sheet.button>
                <x-sheet.button variant="danger" wire:click="flushFailedJobs" wire:loading.attr="disabled" wire:target="loadFailedJobs,retryFailedJobs,forgetFailedJob,flushFailedJobs">{{ $confirmFlushFailed ? __('Delete all :count? Click again', ['count' => $failedJobs['total']]) : __('Delete all') }}</x-sheet.button>
            @endif
        </span>
    </x-sheet.footer>
</x-sheet>
