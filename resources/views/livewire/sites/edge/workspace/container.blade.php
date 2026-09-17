<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-containers',
            'what' => __('Your app runs on Cloudflare Containers. Pick how big each container is, how many can run at once, and how long an idle one stays warm.'),
            'steps' => [
                __('Choose an instance size that fits your app’s memory.'),
                __('Set max instances: traffic is spread across up to this many containers.'),
                __('Save and redeploy to roll the new settings out.'),
            ],
            'tips' => [
                __('Compute is billed per second while containers run; they sleep when idle and the meter stops.'),
                __('A longer sleep timeout means fewer cold starts but more billed time.'),
                __('Queues: install dply/laravel or dply-rails and add a queue under Jobs.'),
            ],
        ])

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 dark:bg-zinc-900">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Compute this month') }}</p>
                <p class="mt-1 font-mono text-lg font-semibold text-brand-ink">${{ number_format($monthCents / 100, 2) }}</p>
                <p class="text-xs text-brand-moss">{{ __(':cpu vCPU-h · :mem GiB-h', ['cpu' => number_format($cpuHours, 1), 'mem' => number_format($memoryGibHours, 1)]) }}</p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 dark:bg-zinc-900">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Per container, per minute') }}</p>
                <p class="mt-1 font-mono text-lg font-semibold text-brand-ink">${{ number_format($perMinute, 5) }}</p>
                <p class="text-xs text-brand-moss">{{ __('with every vCPU busy') }}</p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 dark:bg-zinc-900">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Worst case, per month') }}</p>
                <p class="mt-1 font-mono text-lg font-semibold text-brand-ink">${{ number_format($maxPerMonth, 2) }}</p>
                <p class="text-xs text-brand-moss">{{ trans_choice(':count instance always on|:count instances always on', $max_instances) }}</p>
            </div>
        </div>

        <div class="mt-4 space-y-4">
            <div>
                <x-input-label :value="__('Instance size')" />
                <div class="mt-2 grid gap-2 sm:grid-cols-3" role="radiogroup">
                    @foreach ($instanceTypes as $type => [$vcpu, $memory, $disk])
                        <label @class([
                            'flex cursor-pointer flex-col rounded-xl border px-3 py-2 text-sm',
                            'border-brand-sage/50 bg-brand-sage/5' => $instance_type === $type,
                            'border-brand-ink/10 bg-white dark:bg-zinc-900' => $instance_type !== $type,
                        ])>
                            <span class="flex items-center gap-2">
                                <input type="radio" wire:model.live="instance_type" value="{{ $type }}" class="text-brand-sage" />
                                <span class="font-mono font-semibold text-brand-ink">{{ $type }}</span>
                            </span>
                            <span class="mt-0.5 text-xs text-brand-moss">{{ $vcpu < 1 ? '1/'.(int) round(1 / $vcpu) : $vcpu }} vCPU · {{ $memory }} GiB · {{ $disk }} GB</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <x-input-label for="ctr-max" :value="__('Max instances')" />
                    <x-text-input id="ctr-max" wire:model.live.debounce.400ms="max_instances" type="number" min="1" max="20" class="mt-1 block w-full text-sm" />
                    <x-input-error :messages="$errors->get('max_instances')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="ctr-sleep" :value="__('Sleep after idle')" />
                    <select id="ctr-sleep" wire:model="sleep_after" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900">
                        @foreach ($sleepOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="ctr-jurisdiction" :value="__('Run only in')" />
                    <select id="ctr-jurisdiction" wire:model="jurisdiction" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900">
                        <option value="">{{ __('Anywhere (fastest)') }}</option>
                        <option value="eu">{{ __('EU') }}</option>
                        <option value="fedramp">{{ __('FedRAMP') }}</option>
                    </select>
                </div>
            </div>

            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="migrate_on_boot" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" />
                <span class="text-sm">
                    <span class="font-medium text-brand-ink">{{ __('Run migrations when a container starts') }}</span>
                    <span class="block text-xs text-brand-moss">{{ __('Laravel: migrate --force --isolated. Rails: db:prepare. Generated Dockerfiles only.') }}</span>
                </span>
            </label>

            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model="scheduler" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" />
                <span class="text-sm">
                    <span class="font-medium text-brand-ink">{{ __('Run the Laravel scheduler every minute') }}</span>
                    <span class="block text-xs text-brand-moss">{{ __('Calls schedule:run through dply/laravel. For other jobs add a cron under Crons with an artisan command or rake task as the handler.') }}</span>
                </span>
            </label>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="font-mono text-xs text-brand-mist">{{ $scriptName }}</p>
                <div class="flex gap-2">
                    <x-secondary-button type="button" wire:click="save">{{ __('Save') }}</x-secondary-button>
                    <x-primary-button type="button" wire:click="save(true)" wire:loading.attr="disabled">{{ __('Save and redeploy') }}</x-primary-button>
                </div>
            </div>
        </div>
    </section>
</div>
