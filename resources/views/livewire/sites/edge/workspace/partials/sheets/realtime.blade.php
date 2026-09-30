@php
    $rtConnection = collect($connections)->firstWhere('host', $resourceHost);
    $rtConnection = is_array($rtConnection) && $rtConnection['kind'] === 'realtime' ? $rtConnection : null;
    $rtApp = $rtConnection === null || $rtConnection['target'] === '' ? null : \App\Models\EdgeRealtimeApp::query()
        ->whereKey($rtConnection['target'])->where('organization_id', $site->organization_id)->first();
@endphp
<x-sheet name="resources-realtime" maxWidth="3xl" focusable>
    @if ($rtApp === null)
        <x-sheet.header :title="__('Realtime')" close-wire="$set('resourceHost', '')" />
        <x-sheet.body><p class="text-xs text-brand-moss">{{ $resourceHost === '' ? __('Loading Realtime…') : __('This Realtime app is no longer attached to this app.') }}</p></x-sheet.body>
    @else
        @php
            $rtHost = \App\Modules\Edge\Services\Realtime\EdgeRealtimeApps::hostFor($rtApp);
            $rtAsleep = $rtConnection['asleep'] || $rtApp->status !== \App\Models\EdgeRealtimeApp::STATUS_ACTIVE;
            $rtStats = is_array($realtimeStats) && $realtimeLoadedHost === $rtConnection['host'] ? $realtimeStats : null;
            // Masked here: the secret never reaches the page.
            $rtEnv = collect(\App\Modules\Edge\Support\EdgeContainerConnections::realtimeDriverEnv($site))
                ->map(fn (string $value, string $key): string => str_ends_with($key, '_SECRET') ? '••••' : $value)
                ->map(fn (string $value, string $key): string => $key.'='.$value)
                ->implode("\n");
            $rtSizes = $this->realtimeSizes();
            $rtMonth = $this->realtimeMonth($rtApp->id);
            $rtBroadcasting = <<<'PHP'
'reverb' => [
    'driver' => 'reverb',
    'key' => env('REVERB_APP_KEY'),
    'secret' => env('REVERB_APP_SECRET'),
    'app_id' => env('REVERB_APP_ID'),
    'options' => [
        'host' => env('REVERB_HOST'),
        'port' => env('REVERB_PORT', 443),
        'scheme' => env('REVERB_SCHEME', 'https'),
        'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
    ],
],
PHP;
            $rtEcho = <<<'JS'
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
JS;
            $rtChannels = <<<'PHP'
use Illuminate\Support\Facades\Broadcast;

// Public channels need no entry. Private and presence channels are
// authorised by your app at POST /broadcasting/auth.
Broadcast::channel('orders.{order}', function ($user, App\Models\Order $order) {
    return $user->id === $order->user_id;
});

// Anywhere in the app:
broadcast(new App\Events\OrderShipped($order));
Echo.private(`orders.${orderId}`).listen('OrderShipped', (e) => console.log(e));
PHP;
            $rtNodeClient = "import Pusher from 'pusher-js';\n\nconst pusher = new Pusher('".$rtApp->app_key."', {\n  wsHost: '".$rtHost."',\n  wssPort: 443,\n  forceTLS: true,\n  enabledTransports: ['ws', 'wss'],\n  cluster: 'mt1',\n});\n\npusher.subscribe('orders').bind('order.shipped', (data) => console.log(data));";
            $rtNodeServer = "import Pusher from 'pusher';\n\nconst pusher = new Pusher({\n  appId: process.env.PUSHER_APP_ID,\n  key: process.env.PUSHER_APP_KEY,\n  secret: process.env.PUSHER_APP_SECRET,\n  host: process.env.PUSHER_HOST,\n  port: '443',\n  useTLS: true,\n});\n\nawait pusher.trigger('orders', 'order.shipped', { id: 42 });";
            $rtWorkerServer = "import Pusher from 'pusher';\n\n// In a request handler; the values are Worker bindings.\nconst pusher = new Pusher({\n  appId: env.PUSHER_APP_ID,\n  key: env.PUSHER_APP_KEY,\n  secret: env.PUSHER_APP_SECRET,\n  host: env.PUSHER_HOST,\n  port: '443',\n  useTLS: true,\n});\n\nawait pusher.trigger('orders', 'order.shipped', { id: 42 });";
            $pre = 'overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950';
        @endphp
        <x-sheet.header :eyebrow="$rtHost" :title="$rtApp->name" close-wire="$set('resourceHost', '')">
            <span class="inline-flex items-center gap-2">
                {{ __('Realtime · WebSockets for Reverb, Echo and Pusher clients') }}
                <span @class([
                    'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs font-semibold',
                    'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => ! $rtAsleep,
                    'bg-violet-500/10 text-violet-700 dark:text-violet-300' => $rtAsleep,
                ])><span class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ $rtAsleep ? __('Asleep') : __('Active') }}</span>
            </span>
        </x-sheet.header>

        <x-sheet.body wire:key="realtime-{{ $rtConnection['host'] }}" x-init="$wire.$island('resources-realtime').realtimeLoad()">
            <div class="grid content-start gap-4" x-data="{ tab: 'overview' }">
                <x-sheet.tabs>
                    <button type="button" role="tab" x-on:click="tab = 'overview'" :aria-selected="tab === 'overview' ? 'true' : 'false'">{{ __('Overview') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'connect'" :aria-selected="tab === 'connect' ? 'true' : 'false'">{{ __('Connect') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'try'" :aria-selected="tab === 'try' ? 'true' : 'false'">{{ __('Try it') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'credentials'" :aria-selected="tab === 'credentials' ? 'true' : 'false'">{{ __('Credentials') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'settings'" :aria-selected="tab === 'settings' ? 'true' : 'false'">{{ __('Settings') }}</button>
                </x-sheet.tabs>

                {{-- Overview --}}
                <div x-show="tab === 'overview'" class="grid gap-4">
                    @if ($realtimeError)
                        <x-sheet.note tone="warn">{{ $realtimeError }}</x-sheet.note>
                    @endif
                    <x-sheet.metrics :cols="4">
                        <x-sheet.metric :label="__('Connections')" :note="__('Open now, of :max', ['max' => number_format($rtApp->max_connections)])">
                            {{ $rtStats === null ? ($realtimeError ? '—' : '…') : number_format($rtStats['connections']) }}
                        </x-sheet.metric>
                        <x-sheet.metric :label="__('Peak')" :note="__('Most at once')">
                            {{ $rtStats === null ? ($realtimeError ? '—' : '…') : number_format($rtStats['peak_connections']) }}
                        </x-sheet.metric>
                        <x-sheet.metric :label="__('Messages')" :note="__('This month, collected daily')">
                            {{ \Illuminate\Support\Number::abbreviate($rtMonth['messages'], maxPrecision: 1) }}
                        </x-sheet.metric>
                        <x-sheet.metric :label="__('Cost')" :note="__('This month so far')">
                            {{ '$'.number_format($rtMonth['cents'] / 100, 2) }}
                        </x-sheet.metric>
                    </x-sheet.metrics>
                    @php($rtAbbr = fn (int $n) => \Illuminate\Support\Number::abbreviate($n, maxPrecision: 1))
                    <x-sheet.note>{{ __('Across all Realtime apps this month: :uc connection-minutes and :um messages. :c per million connection-minutes and :m per million messages, paid from your plan’s included usage credit first.', ['c' => \App\Modules\Billing\Support\UsagePrice::dollars(\App\Modules\Billing\Support\UsagePrice::rate('realtime_connection_minute_millicents') * 1_000_000), 'm' => \App\Modules\Billing\Support\UsagePrice::dollars(\App\Modules\Billing\Support\UsagePrice::rate('realtime_message_millicents_per_million')), 'uc' => $rtAbbr($rtMonth['org_minutes']), 'um' => $rtAbbr($rtMonth['org_messages'])]) }}</x-sheet.note>
                    @if ($rtAsleep)
                        <x-sheet.note>{{ __('Asleep. The relay refuses connections and broadcasts until you wake it. The app keeps its keys, so waking needs no redeploy.') }}</x-sheet.note>
                    @endif
                    <p class="text-2xs leading-4 text-brand-mist">{{ __('Browsers connect to wss://:host. The app publishes over HTTPS to the same host and signs private channels itself at /broadcasting/auth. No Reverb process runs in the app.', ['host' => $rtHost]) }}</p>
                    <div><x-sheet.button wire:click="realtimeLoad" wire:loading.attr="disabled" wire:target="realtimeLoad">{{ __('Refresh') }}</x-sheet.button></div>
                    @unless ($cardOnFile)
                        <x-sheet.note tone="warn">{{ __('This counts against the usage credit until a card is on the account.') }}</x-sheet.note>
                    @endunless
                </div>

                {{-- Connect --}}
                <div x-show="tab === 'connect'" x-cloak class="grid gap-5">
                    <x-sheet.section :title="$isWorker ? __('Bindings the app receives') : __('Env the app receives')">
                        <p class="text-xs text-brand-moss">{{ $isWorker
                            ? __('The next deploy binds these on the Worker (the secret as a secret). A value you set in Environment wins.')
                            : __('The next deploy sets these. The VITE_* values also reach the asset build, because Vite bakes them into the JavaScript. A value you set in Environment wins.') }}</p>
                        <pre class="{{ $pre }}">{{ $rtEnv }}</pre>
                    </x-sheet.section>

                    @if ($isWorker)
                        <x-sheet.section :title="__('Publish from the Worker')">
                            <p class="text-xs text-brand-moss">{{ __('Any Pusher server SDK works. The pusher package signs with Node crypto, so the Worker needs the nodejs_compat flag.') }}</p>
                            <pre class="{{ $pre }}">{{ $rtWorkerServer }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('Listen in the browser')">
                            <pre class="{{ $pre }}">{{ $rtNodeClient }}</pre>
                        </x-sheet.section>
                    @else
                        <x-sheet.section :title="__('Laravel: config/broadcasting.php')">
                            <p class="text-xs text-brand-moss">{{ __('The stock reverb connection from Laravel 11 and 12 works unchanged. php artisan install:broadcasting --reverb adds it, echo.js and routes/channels.php. Do not run reverb:start; dply runs the server.') }}</p>
                            <pre class="{{ $pre }}">{{ $rtBroadcasting }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('resources/js/echo.js')">
                            <pre class="{{ $pre }}">{{ $rtEcho }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('routes/channels.php')">
                            <p class="text-xs text-brand-moss">{{ __('/broadcasting/auth is your app’s own route (Broadcast::routes(), added by install:broadcasting). The relay never sees your users.') }}</p>
                            <pre class="{{ $pre }}">{{ $rtChannels }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('Node or any other stack')">
                            <p class="text-xs text-brand-moss">{{ __('It speaks the Pusher protocol, so any Pusher client and server SDK works. Browser:') }}</p>
                            <pre class="{{ $pre }}">{{ $rtNodeClient }}</pre>
                            <p class="text-xs text-brand-moss">{{ __('Server (npm pusher):') }}</p>
                            <pre class="{{ $pre }}">{{ $rtNodeServer }}</pre>
                        </x-sheet.section>
                    @endif
                </div>

                {{-- Try it: a socket from this page, a signed publish from dply. wire:ignore keeps morphs off the live log. --}}
                <div
                    x-show="tab === 'try'"
                    x-cloak
                    wire:ignore
                    class="grid gap-3"
                    x-data="dplyRealtimeConsole({ host: {{ \Illuminate\Support\Js::from($rtHost) }}, appKey: {{ \Illuminate\Support\Js::from($rtApp->app_key) }}, channel: 'dply-test.' + Math.random().toString(36).slice(2, 12).padEnd(8, '0') })"
                    x-effect="$store.sheets.stack.includes('resources-realtime') || (socket && disconnect())"
                >
                    <p class="text-xs text-brand-moss">{{ __('Connects this page to the relay with the app key and listens on a throwaway public channel. Send publishes a signed dply.test event from dply. When it shows up below, Realtime works end to end.') }}</p>
                    @if ($rtAsleep)
                        <x-sheet.note>{{ __('Asleep. Wake Realtime to try it.') }}</x-sheet.note>
                    @endif
                    <div class="flex flex-wrap items-center gap-2">
                        <x-sheet.button x-on:click="toggle()"><span x-text="connected || state === 'connecting' ? {{ \Illuminate\Support\Js::from(__('Disconnect')) }} : {{ \Illuminate\Support\Js::from(__('Connect')) }}"></span></x-sheet.button>
                        @can('update', $site)
                            <x-sheet.button variant="primary" x-bind:disabled="state !== 'subscribed'" x-on:click="sendTest((c, n) => $wire.$island('resources-realtime').realtimeSendTest(c, n))">{{ __('Send test event') }}</x-sheet.button>
                        @endcan
                        <span class="font-mono text-2xs text-brand-mist" x-text="statusLabel + ' · ' + channel"></span>
                    </div>
                    <template x-if="state === 'error'">
                        <x-sheet.note tone="danger"><span x-text="error"></span> {{ __('If this keeps happening, the relay may not be deployed yet, the app may be asleep, or an allowed-origins list may not include this dashboard.') }}</x-sheet.note>
                    </template>
                    <div class="h-56 overflow-y-auto rounded-xl bg-brand-ink px-3 py-2 font-mono text-2xs leading-relaxed">
                        <template x-if="log.length === 0">
                            <p class="py-8 text-center text-brand-cream/40">{{ __('Nothing yet. Connect, then send a test event.') }}</p>
                        </template>
                        <template x-for="entry in log" :key="entry.id">
                            <div class="flex gap-2 border-b border-brand-cream/5 py-1 last:border-0">
                                <span class="shrink-0 text-brand-cream/30" x-text="entry.at"></span>
                                <span class="shrink-0 font-semibold" :class="entry.kind === 'event' ? 'text-brand-sage' : (entry.kind === 'error' ? 'text-rose-300' : 'text-brand-cream/50')" x-text="entry.event ?? entry.kind"></span>
                                <span class="min-w-0 flex-1 break-all text-brand-cream/75" x-text="entry.message"></span>
                                <span x-show="entry.latency !== null" class="shrink-0 text-brand-sage" x-text="entry.latency + ' ms'"></span>
                            </div>
                        </template>
                    </div>
                    <p class="text-2xs leading-4 text-brand-mist">{{ __('Latency is from pressing Send to the event arriving here, through dply and the relay. Your app publishing directly is faster.') }}</p>
                </div>

                {{-- Credentials --}}
                <div x-show="tab === 'credentials'" x-cloak class="grid gap-4">
                    <div>
                        @foreach ([__('App ID') => $rtApp->id, __('Key') => $rtApp->app_key, __('Host') => $rtHost] as $rtLabel => $rtValue)
                            <x-sheet.stat :label="$rtLabel">
                                <span class="inline-flex items-center gap-2">
                                    {{ $rtValue }}
                                    <button type="button" x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($rtValue) }})" class="font-sans text-2xs font-semibold text-brand-sage hover:underline">{{ __('Copy') }}</button>
                                </span>
                            </x-sheet.stat>
                        @endforeach
                        <x-sheet.stat :label="__('Secret')">
                            <span x-data="{ secret: '' }" class="inline-flex items-center gap-2">
                                <span class="break-all" x-text="secret || '••••••••'"></span>
                                @can('update', $site)
                                    <button type="button" x-show="! secret" x-on:click="secret = await $wire.$island('resources-realtime').realtimeSecret()" class="font-sans text-2xs font-semibold text-brand-sage hover:underline">{{ __('Show') }}</button>
                                    <button type="button" x-show="secret" x-cloak x-on:click="navigator.clipboard.writeText(secret)" class="font-sans text-2xs font-semibold text-brand-sage hover:underline">{{ __('Copy') }}</button>
                                @endcan
                            </span>
                        </x-sheet.stat>
                    </div>
                    <p class="text-2xs leading-4 text-brand-mist">{{ __('The key is public: browsers use it to connect. The secret signs publishes; keep it on the server. The app gets it as REVERB_APP_SECRET and PUSHER_APP_SECRET.') }}</p>
                    @can('update', $site)
                        <x-sheet.danger :title="__('Rotate the secret')">
                            <p class="text-xs text-brand-moss">{{ __('Makes a new secret at once. Browsers keep working, since the key does not change, but the running app keeps signing with the old secret, so its broadcasts are refused until you redeploy.') }}</p>
                            @error('realtimeSecret')<p class="text-xs text-rose-700 dark:text-rose-300">{{ $message }}</p>@enderror
                            <div><x-sheet.button variant="danger" wire:click="realtimeRotateSecret" wire:confirm="{{ __('Rotate the secret? The app cannot broadcast until it is redeployed.') }}" wire:loading.attr="disabled" wire:target="realtimeRotateSecret">{{ __('Rotate secret') }}</x-sheet.button></div>
                        </x-sheet.danger>
                    @endcan
                </div>

                {{-- Settings --}}
                <div x-show="tab === 'settings'" x-cloak class="grid gap-4">
                    <x-sheet.field :label="__('Max connections')" :help="__('Sockets open at once. The relay refuses connections beyond this.')">
                        {{-- Picked on the client (a server $set re-rendered for over a second per click); sent with Save. --}}
                        <x-sheet.segmented x-data="{ max: $wire.entangle('realtimeEditMax') }">
                            @foreach (\App\Modules\Edge\Services\Realtime\EdgeRealtimeApps::MAX_CONNECTION_SIZES as $rtSize)
                                <x-sheet.segment :bind="'max === '.$rtSize" :disabled="! in_array($rtSize, $rtSizes, true)" x-on:click="max = {{ $rtSize }}">{{ number_format($rtSize) }}</x-sheet.segment>
                            @endforeach
                        </x-sheet.segmented>
                    </x-sheet.field>
                    <x-sheet.field :label="__('Allowed origins')" for="realtime-origins" :help="__('One per line, like https://example.com. Browsers from other origins cannot connect. Empty allows any.')">
                        <textarea id="realtime-origins" wire:model="realtimeEditOrigins" rows="3" spellcheck="false" class="dply-input mt-0 font-mono text-xs" placeholder="https://example.com"></textarea>
                    </x-sheet.field>
                    <div>
                        <x-sheet.toggle :label="__('Client events')" :help="__('Let browsers send client-* events to each other on private and presence channels (Echo whisper).')" wire:model="realtimeEditClientEvents" />
                    </div>
                    @error('realtimeSettings')<x-sheet.note tone="danger">{{ $message }}</x-sheet.note>@enderror
                    @can('update', $site)
                        <div class="flex flex-wrap items-center gap-3">
                            <x-sheet.button variant="primary" wire:click="realtimeSaveSettings" wire:loading.attr="disabled" wire:target="realtimeSaveSettings">{{ __('Save') }}</x-sheet.button>
                            <p class="text-2xs text-brand-mist">{{ __('Applies at the relay right away. No redeploy needed.') }}</p>
                        </div>
                    @endcan
                </div>

                @can('update', $site)
                    {{-- Sleep: like the other resources. --}}
                    <x-sheet.section :title="__('Sleep')">
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 px-3.5 py-2.5 dark:border-brand-mist/15">
                            <p class="min-w-0 flex-1 text-xs text-brand-moss">
                                {{ $rtConnection['asleep']
                                    ? __('Asleep. Waking lets browsers connect again right away, with no redeploy.')
                                    : __('Sleeping makes the relay refuse connections and broadcasts, so nothing bills. The app keeps its keys.') }}
                            </p>
                            <x-sheet.button wire:click="sleepConnection({{ \Illuminate\Support\Js::from($rtConnection['host']) }}, {{ $rtConnection['asleep'] ? 'false' : 'true' }})" wire:island="resources-realtime">{{ $rtConnection['asleep'] ? __('Wake') : __('Sleep') }}</x-sheet.button>
                        </div>
                    </x-sheet.section>

                    <x-sheet.danger :title="__('Delete Realtime')">
                        <p class="text-xs text-brand-moss">{{ __('Removes this Realtime app and disconnects every browser. The app loses its Realtime keys on the next deploy. This cannot be undone.') }}</p>
                        <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($rtConnection['host']) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Delete Realtime') }}</x-sheet.button></div>
                    </x-sheet.danger>
                @endcan
            </div>
        </x-sheet.body>
    @endif
</x-sheet>
