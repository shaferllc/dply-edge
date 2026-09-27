@php
    $queueConnection = collect($connections)->firstWhere('host', $resourceHost);
    $queueConnection = is_array($queueConnection) && $queueConnection['kind'] === 'queue' ? $queueConnection : null;
@endphp
<x-sheet name="resources-queue" maxWidth="3xl" focusable>
    @if ($queueConnection === null)
        <x-sheet.header :title="__('Queue')" close-wire="$set('resourceHost', '')" />
        <x-sheet.body><p class="text-xs text-brand-moss">{{ $resourceHost === '' ? __('Loading the queue…') : __('This queue is no longer attached to this app.') }}</p></x-sheet.body>
    @else
        @php
            $queueHost = $queueConnection['host'];
            $queueBinding = $queueConnection['name'];
            $queueRecord = \App\Models\EdgeQueue::query()->where('organization_id', $site->organization_id)->where('cloudflare_name', $queueConnection['target'])->first();
            $queueDriverEnv = \App\Modules\Edge\Support\EdgeContainerConnections::queueDriverEnv($site);
            $queueIsDefault = ($queueDriverEnv['DPLY_QUEUE'] ?? null) === $queueBinding;
            $queueCents = $this->queueCostCents($queueConnection);
            $queueLoaded = is_array($queueDetail) && $queueLoadedHost === $queueHost ? $queueDetail : null;
            $queueSnippets = [
                'Laravel' => 'dispatch(new ProcessOrder($order))'.($queueIsDefault ? '' : "\n    ->onQueue('".$queueBinding."')").';',
                'Node' => "await fetch('http://".$queueHost."/send', {\n  method: 'POST',\n  headers: { 'content-type': 'application/json' },\n  body: JSON.stringify({ order: 42 }),\n});",
                'curl' => "curl -X POST http://".$queueHost."/send \\\n  -H 'content-type: application/json' \\\n  -d '{\"order\": 42}'",
            ];
            $queueWorker = "await env.".$queueBinding.".send({ order: 42 });";
        @endphp
        <x-sheet.header :eyebrow="$isWorker ? 'env.'.$queueBinding : $queueHost" :title="$queueRecord?->name ?? strtolower($queueBinding)" close-wire="$set('resourceHost', '')">
            {{ __('Cloudflare Queues') }}
        </x-sheet.header>

        <x-sheet.body wire:key="queue-{{ $queueHost }}" x-init="$wire.$island('resources-queue').queueLoad()">
            @if ($queueError)
                <x-sheet.note tone="danger">{{ $queueError }}</x-sheet.note>
            @endif
            <div class="grid content-start gap-4" x-data="{ tab: 'overview' }">
                <x-sheet.tabs>
                    <button type="button" role="tab" x-on:click="tab = 'overview'" :aria-selected="tab === 'overview' ? 'true' : 'false'">{{ __('Overview') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'connect'" :aria-selected="tab === 'connect' ? 'true' : 'false'">{{ __('Connect') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'test'" :aria-selected="tab === 'test' ? 'true' : 'false'">{{ __('Send a test') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'consumers'" :aria-selected="tab === 'consumers' ? 'true' : 'false'">{{ __('Consumers') }}</button>
                </x-sheet.tabs>

                <div x-show="tab === 'overview'" class="grid gap-4">
                    @php $queueFirst = $queueLoaded['consumers'][0] ?? null; @endphp
                    <x-sheet.metrics :cols="3">
                        <x-sheet.metric :label="__('Waiting')" :note="__('Messages in the queue, last hour')">
                            {{ $queueLoaded === null ? '…' : ($queueLoaded['backlog'] === null ? '—' : number_format($queueLoaded['backlog'])) }}
                        </x-sheet.metric>
                        <x-sheet.metric :label="__('Operations')" :note="__('This month so far')">
                            {{ $queueLoaded === null ? '…' : number_format($queueLoaded['operations']) }}
                        </x-sheet.metric>
                        <x-sheet.metric :label="__('Cost')" :note="__('This month so far')">
                            {{ $queueCents === null ? '—' : '$'.number_format($queueCents / 100, 2) }}
                        </x-sheet.metric>
                    </x-sheet.metrics>

                    <x-sheet.section :title="__('Who runs the jobs')">
                        @if ($queueLoaded === null)
                            <p class="text-xs text-brand-moss">{{ __('Loading…') }}</p>
                        @elseif ($queueLoaded['owner_is_this'])
                            <x-sheet.note tone="ok">{{ __('This app runs this queue’s jobs.') }}</x-sheet.note>
                        @elseif ($queueLoaded['owner'] !== null)
                            <x-sheet.note>{{ __('This app sends only. :app runs the jobs.', ['app' => $queueLoaded['owner']]) }}</x-sheet.note>
                        @else
                            <x-sheet.note tone="warn">{{ __('No production app runs this queue’s jobs yet, so messages wait. The earliest production app attached to it becomes the one that runs them.') }}</x-sheet.note>
                        @endif
                    </x-sheet.section>

                    @if (is_array($queueFirst))
                        <x-sheet.section :title="__('Delivery')">
                            <div>
                                <x-sheet.stat :label="__('Batch size')">{{ $queueFirst['batch_size'] ?? '—' }}</x-sheet.stat>
                                <x-sheet.stat :label="__('Retries')">{{ $queueFirst['max_retries'] ?? '—' }}</x-sheet.stat>
                                <x-sheet.stat :label="__('Batch wait')">{{ $queueFirst['max_wait_ms'] === null ? '—' : __(':s s', ['s' => rtrim(rtrim(number_format($queueFirst['max_wait_ms'] / 1000, 1), '0'), '.')]) }}</x-sheet.stat>
                                <x-sheet.stat :label="__('Concurrency')">{{ $queueFirst['max_concurrency'] ?? __('Automatic') }}</x-sheet.stat>
                                <x-sheet.stat :label="__('Dead-letter queue')">{{ $queueFirst['dead_letter_queue'] !== '' ? $queueFirst['dead_letter_queue'] : __('None') }}</x-sheet.stat>
                            </div>
                            <p class="text-2xs leading-4 text-brand-mist">{{ __('A message that fails every retry is dropped unless a dead-letter queue is set. Speed follows your plan.') }}</p>
                        </x-sheet.section>
                    @endif

                    <p class="text-2xs leading-4 text-brand-mist">{{ __('Every write, read, and delete of a message is an operation. Collected daily.') }}</p>
                    @unless ($cardOnFile)
                        <x-sheet.note tone="warn">{{ __('This counts against the usage credit until a card is on the account.') }}</x-sheet.note>
                    @endunless
                </div>

                <div x-show="tab === 'connect'" x-cloak class="grid gap-5">
                    @if ($isWorker)
                        <x-sheet.section :title="__('Worker')">
                            <p class="text-xs text-brand-moss">{{ __('The queue is bound as env.:name after the next deploy. Send any JSON value.', ['name' => $queueBinding]) }}</p>
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ $queueWorker }}</pre>
                        </x-sheet.section>
                    @else
                        <x-sheet.section :title="__('Laravel')">
                            @if ($queueIsDefault)
                                <p class="text-xs text-brand-moss">{{ isset($queueDriverEnv['QUEUE_CONNECTION'])
                                    ? __('The next deploy sets QUEUE_CONNECTION=dply and DPLY_QUEUE=:name. dispatch() then sends jobs here, and the app that runs this queue handles them.', ['name' => $queueBinding])
                                    : __('The next deploy sets DPLY_QUEUE=:name. With dply/laravel and QUEUE_CONNECTION=dply, dispatch() sends jobs here.', ['name' => $queueBinding]) }}</p>
                            @else
                                <p class="text-xs text-brand-moss">{{ __('This app’s default queue is another one. Name this queue to send jobs here.') }}</p>
                            @endif
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ $queueSnippets['Laravel'] }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('Any language')">
                            <p class="text-xs text-brand-moss">{{ __('POST the message body to http://:host/send from inside the app. It is on the app after the next deploy.', ['host' => $queueHost]) }}</p>
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ $queueSnippets['Node'] }}</pre>
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ $queueSnippets['curl'] }}</pre>
                        </x-sheet.section>
                    @endif
                </div>

                <div x-show="tab === 'test'" x-cloak class="grid gap-3">
                    <p class="text-xs text-brand-moss">{{ __('Puts one message on the queue from here. The app that runs its jobs receives exactly this body, so send something it expects or ignores.') }}</p>
                    <x-sheet.field :label="__('Message (JSON)')" for="queue-test-body">
                        <textarea id="queue-test-body" wire:model="queueTestBody" rows="6" spellcheck="false" class="dply-input mt-0 font-mono text-xs"></textarea>
                    </x-sheet.field>
                    <div><x-sheet.button variant="primary" wire:click="queueSendTest" wire:loading.attr="disabled" wire:target="queueSendTest">{{ __('Send message') }}</x-sheet.button></div>
                    @if ($queueTestResult !== null)
                        <x-sheet.note :tone="$queueTestOk ? 'ok' : 'danger'">{{ $queueTestResult }}</x-sheet.note>
                    @endif
                </div>

                <div x-show="tab === 'consumers'" x-cloak class="grid gap-3">
                    <p class="text-xs text-brand-moss">{{ __('A queue has one consumer. The earliest production app attached to it runs its jobs. Every other app only sends.') }}</p>
                    @if ($queueLoaded === null || $queueLoaded['consumers'] === null)
                        <p class="text-xs text-brand-moss">{{ $queueLoaded === null ? __('Loading…') : __('Could not read the consumers.') }}</p>
                    @elseif ($queueLoaded['consumers'] === [])
                        <x-sheet.empty :message="$queueLoaded['owner'] !== null ? __(':app becomes the consumer on its next deploy.', ['app' => $queueLoaded['owner']]) : __('No consumer. Messages wait until a production app runs this queue.')" />
                    @else
                        <x-sheet.table>
                            <table>
                                <thead><tr><th>{{ __('Script') }}</th><th>{{ __('Type') }}</th><th>{{ __('Batch') }}</th><th>{{ __('Retries') }}</th></tr></thead>
                                <tbody>
                                    @foreach ($queueLoaded['consumers'] as $consumer)
                                        <tr>
                                            <td class="font-mono">{{ $consumer['script'] !== '' ? $consumer['script'] : '—' }}</td>
                                            <td>{{ $consumer['type'] }}</td>
                                            <td>{{ $consumer['batch_size'] ?? '—' }}</td>
                                            <td>{{ $consumer['max_retries'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-sheet.table>
                        @if ($queueLoaded['owner'] !== null)
                            <p class="text-2xs leading-4 text-brand-mist">{{ __('Runs in :app.', ['app' => $queueLoaded['owner']]) }}</p>
                        @endif
                    @endif
                    <div><x-sheet.button wire:click="queueLoad" wire:loading.attr="disabled" wire:target="queueLoad">{{ __('Refresh') }}</x-sheet.button></div>
                </div>

                <x-sheet.danger :title="__('Delete this queue')">
                    <p class="text-xs text-brand-moss">{{ __('Detaches the queue from this app. It’s deleted, with every message waiting in it, once no app uses it (after this app’s next deploy). This cannot be undone.') }}</p>
                    <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($queueHost) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Delete queue') }}</x-sheet.button></div>
                </x-sheet.danger>
            </div>
        </x-sheet.body>
    @endif
</x-sheet>
