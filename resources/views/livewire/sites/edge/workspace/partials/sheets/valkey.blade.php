@php
    $valkeyConnection = collect($connections)->firstWhere('host', $valkeyHost);
    $valkeySpec = is_array($valkeyConnection)
        ? (\App\Modules\Edge\Support\EdgeValkey::spec((string) $valkeyConnection['plan']))
        : null;
    $valkeyModalSleep = is_array($valkeyConnection)
        ? (int) ($site->edgeMeta()['valkey_sleep'][$valkeyConnection['target']] ?? ($valkeySpec['sleeps'] ? \App\Modules\Edge\Support\EdgeValkey::DEFAULT_SLEEP : 0))
        : 0;
@endphp
<x-sheet name="resources-valkey" :show="$valkeyHost !== ''" maxWidth="3xl" focusable>
    <x-sheet.header :eyebrow="$valkeyHost !== '' ? $valkeyHost : null" :title="__('dply Valkey')" close-wire="$set('valkeyHost', '')" />

    <x-sheet.body>
        {{-- Opening sets valkeyHost on the server (and refreshes awake time from the gateway), so the body waits on that round trip. --}}
        <div wire:loading.block wire:target="valkeyHost" class="hidden" aria-live="polite" aria-busy="true">
            <div class="flex items-center gap-2 text-xs font-medium text-brand-moss">
                <x-spinner variant="forest" />
                <span>{{ __('Loading this database…') }}</span>
            </div>
            <div class="mt-4 flex gap-4 border-b border-brand-ink/10 pb-2 dark:border-brand-mist/15" aria-hidden="true">
                @foreach (['w-16', 'w-14', 'w-20', 'w-10', 'w-12', 'w-14'] as $width)
                    <span class="{{ $width }} h-3 animate-pulse rounded bg-brand-ink/10"></span>
                @endforeach
            </div>
            <x-sheet.metrics :cols="2" class="mt-4" aria-hidden="true">
                @foreach (range(1, 4) as $placeholder)
                    <div class="min-w-0 rounded-xl border border-brand-ink/10 px-3.5 py-3 dark:border-brand-mist/15">
                        <span class="block h-2.5 w-16 animate-pulse rounded bg-brand-ink/10"></span>
                        <span class="mt-2 block h-5 w-28 max-w-full animate-pulse rounded bg-brand-ink/10"></span>
                        <span class="mt-1.5 block h-2.5 w-44 max-w-full animate-pulse rounded bg-brand-ink/10"></span>
                    </div>
                @endforeach
            </x-sheet.metrics>
        </div>
        @if (is_array($valkeyConnection) && is_array($valkeySpec))
            <div wire:loading.remove wire:target="valkeyHost" class="grid content-start gap-5">
            @php
                $valkeyAddress = \App\Modules\Edge\Support\EdgeValkey::address($valkeyConnection['target']);
                $valkeySpent = $connectionEstimates[$valkeyConnection['host']] ?? 0;
                $valkeySleepLabel = $valkeyModalSleep > 0 ? __(\App\Modules\Edge\Support\EdgeValkey::SLEEPS[$valkeyModalSleep] ?? '5 minutes') : null;
            @endphp
            <p class="flex items-start gap-2 text-xs leading-5 text-brand-moss"><x-resource-kind-icon kind="redis" />{{ __('Redis-compatible and private to this app. :size, :sleep.', ['size' => __($valkeySpec['label']), 'sleep' => $valkeySleepLabel ? __('sleeps after :time idle', ['time' => $valkeySleepLabel]) : __('stays on')]) }}</p>
            @include('livewire.sites.edge.workspace.partials.valkey-size-fields', ['connection' => $valkeyConnection])
            <div class="grid content-start gap-4" x-data="{ tab: 'overview' }">
                <x-sheet.tabs>
                    @foreach (['overview' => __('Overview'), 'connect' => __('Connect'), 'stats' => __('Statistics'), 'test' => __('Test'), 'costs' => __('Costs')] as $tabKey => $tabLabel)
                        <button type="button" role="tab" x-on:click="tab = '{{ $tabKey }}'; {{ $tabKey === 'stats' ? '$wire.$island(\'resources-valkey\').loadValkeyStatus()' : '' }}" :aria-selected="tab === '{{ $tabKey }}' ? 'true' : 'false'">{{ $tabLabel }}</button>
                    @endforeach
                </x-sheet.tabs>

                <div x-show="tab === 'overview'" class="grid gap-3">
                    @php
                        $awakeH = intdiv($valkeyAwakeSeconds, 3600);
                        $awakeM = intdiv($valkeyAwakeSeconds % 3600, 60);
                        $overviewCards = [
                            [__('Size'), __($valkeySpec['label']), __(':mb MB of memory for keys', ['mb' => number_format($valkeySpec['memory_mb'])])],
                            [__('Sleep'), $valkeySleepLabel ? __('After :time idle', ['time' => $valkeySleepLabel]) : __('Stays on'), $valkeySleepLabel ? __('Keys are saved and come back on the next connection, with their expiry.') : __('Keys are written to disk.')],
                            [__('Awake this month'), $awakeH > 0 ? __(':h h :m min', ['h' => $awakeH, 'm' => $awakeM]) : __(':m min', ['m' => $awakeM]), __('$:spent so far · never more than $:cap/mo', ['spent' => \App\Modules\Edge\Support\EdgeValkey::money($valkeySpent), 'cap' => number_format($valkeySpec['cap_cents'] / 100, 0)])],
                            [__('When it is full'), __('Writes are refused'), __('Nothing is evicted. Pick a larger size on the card.')],
                        ];
                    @endphp
                    <div class="flex justify-end">
                        <x-sheet.button wire:click="refreshValkeyAwake" wire:loading.attr="disabled" wire:target="refreshValkeyAwake">
                            <x-spinner size="sm" wire:loading wire:target="refreshValkeyAwake" />
                            {{ __('Refresh awake time') }}
                        </x-sheet.button>
                    </div>
                    <x-sheet.metrics :cols="2">
                        @foreach ($overviewCards as [$cardLabel, $cardValue, $cardNote])
                            {{-- Notes here are sentences, so they go in the extra slot and wrap instead of truncating. --}}
                            <x-sheet.metric :label="$cardLabel">
                                {{ $cardValue }}
                                <x-slot:extra><p class="mt-0.5 text-2xs leading-4 text-brand-moss">{{ $cardNote }}</p></x-slot:extra>
                            </x-sheet.metric>
                        @endforeach
                    </x-sheet.metrics>
                </div>

                <div x-show="tab === 'connect'" class="grid gap-5">
                    {{-- Rows styled like x-sheet.stat, but values wrap (break-all) instead of truncating. --}}
                    <dl class="text-sm [&>div]:flex [&>div]:items-baseline [&>div]:justify-between [&>div]:gap-3 [&>div]:border-b [&>div]:border-brand-ink/10 [&>div]:py-2 [&>div:last-child]:border-b-0 dark:[&>div]:border-brand-mist/15 [&_dt]:shrink-0 [&_dt]:text-brand-moss [&_dd]:min-w-0 [&_dd]:text-right [&_dd]:text-xs [&_dd]:text-brand-ink">
                        <div>
                            <dt>{{ __('Address') }}</dt>
                            <dd class="break-all font-mono">{{ $valkeyAddress }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('Username') }}</dt>
                            <dd class="font-mono">default <span class="font-sans text-brand-moss">{{ __('(on this app\'s own instance)') }}</span></dd>
                        </div>
                        <div>
                            <dt>{{ __('Password') }}</dt>
                            <dd x-data="{ password: '' }" class="flex flex-wrap items-center justify-end gap-2">
                                <span class="break-all font-mono text-xs text-brand-ink" x-text="password || '••••••••••••••••'"></span>
                                <x-sheet.button x-show="! password" x-on:click="password = await $wire.$island('resources-valkey').valkeyPassword({{ \Illuminate\Support\Js::from($valkeyConnection['host']) }})">{{ __('Show') }}</x-sheet.button>
                                <x-sheet.button x-show="password" x-on:click="navigator.clipboard.writeText(password)">{{ __('Copy') }}</x-sheet.button>
                            </dd>
                        </div>
                        <div>
                            <dt>{{ __('Encryption') }}</dt>
                            <dd>{{ __('TLS required (rediss://).') }}</dd>
                        </div>
                        <div>
                            <dt>{{ __('On the app') }}</dt>
                            <dd>{{ __('REDIS_URL is set on the next deploy. It holds the password.') }}</dd>
                        </div>
                    </dl>
                    <x-sheet.section :title="__('Laravel')">
                        <p class="text-xs text-brand-moss">{{ __('dply sets REDIS_URL, REDIS_CLIENT=phpredis and CACHE_STORE=redis for you. Sessions can use it too:') }}</p>
                        <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">SESSION_DRIVER=redis</pre>
                    </x-sheet.section>
                    <x-sheet.section :title="__('Node')">
                        <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "import { createClient } from 'redis';\nconst redis = await createClient({ url: process.env.REDIS_URL }).connect();" }}</pre>
                    </x-sheet.section>
                    <x-sheet.section :title="__('Rails')">
                        <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "config.cache_store = :redis_cache_store, { url: ENV['REDIS_URL'] }" }}</pre>
                    </x-sheet.section>
                </div>

                <div x-show="tab === 'stats'" class="grid gap-4">
                    @if ($valkeyStatsError)
                        <x-sheet.note tone="danger">{{ $valkeyStatsError }}</x-sheet.note>
                    @endif
                    @if (is_array($valkeyStatus))
                        <div>
                            <x-sheet.stat :label="__('State')" class="font-semibold">{{ ($valkeyStatus['awake'] ?? false) ? __('Awake') : __('Asleep') }}</x-sheet.stat>
                            @if ($valkeyStatus['awake'] ?? false)
                                <x-sheet.stat :label="__('Idle for')" class="tabular-nums">{{ __(':s s since the last connection', ['s' => (int) ($valkeyStatus['idle_seconds'] ?? 0)]) }}</x-sheet.stat>
                            @endif
                            <x-sheet.stat :label="__('Saved keys')">{{ ($valkeyStatus['has_snapshot'] ?? false) ? __('A snapshot is stored and restores on wake.') : __('No snapshot yet.') }}</x-sheet.stat>
                        </div>
                    @else
                        <p class="text-xs text-brand-moss" wire:loading wire:target="loadValkeyStatus">{{ __('Loading…') }}</p>
                    @endif
                    <div class="flex flex-wrap items-center gap-3">
                        <x-sheet.button wire:click="loadValkeyStats" wire:loading.attr="disabled" wire:target="loadValkeyStats">
                            <span wire:loading.remove wire:target="loadValkeyStats">{{ is_array($valkeyStats) ? __('Refresh live stats') : __('Load live stats') }}</span>
                            <span wire:loading wire:target="loadValkeyStats">{{ __('Loading…') }}</span>
                        </x-sheet.button>
                        <span class="text-2xs text-brand-mist">{{ __('Connects to the database, so it wakes it if it is asleep.') }}</span>
                    </div>
                    @if (is_array($valkeyStats))
                        @php
                            $usedPct = $valkeyStats['max_memory'] > 0 ? min(100, round($valkeyStats['used_memory'] / $valkeyStats['max_memory'] * 100, 1)) : null;
                        @endphp
                        <x-sheet.metrics :cols="3">
                            @foreach ([
                                [__('Keys'), number_format($valkeyStats['keys'])],
                                [__('Memory used'), number_format($valkeyStats['used_memory'] / 1048576, 1).' MB'.($usedPct !== null ? ' · '.$usedPct.'%' : '')],
                                [__('Hit rate'), $valkeyStats['hit_rate'] !== null ? $valkeyStats['hit_rate'].'%' : '—'],
                                [__('Commands'), number_format($valkeyStats['commands'])],
                                [__('Ops per second'), number_format($valkeyStats['ops_per_sec'])],
                                [__('Clients connected'), number_format($valkeyStats['clients'])],
                                [__('Evicted keys'), number_format($valkeyStats['evicted_keys'])],
                                [__('Expired keys'), number_format($valkeyStats['expired_keys'])],
                                [__('Up for'), \Carbon\CarbonInterval::seconds($valkeyStats['uptime_seconds'])->cascade()->forHumans(['short' => true, 'parts' => 2])],
                            ] as [$statLabel, $statValue])
                                <x-sheet.metric :label="$statLabel">{{ $statValue }}</x-sheet.metric>
                            @endforeach
                        </x-sheet.metrics>
                        @if ($usedPct !== null)
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/10" role="progressbar" aria-valuenow="{{ $usedPct }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Memory used') }}">
                                <div @class(['h-full', 'bg-brand-sage' => $usedPct < 80, 'bg-amber-500' => $usedPct >= 80 && $usedPct < 95, 'bg-red-600' => $usedPct >= 95]) style="width: {{ $usedPct }}%"></div>
                            </div>
                        @endif
                        <p class="text-2xs leading-4 text-brand-mist">{{ __('Hits :hits · misses :misses · Valkey :version. Counts reset when it sleeps. When full it evicts keys that expire (cache entries), never queued jobs.', ['hits' => number_format($valkeyStats['hits']), 'misses' => number_format($valkeyStats['misses']), 'version' => $valkeyStats['version']]) }}</p>
                        @if (is_array($valkeySlowlog))
                            <x-sheet.section :title="__('Slowest recent commands')">
                                @if ($valkeySlowlog === [])
                                    <x-sheet.empty :message="__('None over 10 ms since it last woke.')" />
                                @else
                                    <x-sheet.table>
                                        <table>
                                            <tbody>
                                                @foreach ($valkeySlowlog as $slow)
                                                    <tr>
                                                        <td>{{ $slow['command'] }}</td>
                                                        <td>{{ $slow['key'] }}</td>
                                                        <td class="text-right tabular-nums">{{ number_format($slow['micros'] / 1000, 1) }} ms</td>
                                                        <td>{{ \Illuminate\Support\Carbon::createFromTimestamp($slow['at'])->diffForHumans(short: true) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </x-sheet.table>
                                @endif
                            </x-sheet.section>
                        @endif
                    @endif
                </div>

                <div x-show="tab === 'test'" class="grid gap-4">
                    <x-sheet.note>{{ __('Connects the way the app does (TLS, user default, this app\'s password), wakes it if it is asleep, then writes, reads and deletes a test key and sends 10 PINGs. It runs from the dply server, so times include the trip from there to the database; an app running nearby sees less.') }}</x-sheet.note>
                    <div class="flex flex-wrap gap-2">
                        <x-sheet.button wire:click="testValkey" wire:loading.attr="disabled" wire:target="testValkey">
                            <span wire:loading.remove wire:target="testValkey">{{ __('Run test from dply') }}</span>
                            <span wire:loading wire:target="testValkey">{{ __('Testing…') }}</span>
                        </x-sheet.button>
                        <x-sheet.button wire:click="testValkeyFromApp" wire:loading.attr="disabled" wire:target="testValkeyFromApp">
                            <span wire:loading.remove wire:target="testValkeyFromApp">{{ __('Run test from the app') }}</span>
                            <span wire:loading wire:target="testValkeyFromApp">{{ __('Testing…') }}</span>
                        </x-sheet.button>
                    </div>
                    @if (is_array($valkeyAppTest))
                        <div class="grid gap-2 rounded-xl border border-brand-ink/10 p-3.5 dark:border-brand-mist/20">
                            <x-sheet.note :tone="($valkeyAppTest['ok'] ?? false) ? 'ok' : 'danger'" class="font-semibold">
                                {{ ($valkeyAppTest['ok'] ?? false) ? __('From the app: working.') : __('From the app: :error', ['error' => $valkeyAppTest['error'] ?? __('failed')]) }}
                            </x-sheet.note>
                            @if (($valkeyAppTest['steps'] ?? []) !== [])
                                <x-sheet.table>
                                    <table>
                                        <tbody>
                                            @foreach ($valkeyAppTest['steps'] as $step)
                                                <tr>
                                                    <td>{{ $step['step'] }}</td>
                                                    <td class="text-right tabular-nums">{{ number_format((float) $step['ms'], 1) }} ms</td>
                                                    <td>{{ \Illuminate\Support\Str::limit((string) $step['result'], 24) }}</td>
                                                </tr>
                                            @endforeach
                                            @if (isset($valkeyAppTest['ping_median_ms']))
                                                <tr>
                                                    <td>{{ __('PING ×10') }}</td>
                                                    <td class="text-right tabular-nums">{{ number_format((float) $valkeyAppTest['ping_median_ms'], 1) }} ms</td>
                                                    <td>{{ __('median · slowest :max ms', ['max' => number_format((float) $valkeyAppTest['ping_max_ms'], 1)]) }}</td>
                                                </tr>
                                            @endif
                                        </tbody>
                                    </table>
                                </x-sheet.table>
                                <p class="text-2xs text-brand-mist">
                                    {{ __('Client :client · persistent connections :persistent', ['client' => $valkeyAppTest['client'] ?? '?', 'persistent' => ($valkeyAppTest['persistent'] ?? false) ? __('on') : __('off')]) }}{{ ($valkeyAppTest['region'] ?? '') !== '' ? ' · '.__('app runs in :region', ['region' => $valkeyAppTest['region']]) : '' }}
                                </p>
                            @endif
                        </div>
                    @endif
                    @if (is_array($valkeyTest))
                        <x-sheet.note :tone="$valkeyTest['ok'] ? 'ok' : 'danger'" class="font-semibold">
                            {{ $valkeyTest['ok'] ? __('Working. Every command answered.') : __('Failed: :error', ['error' => $valkeyTest['error']]) }}
                        </x-sheet.note>
                        @if ($valkeyTest['steps'] !== [])
                            <x-sheet.table>
                                <table>
                                    <tbody>
                                        @foreach ($valkeyTest['steps'] as $step)
                                            <tr>
                                                <td>{{ $step['step'] }}</td>
                                                <td class="text-right tabular-nums">{{ number_format($step['ms'], 1) }} ms</td>
                                                {{-- A failed step keeps its warning colour; the wrapper's text colour is on td, so the tone goes on an inner span. --}}
                                                <td><span @class(['text-rose-700 dark:text-rose-300' => $step['result'] === 'failed'])>{{ \Illuminate\Support\Str::limit($step['result'], 24) }}</span></td>
                                            </tr>
                                        @endforeach
                                        @if ($valkeyTest['ping_median_ms'] !== null)
                                            <tr>
                                                <td>{{ __('PING ×10') }}</td>
                                                <td class="text-right tabular-nums">{{ number_format($valkeyTest['ping_median_ms'], 1) }} ms</td>
                                                <td>{{ __('median · slowest :max ms', ['max' => number_format($valkeyTest['ping_max_ms'], 1)]) }}</td>
                                            </tr>
                                        @endif
                                    </tbody>
                                </table>
                            </x-sheet.table>
                        @endif
                    @endif
                </div>

                <div x-show="tab === 'costs'" class="grid gap-3">
                    <x-sheet.cost :label="__('So far this month')">{{ '$'.\App\Modules\Edge\Support\EdgeValkey::money($valkeySpent) }}</x-sheet.cost>
                    <div>
                        <x-sheet.stat :label="__('Rate')" class="tabular-nums">{{ __('$:hour per hour while awake', ['hour' => number_format($valkeySpec['per_second'] * 3600, 4)]) }}</x-sheet.stat>
                        <x-sheet.stat :label="__('Monthly cap')" class="tabular-nums">{{ __('$:cap. Never more than this, even if it never sleeps.', ['cap' => number_format($valkeySpec['cap_cents'] / 100, 0)]) }}</x-sheet.stat>
                        <x-sheet.stat :label="__('Asleep')">{{ $valkeySpec['sleeps'] ? __('Not billed.') : __('Pro sizes do not sleep.') }}</x-sheet.stat>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-sheet.button wire:click="refreshValkeyAwake" wire:loading.attr="disabled" wire:target="refreshValkeyAwake">
                            <x-spinner size="sm" wire:loading wire:target="refreshValkeyAwake" />
                            {{ __('Refresh awake time') }}
                        </x-sheet.button>
                        <span class="text-2xs text-brand-mist">{{ __('Shown exactly. The invoice rounds the month\'s total once, to the nearest cent.') }}</span>
                    </div>
                </div>

            </div>

            @php $valkeyHostJs = \Illuminate\Support\Js::from($valkeyConnection['host']); @endphp
            <x-sheet.section :title="$valkeyConnection['asleep'] ? __('Asleep') : __('Sleep')">
                <p class="text-xs leading-5 text-brand-moss">
                    {{ $valkeyConnection['asleep']
                        ? __('Not billed while asleep. Waking gives the app its Redis address again on the next deploy.')
                        : __('Takes the Redis address off the app on the next deploy. Keys are kept and billing stops a minute after the app lets go.') }}
                </p>
                <div>
                    <x-sheet.button wire:click="sleepConnection({{ $valkeyHostJs }}, {{ $valkeyConnection['asleep'] ? 'false' : 'true' }})" wire:loading.attr="disabled" wire:target="sleepConnection">
                        {{ $valkeyConnection['asleep'] ? __('Wake') : __('Put to sleep') }}
                    </x-sheet.button>
                </div>
            </x-sheet.section>

            <x-sheet.danger :title="__('Delete')">
                <p class="text-xs leading-5 text-brand-moss">{{ __('Deletes this Valkey and every key in it, and takes REDIS_URL off the app on the next deploy.') }}</p>
                <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ $valkeyHostJs }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Delete Valkey') }}</x-sheet.button></div>
            </x-sheet.danger>
            </div>
        @endif
    </x-sheet.body>
</x-sheet>
