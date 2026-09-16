<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-load-balancing',
            'what' => __('Load balancing spreads origin traffic across several servers. It health-checks each one and routes around any that go down.'),
            'steps' => [
                __('Add each origin server (public IP or hostname).'),
                __('Set the health check path your servers answer with a 2xx.'),
                __('Enable and Save. Cloudflare sets up the pool in about a minute, then hybrid origin routes go through it.'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Delivery (hybrid origin routes)'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-delivery']),
                ],
            ],
            'tips' => [
                __(':price per endpoint per month, billed monthly on your subscription.', ['price' => '$'.number_format($unitCents / 100, 2)]),
                __('Servers see the load balancer hostname as Host unless you set a Host header per endpoint.'),
                __('Weight 0 keeps an endpoint in the pool (and billed) but sends it no traffic.'),
            ],
        ])

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        @if ($blocked && ! $enabled)
            <div class="mb-4 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-brand-ink">{{ $blocked }}</div>
        @endif

        <div class="mt-4 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 dark:bg-zinc-900">
                <span class="min-w-0 flex-1 basis-56">
                    <span class="block text-sm font-medium text-brand-ink">{{ __('Enable load balancing') }}</span>
                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">
                        @if ($status === 'active' && $lbHostname)
                            {{ __('Active') }} · <span class="font-mono">{{ $lbHostname }}</span>
                        @elseif (in_array($status, ['pending', 'provisioning'], true))
                            {{ __('Setting up on Cloudflare…') }}
                        @elseif ($status === 'error')
                            <span class="text-red-600">{{ __('Cloudflare error: :error', ['error' => $error]) }}</span>
                        @else
                            {{ __('Off') }}
                        @endif
                    </span>
                </span>
                <x-toggle-switch
                    :enabled="(bool) $enabled"
                    wire:model.live="enabled" @disabled(! $managedDelivery || ($blocked && ! $enabled))
                    :on-label="__('On')"
                    :off-label="__('Off')"
                />
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <x-input-label for="lb-steering" :value="__('Traffic steering')" />
                    <select id="lb-steering" wire:model="steering" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900" @disabled(! $managedDelivery)>
                        @foreach ($steeringOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="lb-path" :value="__('Health check path')" />
                    <x-text-input id="lb-path" wire:model="health_path" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="/healthz" @disabled(! $managedDelivery) />
                    <x-input-error :messages="$errors->get('health_path')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="lb-codes" :value="__('Expected status')" />
                    <x-text-input id="lb-codes" wire:model="expected_codes" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="2xx" @disabled(! $managedDelivery) />
                    <x-input-error :messages="$errors->get('expected_codes')" class="mt-1" />
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">
                    {{ trans_choice(':count endpoint|:count endpoints', count($endpoints)) }}
                    @if (count($endpoints) > 0)
                        <span class="ml-1 font-normal normal-case tracking-normal text-brand-moss">· {{ __(':total / month', ['total' => '$'.number_format(count($endpoints) * $unitCents / 100, 2)]) }}</span>
                    @endif
                </p>
                @if ($status === 'active')
                    <button type="button" wire:click="refreshHealth" class="text-xs font-semibold text-brand-sage">{{ __('Check health') }}</button>
                @endif
            </div>
            <x-input-error :messages="$errors->get('endpoints')" />

            @foreach ($endpoints as $i => $endpoint)
                @php $h = $health[$endpoint['address']] ?? null; @endphp
                <div class="grid gap-3 rounded-xl border border-brand-ink/10 p-3 sm:grid-cols-12" wire:key="lb-endpoint-{{ $i }}">
                    <div class="sm:col-span-3">
                        <x-input-label :value="__('Name')" />
                        <x-text-input wire:model="endpoints.{{ $i }}.name" type="text" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                        <x-input-error :messages="$errors->get('endpoints.'.$i.'.name')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-4">
                        <x-input-label :value="__('Address')" />
                        <x-text-input wire:model="endpoints.{{ $i }}.address" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="203.0.113.10 or app1.example.com" @disabled(! $managedDelivery) />
                        <x-input-error :messages="$errors->get('endpoints.'.$i.'.address')" class="mt-1" />
                        @if ($h)
                            <p class="mt-1 text-xs {{ $h['healthy'] ? 'text-brand-sage' : 'text-red-600' }}">
                                {{ $h['healthy'] ? __('Healthy') : __('Unhealthy: :reason', ['reason' => $h['failure_reason'] ?? __('failing checks')]) }}
                            </p>
                        @endif
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label :value="__('Port')" />
                        <x-text-input wire:model="endpoints.{{ $i }}.port" type="number" min="1" max="65535" class="mt-1 block w-full text-sm" placeholder="443" @disabled(! $managedDelivery) />
                        <x-input-error :messages="$errors->get('endpoints.'.$i.'.port')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-3">
                        <x-input-label :value="__('Weight (0–1)')" />
                        <x-text-input wire:model="endpoints.{{ $i }}.weight" type="number" step="0.05" min="0" max="1" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                        <x-input-error :messages="$errors->get('endpoints.'.$i.'.weight')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-5">
                        <x-input-label :value="__('Host header (optional)')" />
                        <x-text-input wire:model="endpoints.{{ $i }}.host_header" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="app.example.com" @disabled(! $managedDelivery) />
                        <x-input-error :messages="$errors->get('endpoints.'.$i.'.host_header')" class="mt-1" />
                    </div>
                    <div class="flex items-end justify-between gap-3 sm:col-span-7">
                        <label class="flex items-center gap-2 pb-2 text-sm text-brand-ink">
                            <input type="checkbox" wire:model="endpoints.{{ $i }}.enabled" class="rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                            {{ __('Receives traffic') }}
                        </label>
                        <button type="button" wire:click="removeEndpoint({{ $i }})" class="pb-2 text-xs font-semibold text-red-600" @disabled(! $managedDelivery)>{{ __('Remove') }}</button>
                    </div>
                </div>
            @endforeach

            <div class="flex flex-wrap items-center justify-between gap-3">
                <button type="button" wire:click="addEndpoint" class="text-sm font-semibold text-brand-sage" @disabled(! $managedDelivery || count($endpoints) >= \App\Modules\Edge\Support\EdgeLoadBalancing::MAX_ENDPOINTS)>{{ __('Add endpoint') }}</button>
                <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" @disabled(! $managedDelivery)>{{ __('Save') }}</x-primary-button>
            </div>
        </div>
    </section>
</div>
