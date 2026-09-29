{{-- "Add queue workers": the choices that change cost or whether jobs run
     (Resources::openWorkerSetup / confirmWorkerSetup). Timeouts, tries,
     memory and the rest keep their defaults in the workers sheet. --}}
@php
    $setup = $workerSetup;
    $setupAllow = \App\Modules\Edge\Support\EdgeQueueWorkers::allowance($site);
    $setupTrial = \App\Modules\Edge\Support\EdgeTrialLimits::applies($site->organization);
    $alwaysCents = \App\Modules\Edge\Support\EdgeQueueWorkers::monthlyCents($site, 1);
    $scaleMax = max(1, min($setupAllow['instances'] ?? 2, 2));
    $scaleCents = \App\Modules\Edge\Support\EdgeQueueWorkers::monthlyCents($site, $scaleMax);
    $valkeyCapCents = \App\Modules\Edge\Support\EdgeValkey::CLASSES[\App\Modules\Edge\Support\EdgeValkey::DEFAULT_CLASS]['price_cap_cents'];
    $money = fn (int $cents): string => '$'.number_format($cents / 100, 2);
@endphp
<x-sheet name="resources-worker-setup" :show="$panel === 'worker-setup'" maxWidth="lg" focusable>
    @if ($setup !== [])
        <x-sheet.header :eyebrow="__('Add a resource')" :title="__('Queue workers')" close-wire="openPanel('')">
            {{ __('Workers run php artisan queue:work beside your app and take the jobs it dispatches.') }}
        </x-sheet.header>

        <x-sheet.body>
            @if ($setup['backend'] === 'valkey')
                <x-sheet.section :title="__('Where jobs wait')">
                    <p class="text-xs text-brand-moss">{{ __('Workers pull jobs from somewhere the app and every worker can reach. This app’s SQLite lives inside one container, so it needs a shared queue first.') }}</p>
                    <x-sheet.option :selected="true" :title="__('Add dply Valkey for the queue')" :description="__('Redis-compatible, smallest size, sleeps when idle. The app’s queue moves to it.')" :meta="__('up to :price/mo', ['price' => $money($valkeyCapCents)])" />
                    <button type="button" wire:click="addDatabase" x-on:click="$dispatch('close-modal', 'resources-worker-setup')" class="justify-self-start text-xs font-semibold text-brand-forest hover:underline dark:text-brand-sage">{{ __('Use a Postgres or MySQL database instead') }}</button>
                </x-sheet.section>
            @endif

            <x-sheet.section :title="__('Queues')">
                <x-sheet.field :label="__('Queues, most urgent first')" for="setup-queues" :help="$setup['groups'] !== []
                    ? __('From config/horizon.php. Each other supervisor gets its own workers: :groups.', ['groups' => implode(' · ', $setup['groups'])])
                    : __('Found in your code at the last build. Comma-separated, like Laravel’s --queue.')">
                    <input id="setup-queues" type="text" wire:model="workerSetup.queues" autocomplete="off" class="dply-input font-mono" />
                </x-sheet.field>
            </x-sheet.section>

            <x-sheet.section :title="__('When workers run')">
                <x-sheet.option wire:click="$set('workerSetup.mode', 'scale')" :selected="$setup['mode'] === 'scale'" :disabled="! $setupAllow['autoscale']"
                    :title="__('Start when jobs arrive')"
                    :description="$setupAllow['autoscale']
                        ? trans_choice('Nothing runs while the queue is empty. A waiting job starts a worker, which takes a few seconds. Up to :count worker.|Nothing runs while the queue is empty. A waiting job starts a worker, which takes a few seconds. Up to :count workers.', $scaleMax)
                        : ($setupTrial ? __('Available after your trial') : __('On Starter, Pro and Team'))"
                    :meta="__('$0 idle · up to :price/mo', ['price' => $money($scaleCents)])" />
                <x-sheet.option wire:click="$set('workerSetup.mode', 'always')" :selected="$setup['mode'] === 'always'"
                    :title="__('Always on')" :description="__('One worker runs all the time, so jobs start straight away.')"
                    :meta="__('about :price/mo', ['price' => $money($alwaysCents)])" />
            </x-sheet.section>

            <x-sheet.section :title="__('Processes per worker')">
                <x-sheet.field :label="__('Processes')" for="setup-processes" :help="__('Jobs each worker runs at once. :count fits this app’s size.', ['count' => \App\Modules\Edge\Support\EdgeQueueWorkers::recommendedProcesses($site)])">
                    <input id="setup-processes" type="number" min="1" max="{{ \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_PROCESSES }}" wire:model="workerSetup.processes" class="dply-input w-24" />
                </x-sheet.field>
                <p class="text-2xs text-brand-mist">{{ __('Timeouts, retries, memory and more are in the workers’ settings once they’re added.') }}</p>
            </x-sheet.section>

            @error('workerSetup') <x-sheet.note tone="warn">{{ $message }}</x-sheet.note> @enderror
        </x-sheet.body>

        <x-sheet.footer>
            <x-sheet.button type="button" wire:click="confirmWorkerSetup(false)" wire:loading.attr="disabled" wire:target="confirmWorkerSetup">{{ __('Add, deploy later') }}</x-sheet.button>
            <x-sheet.button variant="primary" type="button" wire:click="confirmWorkerSetup(true)" wire:loading.attr="disabled" wire:target="confirmWorkerSetup" class="ms-auto">
                <span wire:loading.remove wire:target="confirmWorkerSetup">{{ __('Add and deploy') }}</span>
                <span wire:loading wire:target="confirmWorkerSetup">{{ __('Setting up…') }}</span>
            </x-sheet.button>
        </x-sheet.footer>
    @endif
</x-sheet>
