<x-sheet name="resources-connection" :show="$panel === 'connection'" maxWidth="lg" focusable>
    @if ($connectionKind === '')
        <x-sheet.header :title="__('Add a resource')" close-wire="$set('panel', '')" />

        <x-sheet.body>
            @php
                $visibleKinds = collect($connectionKinds)->filter(fn ($kind, $key) => in_array($key, $allowedKinds, true)
                    && ! in_array($key, \App\Modules\Edge\Support\EdgeContainerConnections::HIDDEN_FROM_BUILDER, true)
                    // Behind a feature flag for this organization (dply:feature).
                    && \App\Modules\Edge\Support\EdgeContainerConnections::flagOn($key, $site->organization)
                    // One per app: hide once added.
                    && ! (in_array($key, ['redis', 'realtime', ...\App\Modules\Edge\Support\EdgeContainerConnections::ENABLE], true) && collect($connections)->contains('kind', $key)));
                $kindGroups = [
                    'background' => ['title' => __('Background work'), 'kinds' => ['queue']],
                    'data' => ['title' => __('Data & storage'), 'kinds' => ['sql', 'database_pool', 'key_value', 'durable_object', 'redis', 'object_storage']],
                    'ai' => ['title' => __('AI & media'), 'kinds' => ['ai', 'vectors', 'images']],
                    'connect' => ['title' => __('Connect'), 'kinds' => ['service', 'realtime']],
                ];
                $grouped = collect($kindGroups)->flatMap(fn ($g) => $g['kinds'])->all();
                // A kind added later without a group still shows, under Connect.
                $kindGroups['connect']['kinds'] = [...$kindGroups['connect']['kinds'], ...$visibleKinds->keys()->diff($grouped)->all()];
                $extras = [
                    'background' => $hasCronWorker || ($isContainer && ! ($workers['enabled'] ?? false)),
                    'data' => true,
                ];
            @endphp
            <div class="grid gap-5">
                @foreach ($kindGroups as $groupKey => $group)
                    @php $groupKinds = $visibleKinds->only($group['kinds']); @endphp
                    @continue($groupKinds->isEmpty() && ! ($extras[$groupKey] ?? false))
                    <x-sheet.section :title="$group['title']">
                        <div class="grid gap-1.5">
                            @if ($groupKey === 'background')
                                @if ($hasCronWorker)
                                    <x-sheet.row wire:click="openPanel('')" wire:island="resources-connection" x-on:click="$dispatch('close-modal', 'resources-connection'); $dispatch('edge-cron-new')" :title="__('Scheduled task')" :hint="__('Run a command on a schedule. Add as many as you need.')"><x-slot:icon><x-heroicon-o-calendar-days /></x-slot:icon></x-sheet.row>
                                @endif
                                @if ($isContainer && ! ($workers['enabled'] ?? false))
                                    <x-sheet.row wire:click="openWorkerSetup" wire:island="resources-worker-setup" x-on:click="$dispatch('close-modal', 'resources-connection'); $dispatch('open-modal', 'resources-worker-setup')" :title="__('Queue workers')" :hint="__('Run jobs in the background. Choose queues and when they run.')"><x-slot:icon><x-resource-kind-icon kind="queue" /></x-slot:icon></x-sheet.row>
                                @endif
                            @endif
                            @if ($groupKey === 'data')
                                {{-- An app can have several (DplyDatabases): always offered. --}}
                                <x-sheet.row wire:click="openAddDatabase" wire:island="resources-database-add" x-on:click="$dispatch('close-modal', 'resources-connection'); $dispatch('open-modal', 'resources-database-add')" :title="__('Database')" :hint="__('Postgres, MySQL or MongoDB. Create one or attach one your organization has.')"><x-slot:icon><x-resource-kind-icon kind="database" /></x-slot:icon></x-sheet.row>
                            @endif
                            @foreach ($group['kinds'] as $key)
                                @continue(! $groupKinds->has($key))
                                @php $kind = $groupKinds[$key]; $locked = in_array($key, \App\Modules\Edge\Support\EdgeContainerConnections::PAID_ONLY, true) && ! $paidFeatures; @endphp
                                <x-sheet.row wire:click="chooseConnectionKind('{{ $key }}')" :disabled="$locked" :class="$locked ? 'cursor-not-allowed opacity-50' : ''" :title="__($kind['label'])" :hint="$locked ? \App\Modules\Edge\Support\EdgeContainerConnections::paidOnlyReason() : (__($kind['hint'] ?? '') ?: null)"><x-slot:icon><x-resource-kind-icon :kind="$key" /></x-slot:icon></x-sheet.row>
                            @endforeach
                        </div>
                    </x-sheet.section>
                @endforeach
            </div>
        </x-sheet.body>
    @else
        <x-sheet.header close-wire="$set('panel', '')" :eyebrow="__('Add a resource')" :title="__($connectionKinds[$connectionKind]['label'])">
            @if ($isWorker)
                {{ __(\App\Modules\Edge\Support\EdgeContainerConnections::WORKER_HINTS[$connectionKind] ?? 'Your code reads it as env.NAME, where NAME is the name you give it here.') }}
            @else
                {{ __($connectionKinds[$connectionKind]['hint']) }}
            @endif
            <x-slot:actions>
                <x-sheet.button wire:click="$set('connectionKind', '')">{{ __('All types') }}</x-sheet.button>
            </x-slot:actions>
        </x-sheet.header>

        <form wire:submit="saveConnection" class="contents">
            <x-sheet.body>
                {{-- Attach existing: only when the organization has one this app does not use yet (Redis attaches by address). --}}
                @if ((in_array($connectionKind, \App\Modules\Edge\Support\EdgeContainerConnections::CREATABLE, true) && $connectionOptions !== []) || $connectionKind === 'redis')
                    <x-sheet.segmented>
                        <x-sheet.segment wire:click="setConnectionMode('create')" :active="$connectionMode === 'create'">{{ __('Create new') }}</x-sheet.segment>
                        <x-sheet.segment wire:click="setConnectionMode('attach')" :active="$connectionMode === 'attach'">{{ __('Attach existing') }}</x-sheet.segment>
                    </x-sheet.segmented>
                @endif

                @if ($connectionMode === 'create' && $connectionKind === 'key_value' && ! $cardOnFile)
                    <x-sheet.note tone="warn">{{ __('Add a card before starting a key-value store. Reads, writes, and storage are billed to that card.') }}</x-sheet.note>
                    @if ($site->organization)
                        <div><x-sheet.button href="{{ route('billing.show', $site->organization) }}">{{ __('Billing') }}</x-sheet.button></div>
                    @endif
                @elseif ($connectionKind === 'realtime' && ! $cardOnFile)
                    <x-sheet.note tone="warn">{{ __('Add a card before starting Realtime. Connections and messages are billed to that card.') }}</x-sheet.note>
                    @if ($site->organization)
                        <div><x-sheet.button href="{{ route('billing.show', $site->organization) }}">{{ __('Billing') }}</x-sheet.button></div>
                    @endif
                @elseif ($connectionKind === 'realtime')
                    <x-sheet.field :label="__('Name')" for="connection-realtime-name" :help="__('Optional. Only shown here.')">
                        <input id="connection-realtime-name" type="text" wire:model="realtimeName" maxlength="100" placeholder="{{ $site->name }}" class="dply-input mt-0" />
                    </x-sheet.field>
                    <x-sheet.field :label="__('Max connections')" :help="__('Open sockets at once. Past this, new connections are refused.')">
                        <x-sheet.segmented>
                            @foreach (\App\Modules\Edge\Services\Realtime\EdgeRealtimeApps::MAX_CONNECTION_SIZES as $size)
                                <x-sheet.segment wire:click="$set('realtimeMaxConnections', {{ $size }})" :active="$realtimeMaxConnections === $size">{{ number_format($size) }}</x-sheet.segment>
                            @endforeach
                        </x-sheet.segmented>
                    </x-sheet.field>
                    <x-sheet.field :label="__('Allowed origins')" for="connection-realtime-origins" :help="__('Optional. One per line, like https://example.com. Empty allows any site to connect.')">
                        <textarea id="connection-realtime-origins" wire:model="realtimeAllowedOrigins" rows="2" spellcheck="false" class="dply-input mt-0 font-mono"></textarea>
                    </x-sheet.field>
                    <x-sheet.note>{{ __('The next deploy sets BROADCAST_CONNECTION=reverb, the REVERB_* and PUSHER_* keys, and VITE_* for the asset build. Your own saved values win.') }}</x-sheet.note>
                @elseif ($connectionMode === 'create' && in_array($connectionKind, \App\Modules\Edge\Support\EdgeContainerConnections::CREATABLE, true))
                    <x-sheet.field :label="__('Name')" for="connection-label" :help="__('This creates the resource and gives the app a private host on the next deploy.')">
                        <input id="connection-label" type="text" wire:model="connectionLabel" placeholder="{{ __('Uploads') }}" class="dply-input mt-0" />
                    </x-sheet.field>
                    @if ($connectionKind === 'object_storage')
                        <x-sheet.field :label="__('Location')" for="connection-object-location" :help="__('Where Cloudflare keeps the bucket. It cannot be moved later.')">
                            <select id="connection-object-location" wire:model="objectLocationHint" class="dply-input mt-0">
                                @foreach (\App\Livewire\Sites\Edge\Workspace\Resources::R2_LOCATION_HINTS as $hint => $label)
                                    <option value="{{ $hint }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </x-sheet.field>
                    @endif
                    @if ($connectionKind === 'vectors')
                        <x-sheet.field :label="__('Dimensions')" for="connection-vectors-dimensions" :help="__('How many numbers each vector has. Match your embedding model, such as 768 for bge-base or 1536 for text-embedding-3-small. It cannot change later.')">
                            <select id="connection-vectors-dimensions" wire:model="vectorsDimensions" class="dply-input mt-0">
                                @foreach (\App\Modules\Edge\Support\EdgeContainerConnections::VECTOR_DIMENSIONS as $dimensions)
                                    <option value="{{ $dimensions }}">{{ $dimensions }}</option>
                                @endforeach
                            </select>
                        </x-sheet.field>
                        <x-sheet.field :label="__('Distance')" for="connection-vectors-metric" :help="__('How closeness is measured. Cosine suits most text embeddings.')">
                            <select id="connection-vectors-metric" wire:model="vectorsMetric" class="dply-input mt-0">
                                @foreach (\App\Modules\Edge\Support\EdgeContainerConnections::VECTOR_METRICS as $metric)
                                    <option value="{{ $metric }}">{{ $metric }}</option>
                                @endforeach
                            </select>
                        </x-sheet.field>
                    @elseif ($connectionKind === 'database_pool')
                        @php $poolAppEngine = $this->poolAppEngine(); @endphp
                        @if ($poolAppEngine !== '')
                            <x-sheet.options>
                                <x-sheet.option wire:click="$set('poolSource', 'app')" :selected="$poolSource === 'app'" :title="__('This app\'s database')" :description="__('Pool the dply :engine this app already has.', ['engine' => $poolAppEngine === 'mysql' ? 'MySQL' : 'Postgres'])" />
                                <x-sheet.option wire:click="$set('poolSource', 'url')" :selected="$poolSource === 'url'" :title="__('Another database')" :description="__('Paste its address.')" />
                            </x-sheet.options>
                        @endif
                        @if ($poolSource === 'url' || $poolAppEngine === '')
                            <x-sheet.field :label="__('Address')" for="connection-pool-url" :help="__('The pool signs in with this address. It is sent to the pool and not stored or shown here.')">
                                <input id="connection-pool-url" type="password" wire:model="poolOriginUrl" autocomplete="off" spellcheck="false" placeholder="postgres://user:secret@db.example.com:5432/app" class="dply-input mt-0 font-mono" />
                            </x-sheet.field>
                        @endif
                        <x-sheet.note>{{ __('Creating a pool connects to the database once, which can take a few seconds while it wakes.') }}</x-sheet.note>
                    @endif
                @elseif (in_array($connectionKind, \App\Modules\Edge\Support\EdgeContainerConnections::CREATABLE, true))
                    <x-sheet.field :label="__('Existing :type', ['type' => __(\App\Modules\Edge\Support\EdgeContainerConnections::targetLabel($connectionKind))])" for="connection-pick">
                        <select id="connection-pick" wire:model="connectionPick" class="dply-input mt-0">
                            <option value="">{{ __('Choose one') }}</option>
                            @foreach ($connectionOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </x-sheet.field>
                @elseif ($connectionKind === 'redis' && $connectionMode === 'create' && ! $cardOnFile)
                    <x-sheet.note tone="warn">{{ __('Add a card before starting dply Valkey. Usage is billed to that card.') }}</x-sheet.note>
                    @if ($site->organization)
                        <div><x-sheet.button href="{{ route('billing.show', $site->organization) }}">{{ __('Billing') }}</x-sheet.button></div>
                    @endif
                @elseif ($connectionKind === 'redis' && $connectionMode === 'create')
                    <x-sheet.field :label="__('Name')" for="connection-label">
                        <input id="connection-label" type="text" wire:model="connectionLabel" placeholder="{{ __('Cache') }}" class="dply-input mt-0" />
                    </x-sheet.field>
                    @php $vkTrial = \App\Modules\Edge\Support\EdgeTrialLimits::applies($site->organization); @endphp
                    <x-sheet.field :label="__('Size')" for="connection-valkey-class" :help="$vkTrial ? __('During the trial Valkey runs the smallest size and sleeps when idle.') : null">
                        <select id="connection-valkey-class" wire:model.live="valkeyClass" class="dply-input mt-0">
                            @foreach (\App\Modules\Edge\Support\EdgeValkey::offered() as $id => $class)
                                <option value="{{ $id }}" @disabled($vkTrial && $id !== \App\Modules\Edge\Support\EdgeTrialLimits::valkeyClass())>@if ($vkTrial && $id !== \App\Modules\Edge\Support\EdgeTrialLimits::valkeyClass()){{ __('Available after your trial') }} · @endif{{ __($class['label']) }} · {{ __('up to $:price/mo', ['price' => number_format($class['cap_cents'] / 100, 0)]) }}</option>
                            @endforeach
                        </select>
                    </x-sheet.field>
                    @if (\App\Modules\Edge\Support\EdgeValkey::CLASSES[$valkeyClass]['sleeps'] ?? false)
                        <x-sheet.field :label="__('Sleep when idle for')" for="connection-valkey-sleep" :help="__('Billed per second while awake, up to the monthly price. Asleep, it is not billed. Its data is saved and comes back on the next connection, which takes a few seconds.')">
                            <select id="connection-valkey-sleep" wire:model="valkeySleep" class="dply-input mt-0">
                                @foreach (\App\Modules\Edge\Support\EdgeValkey::SLEEPS as $seconds => $label)
                                    <option value="{{ $seconds }}" @disabled($vkTrial && $seconds === 0)>{{ __($label) }}@if ($vkTrial && $seconds === 0) · {{ __('Available after your trial') }}@endif</option>
                                @endforeach
                            </select>
                        </x-sheet.field>
                    @else
                        <x-sheet.note>{{ __('Stays on and writes every change to disk. Billed per second, up to the monthly price.') }}</x-sheet.note>
                    @endif
                    <x-sheet.note>{{ __('The address is set as REDIS_URL on the next deploy and is not shown.') }}</x-sheet.note>
                @elseif ($connectionKind === 'redis')
                    <x-sheet.field :label="__('Name')" for="connection-label">
                        <input id="connection-label" type="text" wire:model="connectionLabel" placeholder="{{ __('Cache') }}" class="dply-input mt-0" />
                    </x-sheet.field>
                    <x-sheet.field :label="__('Address')" for="connection-pick" :help="__('The app connects straight to this address. The address is encrypted and is not shown again. A pasted address is not billed here.')">
                        <input id="connection-pick" type="password" wire:model="connectionPick" autocomplete="off" spellcheck="false" placeholder="rediss://default:secret@cache.example:6379" class="dply-input mt-0 font-mono" />
                    </x-sheet.field>
                    @if ($site->isLaravelFrameworkDetected())
                        <x-sheet.note>{{ __('The next deploy also sets CACHE_STORE=redis unless that is already set.') }}</x-sheet.note>
                    @endif
                @elseif ($connectionKind === 'durable_object')
                    <x-sheet.field :label="__('Name')" for="connection-label" :help="__('One object keeps these keys. Counters and locks stay exact. It applies on the next deploy.')">
                        <input id="connection-label" type="text" wire:model="connectionLabel" placeholder="{{ __('Visits') }}" class="dply-input mt-0" />
                    </x-sheet.field>
                @elseif ($connectionKind === 'service')
                    <x-sheet.field :label="__('Name')" for="connection-label">
                        <input id="connection-label" type="text" wire:model="connectionLabel" placeholder="{{ __('Billing') }}" class="dply-input mt-0" />
                    </x-sheet.field>
                    <x-sheet.field :label="__('App')" for="connection-pick" :help="$connectionOptions === [] ? __('No other apps in this workspace yet.') : null">
                        <select id="connection-pick" wire:model="connectionPick" class="dply-input mt-0">
                            <option value="">{{ __('Choose an app') }}</option>
                            @foreach ($connectionOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </x-sheet.field>
                @else
                    <x-sheet.field :label="__('Name')" for="connection-label">
                        <input id="connection-label" type="text" wire:model="connectionLabel" placeholder="{{ __('Main') }}" class="dply-input mt-0" />
                    </x-sheet.field>
                    <x-sheet.field :label="__(\App\Modules\Edge\Support\EdgeContainerConnections::targetLabel($connectionKind))" for="connection-pick">
                        <input id="connection-pick" type="text" wire:model="connectionPick" class="dply-input mt-0" />
                    </x-sheet.field>
                @endif
                <x-input-error :messages="$errors->get('connection')" />
            </x-sheet.body>

            @if (! (($connectionKind === 'redis' && $connectionMode === 'create' || $connectionKind === 'key_value' && $connectionMode === 'create' || $connectionKind === 'realtime') && ! $cardOnFile))
                <x-sheet.footer>
                    <x-sheet.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveConnection" class="ms-auto">
                        <span wire:loading wire:target="saveConnection" class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-brand-cream/40 border-t-brand-cream" aria-hidden="true"></span>
                        <span wire:loading.remove wire:target="saveConnection">{{ $connectionMode === 'attach' ? __('Attach') : __('Create') }}</span>
                        <span wire:loading wire:target="saveConnection">{{ $connectionKind === 'redis' && $connectionMode === 'create' ? __('Starting Redis…') : __('Working…') }}</span>
                    </x-sheet.button>
                </x-sheet.footer>
            @endif
        </form>
    @endif
</x-sheet>
