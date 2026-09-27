@php
    $externalRedis = collect($connections)->firstWhere('host', $resourceHost);
    $externalRedis = is_array($externalRedis) && $externalRedis['kind'] === 'redis' && ! \App\Modules\Edge\Support\EdgeValkey::isTarget($externalRedis['target']) ? $externalRedis : null;
@endphp
<x-sheet name="resources-redis-external" maxWidth="3xl" focusable>
    @if ($externalRedis)
        @php
            $externalRedisRows = $this->externalRedisEnv;
            $externalRedisAddress = collect($externalRedisRows)->firstWhere('key', 'REDIS_URL')['value'] ?? null;
        @endphp
        <x-sheet.header :eyebrow="$externalRedis['name']" :title="__('Redis')" close-wire="$set('resourceHost', '')">
            {{ __('An address you pasted. dply does not run or bill this Redis; the app connects to it directly.') }}
        </x-sheet.header>

        <x-sheet.body>
            <x-sheet.section :title="__('Address')">
                @if ($externalRedisAddress)
                    <p class="break-all font-mono text-xs text-brand-ink">{{ $externalRedisAddress }}</p>
                @elseif ($externalRedis['asleep'])
                    <x-sheet.note>{{ __('Asleep. The app does not get the address until you wake it. The saved address is kept.') }}</x-sheet.note>
                @else
                    <x-sheet.note tone="warn">{{ __('No address is saved. Paste one below.') }}</x-sheet.note>
                @endif
            </x-sheet.section>

            @if ($externalRedisRows !== [])
                <x-sheet.section :title="__('Set on the app')">
                    <div>
                        @foreach ($externalRedisRows as $row)
                            <x-sheet.stat :label="$row['key']">{{ $row['value'] }}</x-sheet.stat>
                        @endforeach
                    </div>
                    <p class="text-2xs leading-4 text-brand-mist">{{ __('Applied on each deploy.') }}</p>
                </x-sheet.section>
            @endif

            <x-sheet.section :title="__('Test connection')">
                <p class="text-xs text-brand-moss">{{ __('Connects from dply, signs in, and sends PING. The host must be reachable from the internet.') }}</p>
                <div><x-sheet.button wire:click="testExternalRedis" wire:loading.attr="disabled" wire:target="testExternalRedis">{{ __('Test connection') }}</x-sheet.button></div>
                @if ($externalRedisTest)
                    <x-sheet.note :tone="$externalRedisTest['ok'] ? 'ok' : 'danger'">{{ $externalRedisTest['message'] }}</x-sheet.note>
                @endif
            </x-sheet.section>

            <x-sheet.section :title="__('Replace address')">
                <x-sheet.field :label="__('New address')" for="external-redis-url" :help="__('redis:// or rediss://. Stored encrypted; the app uses it on the next deploy.')">
                    <input id="external-redis-url" type="password" wire:model="externalRedisUrl" autocomplete="off" spellcheck="false" placeholder="rediss://default:password@host:6379" class="dply-input mt-0 font-mono" />
                </x-sheet.field>
                <x-input-error :messages="$errors->get('externalRedis')" />
                <div><x-sheet.button variant="primary" wire:click="replaceExternalRedisUrl">{{ __('Save address') }}</x-sheet.button></div>
            </x-sheet.section>

            <x-sheet.danger :title="__('Delete')">
                <p class="text-xs leading-5 text-brand-moss">{{ __('Removes REDIS_URL and the other Redis values from this app. The Redis itself, and its data, stay where they are.') }}</p>
                <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($externalRedis['host']) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Remove from this app') }}</x-sheet.button></div>
            </x-sheet.danger>
        </x-sheet.body>
    @endif
</x-sheet>
