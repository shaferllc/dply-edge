                    <div class="flex justify-center py-0.5" aria-hidden="true"><span @class(['resource-flow resource-flow-y', 'resource-flow-asleep' => $connection['asleep']])></span></div>
                    <div @class([
                        'rounded-xl border p-3',
                        'resource-asleep border-dashed border-brand-ink/20 bg-white/70 dark:bg-zinc-900/70' => $connection['asleep'],
                        'border-brand-sage bg-brand-sage/5' => ! $connection['asleep'],
                    ])>
                        <div class="flex flex-col gap-2">
                            <p class="flex items-center gap-1.5 whitespace-nowrap text-xs font-semibold text-brand-ink">
                                <x-resource-kind-icon :kind="$connection['kind']" class="h-3.5 w-3.5 shrink-0" />
                                {{ __($connectionKinds[$connection['kind']]['label']) }}
                                @if ($connection['asleep'])
                                    <span class="font-medium text-brand-moss">{{ __('Asleep') }}</span>
                                    <span class="resource-snore" aria-hidden="true"><span>z</span><span>z</span><span>z</span></span>
                                @endif
                            </p>
                            <span class="flex flex-wrap gap-x-2 gap-y-1">
                                @if ($connection['kind'] === 'service')
                                    <button type="button" wire:click="$set('explainConnectionHost', '{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-service')" class="text-xs font-semibold text-brand-ink underline">{{ __('Open') }}</button>
                                @elseif ($connection['kind'] === 'key_value')
                                    <button type="button" wire:click="openKv('{{ $connection['host'] }}')" wire:loading.attr="disabled" wire:target="openKv" class="text-xs font-semibold text-brand-ink underline disabled:opacity-50">{{ __('Settings') }}</button>
                                @elseif ($connection['kind'] === 'object_storage')
                                    <button type="button" wire:click="openObject('{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-object')" class="text-xs font-semibold text-brand-ink underline">{{ __('Open') }}</button>
                                @elseif ($connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']))
                                    <button type="button" wire:click="$set('valkeyHost', '{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-valkey')" class="text-xs font-semibold text-brand-ink underline">{{ __('Details') }}</button>
                                @elseif ($connection['kind'] === 'images')
                                    <button type="button" wire:click="$set('imagesHost', '{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-images')" class="text-xs font-semibold text-brand-ink underline">{{ __('Settings') }}</button>
                                @endif
                                <button type="button" wire:click="sleepConnection('{{ $connection['host'] }}', {{ $connection['asleep'] ? 'false' : 'true' }})" class="text-xs font-semibold text-brand-ink underline">{{ $connection['asleep'] ? __('Wake') : __('Sleep') }}</button>
                                <button type="button" wire:click="askDeleteConnection('{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-delete-connection')" class="text-xs font-semibold text-brand-ink underline">{{ __('Delete') }}</button>
                                <button type="button" wire:click="removeConnection('{{ $connection['host'] }}')" class="text-xs font-semibold text-brand-ink underline">{{ __('Detach') }}</button>
                            </span>
                        </div>
                        @if ($connection['kind'] === 'object_storage')
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Bucket :name', ['name' => $connection['target']]) }}</p>
                        @elseif ($connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']) && ! $cardOnFile)
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Add a card to keep using this Redis. It stays off the app until then.') }}</p>
                            @if ($site->organization)
                                <a href="{{ route('billing.show', $site->organization) }}" class="text-xs font-semibold text-brand-ink underline">{{ __('Billing') }}</a>
                            @endif
                        @elseif ($connection['kind'] === 'key_value' && $connection['asleep'])
                            <p class="mt-1 font-mono text-xs text-brand-moss">{{ $connection['host'] }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Asleep. The address comes off the app on the next deploy. Not billed until you wake it.') }}</p>
                        @elseif ($connection['kind'] !== 'redis')
                            <p class="mt-1 font-mono text-xs text-brand-moss">{{ $isWorker ? 'env.'.$connection['name'] : $connection['host'] }}</p>
                        @endif
                        @if ($isWorker && ! in_array($connection['kind'], $allowedKinds, true))
                            <p class="mt-1 text-xs font-semibold text-red-700 dark:text-red-400">{{ __('This app runs as a Worker. This resource needs a container app, so it is not attached.') }}</p>
                        @endif
                        @if ($connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']))
                            @php
                                $valkeyClass = \App\Modules\Edge\Support\EdgeValkey::CLASSES[$connection['plan']] ?? \App\Modules\Edge\Support\EdgeValkey::CLASSES[\App\Modules\Edge\Support\EdgeValkey::DEFAULT_CLASS];
                                $valkeySleepNow = (int) ($site->edgeMeta()['valkey_sleep'][$connection['target']] ?? ($valkeyClass['sleeps'] ? \App\Modules\Edge\Support\EdgeValkey::DEFAULT_SLEEP : 0));
                            @endphp
                            @php
                                $valkeyPlan = isset(\App\Modules\Edge\Support\EdgeValkey::CLASSES[$connection['plan']]) ? $connection['plan'] : \App\Modules\Edge\Support\EdgeValkey::DEFAULT_CLASS;
                                $valkeyField = 'block w-full rounded-md border border-brand-ink/15 bg-white py-1 ps-2 pe-6 text-xs font-semibold text-brand-ink disabled:opacity-60 dark:bg-zinc-900';
                            @endphp
                            <dl class="mt-2 grid grid-cols-[auto_1fr] items-center gap-x-2 gap-y-1.5 text-xs" wire:key="valkey-card-{{ md5($connection['host']) }}">
                                <dt><label for="valkey-size-{{ $connectionIndex }}" class="text-brand-moss">{{ __('Size') }}</label></dt>
                                <dd>
                                    <select id="valkey-size-{{ $connectionIndex }}" wire:change="saveValkey('{{ $connection['host'] }}', $event.target.value, {{ $valkeySleepNow }})" wire:loading.attr="disabled" wire:target="saveValkey" class="{{ $valkeyField }}">
                                        @foreach (\App\Modules\Edge\Support\EdgeValkey::offered() as $classId => $class)
                                            <option value="{{ $classId }}" @selected($valkeyPlan === $classId)>{{ __($class['label']) }} · {{ __('up to $:price/mo', ['price' => number_format($class['cap_cents'] / 100, 0)]) }}</option>
                                        @endforeach
                                    </select>
                                </dd>
                                <dt><label for="valkey-sleep-{{ $connectionIndex }}" class="text-brand-moss">{{ __('Sleep') }}</label></dt>
                                <dd>
                                    @if ($valkeyClass['sleeps'])
                                        <select id="valkey-sleep-{{ $connectionIndex }}" wire:change="saveValkey('{{ $connection['host'] }}', '{{ $valkeyPlan }}', $event.target.value)" wire:loading.attr="disabled" wire:target="saveValkey" class="{{ $valkeyField }}">
                                            @foreach (\App\Modules\Edge\Support\EdgeValkey::SLEEPS as $seconds => $label)
                                                <option value="{{ $seconds }}" @selected($valkeySleepNow === $seconds)>{{ $seconds > 0 ? __('After :time idle', ['time' => __($label)]) : __($label) }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="font-semibold text-brand-ink">{{ __('Stays on (Pro sizes do not sleep)') }}</span>
                                    @endif
                                </dd>
                            </dl>
                        @endif
                        @if ($connection['kind'] === 'queue' && isset($queueOwners[$connection['target']]))
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Sends only. :app runs these jobs.', ['app' => $queueOwners[$connection['target']]]) }}</p>
                        @endif
                        @if (in_array($connection['name'], $overriddenByRepo, true))
                            <p class="mt-1 text-xs font-semibold text-brand-ink">{{ __('Overridden by wrangler.toml. The repo binding is used.') }}</p>
                        @endif
                        @if (isset($connectionEstimates[$connection['host']]) && $connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']))
                            <p class="mt-1 text-xs font-semibold tabular-nums text-brand-ink">{{ __('$:price so far this month · up to $:cap/mo', ['price' => \App\Modules\Edge\Support\EdgeValkey::money($connectionEstimates[$connection['host']]), 'cap' => number_format($valkeyClass['cap_cents'] / 100, 0)]) }}</p>
                        @elseif (isset($connectionEstimates[$connection['host']]))
                            <p class="mt-1 text-xs font-semibold tabular-nums text-brand-ink">{{ __('Cost estimate · $:price', ['price' => number_format($connectionEstimates[$connection['host']] / 100, 2)]) }}</p>
                        @endif
                    </div>
