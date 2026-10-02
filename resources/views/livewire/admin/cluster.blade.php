@php
    use App\Livewire\Admin\Cluster;

    $th = 'px-3 py-2 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist sm:px-4';
    $td = 'px-3 py-2 align-top sm:px-4';
    $section = 'border-b border-brand-ink/10 px-3 py-2.5 text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss sm:px-4';
@endphp

<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('Cluster'), 'icon' => 'server-stack'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Cluster')"
        :description="__('The DOKS cluster behind builds, Valkey and dply databases: nodes, pods, recent warnings and logs. Read-only.')"
        icon="heroicon-o-server-stack"
    >
        <x-slot:actions>
            <x-secondary-button size="sm" wire:click="$refresh" wire:loading.attr="disabled">
                <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" aria-hidden="true" />
                {{ __('Refresh') }}
            </x-secondary-button>
        </x-slot:actions>

        @if (! $configured)
            <div class="px-4 py-6 text-sm text-brand-moss">
                {{ __('Not configured. Apply deploy/k8s-readonly/rbac.yaml to the cluster and set DPLY_K8S_API_URL, DPLY_K8S_TOKEN and DPLY_K8S_CA (the file has the commands).') }}
            </div>
        @elseif ($error)
            <div class="m-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                <p class="font-semibold">{{ __('Could not read the cluster.') }}</p>
                <p class="mt-1 break-all font-mono text-xs">{{ \Illuminate\Support\Str::limit($error, 500) }}</p>
            </div>
        @else
            {{-- Logs --}}
            @if ($logPod)
                <div class="border-b border-brand-ink/10">
                    <div class="flex flex-wrap items-center gap-2 px-3 py-2.5 sm:px-4">
                        <span class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Logs') }}</span>
                        <span class="font-mono text-xs text-brand-ink">{{ $logNamespace }}/{{ $logPod }} · {{ $logContainer }}</span>
                        <label class="ml-auto inline-flex items-center gap-1.5 text-xs text-brand-moss">
                            <input type="checkbox" wire:model.live="logPrevious" class="rounded border-brand-ink/20" />
                            {{ __('Previous container') }}
                        </label>
                        <x-secondary-button size="xs" wire:click="closeLogs">{{ __('Close') }}</x-secondary-button>
                    </div>
                    @if ($logError)
                        <p class="px-4 pb-3 font-mono text-xs text-red-800">{{ \Illuminate\Support\Str::limit($logError, 500) }}</p>
                    @else
                        <pre class="max-h-[28rem] overflow-auto bg-brand-ink px-4 py-3 font-mono text-2xs leading-relaxed text-brand-cream">{{ $logs !== '' ? $logs : __('(no output)') }}</pre>
                    @endif
                </div>
            @endif

            {{-- Nodes --}}
            <p class="{{ $section }}">{{ __('Nodes') }} · {{ count($nodes) }}</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-brand-ink/10 text-left">
                            <th class="{{ $th }}">{{ __('Node') }}</th>
                            <th class="{{ $th }}">{{ __('Pool') }}</th>
                            <th class="{{ $th }}">{{ __('Size') }}</th>
                            <th class="{{ $th }}">{{ __('Allocatable') }}</th>
                            <th class="{{ $th }}">{{ __('Status') }}</th>
                            <th class="{{ $th }}">{{ __('Age') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5">
                        @foreach ($nodes as $node)
                            <tr wire:key="node-{{ $node['name'] }}">
                                <td class="{{ $td }} font-mono text-xs">{{ $node['name'] }}</td>
                                <td class="{{ $td }} text-xs text-brand-moss">{{ $node['pool'] }}</td>
                                <td class="{{ $td }} font-mono text-xs">{{ $node['size'] }}</td>
                                <td class="{{ $td }} font-mono text-xs text-brand-moss">{{ $node['cpu'] }} CPU · {{ $node['memory'] }}</td>
                                <td class="{{ $td }}"><x-badge size="sm" :tone="$node['ready'] ? 'success' : 'danger'">{{ $node['ready'] ? __('Ready') : __('Not ready') }}</x-badge></td>
                                <td class="{{ $td }} text-xs text-brand-moss">{{ $node['age'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pods by namespace --}}
            <p class="{{ $section }} border-t">
                {{ __('Pods') }}
                @unless ($metrics)
                    <span class="ml-2 font-normal normal-case tracking-normal text-brand-mist">{{ __('(no metrics: is metrics-server running?)') }}</span>
                @endunless
            </p>
            @foreach ($groups as $group)
                <div x-data="{ open: @js($group['open']) }" wire:key="ns-{{ $group['namespace'] }}" class="border-b border-brand-ink/10">
                    <button type="button" x-on:click="open = ! open" class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-brand-sand/30 sm:px-4" :aria-expanded="open">
                        <x-heroicon-m-chevron-right class="h-4 w-4 text-brand-mist transition-transform" ::class="open && 'rotate-90'" aria-hidden="true" />
                        <span class="font-mono text-sm font-semibold text-brand-ink">{{ $group['namespace'] }}</span>
                        <span class="text-xs text-brand-moss">
                            {{ trans_choice(':count pod|:count pods', count($group['pods'])) }}
                            · {{ trans_choice(':count restart|:count restarts', $group['restarts']) }}
                            · {{ $group['notReady'] === 0 ? __('all ready') : __(':count not ready', ['count' => $group['notReady']]) }}
                        </span>
                        @if ($group['trouble'])
                            <x-badge size="sm" tone="warning">{{ __('Needs a look') }}</x-badge>
                        @endif
                    </button>
                    <div x-show="open" x-cloak class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-brand-ink/10 text-left">
                                    <th class="{{ $th }}">{{ __('Pod') }}</th>
                                    <th class="{{ $th }}">{{ __('Status') }}</th>
                                    <th class="{{ $th }}">{{ __('Restarts') }}</th>
                                    <th class="{{ $th }}">{{ __('Last termination') }}</th>
                                    <th class="{{ $th }}">{{ __('Memory / limit') }}</th>
                                    <th class="{{ $th }}">{{ __('CPU') }}</th>
                                    <th class="{{ $th }}">{{ __('Image') }}</th>
                                    <th class="{{ $th }}">{{ __('Node') }}</th>
                                    <th class="{{ $th }}">{{ __('Age') }}</th>
                                    <th class="{{ $th }}">{{ __('Logs') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5">
                                @foreach ($group['pods'] as $pod)
                                    <tr wire:key="pod-{{ $pod['namespace'] }}-{{ $pod['name'] }}" @class(['bg-amber-50/50' => $pod['trouble']])>
                                        <td class="{{ $td }} font-mono text-xs text-brand-ink">{{ $pod['name'] }}</td>
                                        <td class="{{ $td }} whitespace-nowrap">
                                            <x-badge size="sm" :caps="false" :tone="$pod['ready'] ? 'success' : 'danger'">{{ $pod['status'] }}</x-badge>
                                            <span class="ml-1 font-mono text-2xs text-brand-mist">{{ $pod['readyText'] }}</span>
                                        </td>
                                        <td @class([$td, 'font-mono text-xs', 'font-semibold text-red-800' => $pod['restarts'] > 0])>{{ $pod['restarts'] }}</td>
                                        <td class="{{ $td }} whitespace-nowrap text-xs">
                                            @if ($pod['last'])
                                                <span @class(['font-semibold', 'text-red-800' => $pod['last']['recent'], 'text-brand-moss' => ! $pod['last']['recent']])>{{ $pod['last']['reason'] }}</span>
                                                <span class="text-brand-mist">{{ $pod['last']['container'] }} · {{ __(':ago ago', ['ago' => $pod['last']['ago']]) }}@if ($pod['last']['exitCode'] !== null) · {{ __('exit :code', ['code' => $pod['last']['exitCode']]) }}@endif</span>
                                            @else
                                                <span class="text-brand-mist">—</span>
                                            @endif
                                        </td>
                                        <td class="{{ $td }} whitespace-nowrap font-mono text-xs">
                                            @foreach ($pod['containers'] as $c)
                                                @php $pct = $c['memory'] !== null && $c['limit'] ? $c['memory'] / $c['limit'] : null; @endphp
                                                <span @class(['block', 'font-semibold text-red-800' => $pct !== null && $pct >= 0.85])>
                                                    @if (count($pod['containers']) > 1)<span class="text-brand-mist">{{ $c['name'] }}</span> @endif{{ Cluster::bytes($c['memory']) }} / {{ $c['limit'] !== null ? Cluster::bytes($c['limit']) : __('no limit') }}
                                                </span>
                                            @endforeach
                                        </td>
                                        <td class="{{ $td }} whitespace-nowrap font-mono text-xs text-brand-moss">
                                            @php $cpu = array_sum(array_map(fn ($c) => (float) $c['cpu'], $pod['containers'])); @endphp
                                            {{ $metrics ? round($cpu * 1000).'m' : '—' }}
                                        </td>
                                        <td class="{{ $td }} font-mono text-xs text-brand-moss" title="{{ $pod['image'] }}">{{ $pod['tag'] }}</td>
                                        <td class="{{ $td }} font-mono text-2xs text-brand-mist">{{ $pod['node'] }}</td>
                                        <td class="{{ $td }} text-xs text-brand-moss">{{ $pod['age'] }}</td>
                                        <td class="{{ $td }} whitespace-nowrap">
                                            @foreach ($pod['containers'] as $c)
                                                <button type="button" wire:click="showLogs(@js($pod['namespace']), @js($pod['name']), @js($c['name']))" class="text-xs font-semibold text-brand-forest hover:underline">{{ count($pod['containers']) > 1 ? $c['name'] : __('view') }}</button>
                                            @endforeach
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach

            {{-- Events --}}
            <p class="{{ $section }}">{{ __('Warning events · last hour') }} · {{ count($events) }}</p>
            @if ($events === [])
                <p class="px-4 py-4 text-sm text-brand-moss">{{ __('No warnings in the last hour.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-brand-ink/10 text-left">
                                <th class="{{ $th }}">{{ __('When') }}</th>
                                <th class="{{ $th }}">{{ __('Object') }}</th>
                                <th class="{{ $th }}">{{ __('Reason') }}</th>
                                <th class="{{ $th }}">{{ __('Message') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($events as $event)
                                <tr wire:key="event-{{ $loop->index }}">
                                    <td class="{{ $td }} whitespace-nowrap text-xs text-brand-moss">{{ __(':ago ago', ['ago' => $event['ago']]) }}@if ($event['count'] > 1) <span class="text-brand-mist">×{{ $event['count'] }}</span>@endif</td>
                                    <td class="{{ $td }} font-mono text-xs">{{ $event['namespace'] }}/{{ $event['object'] }}</td>
                                    <td class="{{ $td }} text-xs font-semibold text-amber-900">{{ $event['reason'] }}</td>
                                    <td class="{{ $td }} text-xs text-brand-ink">{{ $event['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </x-profile-shell>
</div>
