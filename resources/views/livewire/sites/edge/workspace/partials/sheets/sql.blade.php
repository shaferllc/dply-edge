@php
    $sqlConnection = collect($connections)->firstWhere('host', $resourceHost);
    $sqlConnection = is_array($sqlConnection) && $sqlConnection['kind'] === 'sql' ? $sqlConnection : null;
    $sqlBytes = static fn (int $bytes): string => $bytes >= 1024 ** 3 ? number_format($bytes / 1024 ** 3, 2).' GB' : ($bytes >= 1024 ** 2 ? number_format($bytes / 1024 ** 2, 1).' MB' : ($bytes >= 1024 ? number_format($bytes / 1024, 1).' KB' : number_format($bytes).' B'));
    $sqlCell = static fn (mixed $value): string => $value === null ? 'NULL' : \Illuminate\Support\Str::limit(is_scalar($value) ? (string) $value : (string) json_encode($value), 200);
@endphp
<x-sheet name="resources-sql" maxWidth="4xl" focusable>
    @if ($sqlConnection === null)
        <x-sheet.header :title="__('SQL database')" close-wire="$set('resourceHost', '')" />
        <x-sheet.body><p class="text-xs text-brand-moss">{{ __('Loading the database…') }}</p></x-sheet.body>
    @else
        @php
            $sqlHost = $sqlConnection['host'];
            $sqlBinding = $sqlConnection['name'];
            $sqlRecord = \App\Models\EdgeDatabase::query()->where('organization_id', $site->organization_id)->where('cloudflare_id', $sqlConnection['target'])->first();
            $sqlUsage = $this->sqlMonthUsage($sqlConnection['target']);
            $sqlCents = $this->sqlCostCents($sqlConnection);
            $sqlExample = 'SELECT * FROM users WHERE id = ?';
            $sqlSnippets = [
                'Laravel' => str_replace('HOST', $sqlHost, 'use Illuminate\Support\Facades\Http;'."\n\n".'$rows = Http::post(\'http://HOST/query\', ['."\n".'    \'sql\' => \''.$sqlExample.'\','."\n".'    \'params\' => [1],'."\n".'])->json(\'results\');'),
                'Node' => str_replace('HOST', $sqlHost, "const res = await fetch('http://HOST/query', {\n  method: 'POST',\n  headers: { 'content-type': 'application/json' },\n  body: JSON.stringify({ sql: '".$sqlExample."', params: [1] }),\n});\nconst { results } = await res.json();"),
                'curl' => str_replace('HOST', $sqlHost, "curl -X POST http://HOST/query \\\n  -H 'content-type: application/json' \\\n  -d '{\"sql\": \"SELECT name FROM sqlite_master\"}'"),
            ];
            $sqlWorker = "const { results } = await env.".$sqlBinding."\n  .prepare('".$sqlExample."')\n  .bind(1)\n  .all();";
        @endphp
        <x-sheet.header :eyebrow="$isWorker ? 'env.'.$sqlBinding : $sqlHost" :title="$sqlRecord?->name ?? strtolower($sqlBinding)" close-wire="$set('resourceHost', '')">
            {{ __('Cloudflare D1 · SQLite') }}
        </x-sheet.header>

        <x-sheet.body wire:key="sql-{{ $sqlHost }}" x-init="$wire.$island('resources-sql').sqlLoad()">
            @if ($sqlError)
                <x-sheet.note tone="danger">{{ $sqlError }}</x-sheet.note>
            @endif
            <div class="grid content-start gap-4" x-data="{ tab: 'overview' }">
                <x-sheet.tabs>
                    <button type="button" role="tab" x-on:click="tab = 'overview'" :aria-selected="tab === 'overview' ? 'true' : 'false'">{{ __('Overview') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'connect'" :aria-selected="tab === 'connect' ? 'true' : 'false'">{{ __('Connect') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'tables'" :aria-selected="tab === 'tables' ? 'true' : 'false'">{{ __('Tables') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'console'" :aria-selected="tab === 'console' ? 'true' : 'false'">{{ __('Console') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'settings'" :aria-selected="tab === 'settings' ? 'true' : 'false'">{{ __('Settings') }}</button>
                </x-sheet.tabs>

                <div x-show="tab === 'overview'" class="grid gap-3">
                    <x-sheet.metrics :cols="3">
                        <x-sheet.metric :label="__('Size')">{{ is_array($sqlInfo) ? $sqlBytes((int) ($sqlInfo['file_size'] ?? 0)) : '…' }}</x-sheet.metric>
                        <x-sheet.metric :label="__('Tables')">{{ is_array($sqlInfo) ? number_format((int) ($sqlInfo['num_tables'] ?? 0)) : '…' }}</x-sheet.metric>
                        <x-sheet.metric :label="__('Region')" :note="$sqlRecord?->location_hint ? __('Asked for :hint', ['hint' => strtoupper($sqlRecord->location_hint)]) : null">{{ is_array($sqlInfo) ? ((string) ($sqlInfo['running_in_region'] ?? '') ?: __('Automatic')) : '…' }}</x-sheet.metric>
                        <x-sheet.metric :label="__('Rows read this month')">{{ number_format($sqlUsage['reads']) }}</x-sheet.metric>
                        <x-sheet.metric :label="__('Rows written this month')">{{ number_format($sqlUsage['writes']) }}</x-sheet.metric>
                        <x-sheet.metric :label="__('Peak storage')">{{ $sqlBytes($sqlUsage['storage']) }}</x-sheet.metric>
                    </x-sheet.metrics>
                    <x-sheet.cost :label="__('This month')" :sub="__('This database only. Collected daily through yesterday.')">${{ number_format(($sqlCents ?? 0) / 100, 2) }}</x-sheet.cost>
                    @unless ($cardOnFile)
                        <x-sheet.note tone="warn">{{ __('This counts against the usage credit until a card is on the account.') }}</x-sheet.note>
                    @endunless
                </div>

                <div x-show="tab === 'connect'" x-cloak class="grid gap-5">
                    @if ($isWorker)
                        <x-sheet.section :title="__('Worker')">
                            <p class="text-xs text-brand-moss">{{ __('This app runs as a Worker, so it gets a D1 binding named :name after the next deploy.', ['name' => $sqlBinding]) }}</p>
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ $sqlWorker }}</pre>
                        </x-sheet.section>
                    @else
                        <p class="text-xs leading-5 text-brand-moss">{{ __('POST SQL and params as JSON to http://:host/query. The answer is {results, success, meta}. Only this app can reach the address, after the next deploy.', ['host' => $sqlHost]) }}</p>
                        @foreach ($sqlSnippets as $sqlLabel => $sqlSnippet)
                            <x-sheet.section :title="$sqlLabel">
                                <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ $sqlSnippet }}</pre>
                            </x-sheet.section>
                        @endforeach
                        <x-sheet.note>{{ __('Use ? placeholders and params for values. Each call is one statement.') }}</x-sheet.note>
                        <x-sheet.note>{{ __('Backups: Cloudflare keeps 30 days of point-in-time history for this database (D1 Time Travel).') }}</x-sheet.note>
                    @endif
                </div>

                <div x-show="tab === 'tables'" x-cloak class="grid gap-3">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Tables') }}</h3>
                        <x-sheet.button wire:click="sqlLoad" wire:loading.attr="disabled" wire:target="sqlLoad">{{ __('Refresh') }}</x-sheet.button>
                    </div>
                    @if ($sqlTables === null)
                        <p class="text-xs text-brand-moss">{{ __('Loading tables…') }}</p>
                    @elseif ($sqlTables === [])
                        <x-sheet.empty :message="__('No tables yet. Create one from the console or your app.')" />
                    @else
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($sqlTables as $sqlTableName)
                                <x-sheet.button :variant="$sqlTable === $sqlTableName ? 'primary' : 'secondary'" class="font-mono" wire:click="sqlOpenTable({{ \Illuminate\Support\Js::from($sqlTableName) }})">{{ $sqlTableName }}</x-sheet.button>
                            @endforeach
                        </div>
                    @endif
                    @if ($sqlTable !== '' && is_array($sqlTableRows))
                        <x-sheet.section :title="__(':table · first 50 rows', ['table' => $sqlTable])">
                            @if ($sqlTableRows === [])
                                <x-sheet.empty :message="__('This table is empty.')" />
                            @else
                                <x-sheet.table class="max-h-96">
                                    <table>
                                        <thead><tr>@foreach (array_keys($sqlTableRows[0]) as $sqlColumn)<th>{{ $sqlColumn }}</th>@endforeach</tr></thead>
                                        <tbody>
                                            @foreach ($sqlTableRows as $sqlRow)
                                                <tr>@foreach ($sqlRow as $sqlValue)<td class="font-mono">{{ $sqlCell($sqlValue) }}</td>@endforeach</tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </x-sheet.table>
                            @endif
                        </x-sheet.section>
                    @endif
                </div>

                <div x-show="tab === 'console'" x-cloak class="grid gap-3">
                    <x-sheet.note tone="warn">{{ __('This runs against the live database the app uses. Writes and deletes are real and cannot be undone.') }}</x-sheet.note>
                    <form wire:submit="sqlRun" class="grid gap-2">
                        <x-sheet.field :label="__('SQL')" for="sql-console">
                            <textarea id="sql-console" wire:model="sqlQuery" rows="5" spellcheck="false" class="dply-input mt-0 font-mono text-xs"></textarea>
                        </x-sheet.field>
                        <x-input-error :messages="$errors->get('sqlQuery')" />
                        <div><x-sheet.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="sqlRun">{{ __('Run') }}</x-sheet.button></div>
                    </form>
                    @if ($sqlQueryError)
                        <x-sheet.note tone="danger">{{ $sqlQueryError }}</x-sheet.note>
                    @elseif (is_array($sqlResults))
                        <p class="text-2xs text-brand-mist">
                            {{ __(':rows rows · :changes changed · :ms ms', ['rows' => count($sqlResults), 'changes' => (int) ($sqlResultMeta['changes'] ?? 0), 'ms' => rtrim(rtrim(number_format((float) ($sqlResultMeta['duration'] ?? 0), 2), '0'), '.')]) }}
                            @if (($sqlResultMeta['statements'] ?? 1) > 1) · {{ __('showing the last of :count statements', ['count' => $sqlResultMeta['statements']]) }} @endif
                        </p>
                        @if ($sqlResults !== [])
                            <x-sheet.table class="max-h-96">
                                <table>
                                    <thead><tr>@foreach (array_keys($sqlResults[0]) as $sqlColumn)<th>{{ $sqlColumn }}</th>@endforeach</tr></thead>
                                    <tbody>
                                        @foreach ($sqlResults as $sqlRow)
                                            <tr>@foreach ((array) $sqlRow as $sqlValue)<td class="font-mono">{{ $sqlCell($sqlValue) }}</td>@endforeach</tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </x-sheet.table>
                        @endif
                    @endif
                </div>

                <div x-show="tab === 'settings'" x-cloak class="grid gap-4">
                    @php $sqlNext = \App\Modules\Edge\Support\EdgeContainerConnections::identity($sqlName, $site); @endphp
                    <x-sheet.field :label="__('Name')" for="sql-name">
                        <input id="sql-name" type="text" wire:model.live.debounce.400ms="sqlName" class="dply-input mt-0" />
                        @if (is_array($sqlNext))
                            <p class="text-2xs leading-4 text-brand-mist">{{ $isWorker ? __('The app reads env.:name after the next deploy. Update your code to match.', ['name' => $sqlNext['name']]) : __('The app uses http://:host/query after the next deploy. Update your code to match.', ['host' => $sqlNext['host']]) }}</p>
                        @else
                            <p class="text-2xs leading-4 text-brand-mist">{{ __('Letters and numbers only, starting with a letter. The database itself keeps its name.') }}</p>
                        @endif
                    </x-sheet.field>
                    <x-input-error :messages="$errors->get('sqlName')" />
                    <div><x-sheet.button variant="primary" wire:click="sqlRename">{{ __('Save name') }}</x-sheet.button></div>

                    <x-sheet.danger :title="__('Delete this database')">
                        <p class="text-xs text-brand-moss">{{ __('Deletes the database and every row in it, and removes it from this app. This cannot be undone.') }}</p>
                        <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($sqlHost) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Delete database') }}</x-sheet.button></div>
                    </x-sheet.danger>
                </div>
            </div>
        </x-sheet.body>
    @endif
</x-sheet>
