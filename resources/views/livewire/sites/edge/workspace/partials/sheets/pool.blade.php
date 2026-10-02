@php
    $poolConnection = collect($connections)->first(fn ($c) => $c['host'] === $resourceHost && $c['kind'] === 'database_pool');
    $poolName = is_array($poolConnection) ? $poolConnection['name'] : 'DB';
    $poolHostName = is_array($poolConnection) ? $poolConnection['host'] : '';
@endphp
<x-sheet name="resources-database-pool" maxWidth="3xl" focusable>
    <x-sheet.header :eyebrow="$poolHostName !== '' ? $poolHostName : null" :title="__('Database pool')" />

    <x-sheet.body x-on:resource-opened.window="$event.detail.kind === 'database_pool' && $wire.$island('resources-database-pool').loadPool()">
        @if (! is_array($poolConnection))
            <p class="text-xs text-brand-moss">{{ __('Loading the pool…') }}</p>
        @else
            <p class="text-xs leading-5 text-brand-moss">{{ __('Keeps warm connections to a Postgres or MySQL database close to the app, so each request skips the connection setup. Read queries can be cached for a short time.') }}</p>
            <div class="grid content-start gap-4" x-data="{ tab: 'overview' }">
                <x-sheet.tabs>
                    <button type="button" role="tab" x-on:click="tab = 'overview'" :aria-selected="tab === 'overview' ? 'true' : 'false'">{{ __('Overview') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'connect'" :aria-selected="tab === 'connect' ? 'true' : 'false'">{{ __('Connect') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'settings'" :aria-selected="tab === 'settings' ? 'true' : 'false'">{{ __('Settings') }}</button>
                </x-sheet.tabs>

                <div x-show="tab === 'overview'" class="grid gap-3">
                    @if ($poolError)
                        <x-sheet.note tone="warn">{{ $poolError }}</x-sheet.note>
                    @elseif ($poolInfo === null)
                        <p class="text-xs text-brand-moss" wire:loading.remove wire:target="loadPool">{{ __('Not loaded yet.') }}</p>
                        <p class="text-xs text-brand-moss" wire:loading wire:target="loadPool">{{ __('Reading the pool…') }}</p>
                    @else
                        <div>
                            <x-sheet.stat :label="__('Database host')">{{ $poolInfo['host'] }}</x-sheet.stat>
                            <x-sheet.stat :label="__('Database')">{{ $poolInfo['database'] }}</x-sheet.stat>
                            <x-sheet.stat :label="__('User')">{{ $poolInfo['user'] }}</x-sheet.stat>
                            <x-sheet.stat :label="__('Engine')">{{ $poolInfo['scheme'] === 'mysql' ? 'MySQL' : 'Postgres' }}</x-sheet.stat>
                            <x-sheet.stat :label="__('Query caching')">{{ $poolInfo['caching'] ? __('On') : __('Off') }}</x-sheet.stat>
                        </div>
                    @endif
                    <div><x-sheet.button wire:click="loadPool">{{ __('Refresh') }}</x-sheet.button></div>
                    @if ($poolConnection['asleep'])
                        <x-sheet.note>{{ __('Asleep. The app does not get this pool until you wake it.') }}</x-sheet.note>
                    @endif
                    <x-sheet.note>{{ __('The pool connects to a database you host. dply does not back that database up; keep backups with its host.') }}</x-sheet.note>
                </div>

                <div x-show="tab === 'connect'" x-cloak class="grid gap-5">
                    @if ($isWorker)
                        <p class="text-xs text-brand-moss">{{ __('The next deploy binds the pool as env.:name. Its connectionString goes to your usual driver. Workers need the nodejs_compat flag.', ['name' => $poolName]) }}</p>
                        <x-sheet.section :title="__('pg')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "import { Client } from 'pg';\n\nconst client = new Client({ connectionString: env.{$poolName}.connectionString });\nawait client.connect();\nconst { rows } = await client.query('select now()');\nctx.waitUntil(client.end());" }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('postgres.js')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "import postgres from 'postgres';\n\nconst sql = postgres(env.{$poolName}.connectionString, { max: 5, fetch_types: false });\nconst rows = await sql`select now()`;\nctx.waitUntil(sql.end());" }}</pre>
                        </x-sheet.section>
                    @else
                        <p class="text-xs text-brand-moss">{{ __('GET http://:host/ returns the pool\'s connection string as {"connectionString"} after the next deploy. Connect with it like any database address.', ['host' => $poolHostName]) }}</p>
                        <x-sheet.note tone="warn">{{ __('Not yet verified from a container: the pool\'s address may only be reachable from a Worker. If it does not connect, use the database\'s own address.') }}</x-sheet.note>
                        <x-sheet.section :title="__('PHP')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "\$url = Http::get('http://{$poolHostName}/')->json('connectionString');" }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('Node')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "const { connectionString } = await (await fetch('http://{$poolHostName}/')).json();" }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('curl')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl http://{$poolHostName}/" }}</pre>
                        </x-sheet.section>
                    @endif
                </div>

                <div x-show="tab === 'settings'" x-cloak class="grid gap-3">
                    <x-sheet.stat :label="__('Pool id')">{{ $poolConnection['target'] }}</x-sheet.stat>
                    @can('update', $site)
                        <x-sheet.danger :title="__('Delete this pool')">
                            <p class="text-xs text-brand-moss">{{ __('Deletes the pool, if this organization created it. Otherwise it is only detached from this app. The database it points at and its data are not touched.') }}</p>
                            <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($poolConnection['host']) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Delete') }}</x-sheet.button></div>
                        </x-sheet.danger>
                    @endcan
                </div>
            </div>
        @endif
    </x-sheet.body>
</x-sheet>
