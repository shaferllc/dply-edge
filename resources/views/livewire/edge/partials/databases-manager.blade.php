@if (session('status'))
    <div class="px-4 pt-4"><x-sheet.note tone="ok">{{ session('status') }}</x-sheet.note></div>
@endif

<div class="grid gap-4 p-4 lg:grid-cols-12">
    <div class="grid content-start gap-3 lg:col-span-4">
        <form wire:submit="create" class="grid gap-3 rounded-xl border border-brand-ink/10 p-3.5 dark:border-brand-mist/15">
            <x-sheet.section :title="__('New database')">
                <input aria-label="{{ __('Database name') }}" wire:model="name" type="text" class="dply-input mt-0 font-mono" placeholder="app-db" />
                <x-input-error :messages="$errors->get('name')" />
                <select aria-label="{{ __('Location') }}" wire:model="location" class="dply-input mt-0">
                    @foreach ($locations as $value => $label)
                        <option value="{{ $value }}">{{ __($label) }}</option>
                    @endforeach
                </select>
                <x-sheet.button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">{{ __('Create') }}</x-sheet.button>
                <p class="text-2xs text-brand-mist">
                    {{ $limit === null ? __(':count databases', ['count' => $databases->count()]) : __(':count of :limit on your plan', ['count' => $databases->count(), 'limit' => $limit]) }}
                </p>
            </x-sheet.section>
        </form>

        @if ($databases->isEmpty())
            <x-sheet.empty :message="__('No databases yet.')" />
        @else
            <ul class="divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/15">
                @foreach ($databases as $database)
                    <li>
                        <button type="button" wire:click="select('{{ $database->id }}')" @class([
                            'flex w-full items-center justify-between gap-2 px-3.5 py-2.5 text-left text-sm transition',
                            'bg-brand-forest/5 ring-1 ring-inset ring-brand-forest' => $current?->id === $database->id,
                            'hover:bg-brand-sand/20' => $current?->id !== $database->id,
                        ])>
                            <span class="font-mono text-brand-ink">{{ $database->name }}</span>
                            <span class="text-2xs text-brand-mist">{{ $database->created_at?->diffForHumans() }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="lg:col-span-8">
        @if ($current)
            <div class="grid gap-4">
                <x-sheet.metrics :cols="4">
                    <x-sheet.metric :label="__('Database')" :note="$current->cloudflare_id" class="col-span-2">{{ $current->name }}</x-sheet.metric>
                    @if ($info)
                        <x-sheet.metric :label="__('Size')" :note="! empty($info['running_in_region']) ? strtoupper((string) $info['running_in_region']) : null">{{ Illuminate\Support\Number::fileSize((int) ($info['file_size'] ?? 0)) }}</x-sheet.metric>
                        <x-sheet.metric :label="__('Tables')">{{ trans_choice(':count table|:count tables', (int) ($info['num_tables'] ?? 0)) }}</x-sheet.metric>
                    @endif
                </x-sheet.metrics>

                <form wire:submit="run" class="grid gap-2 rounded-xl border border-brand-ink/10 p-3.5 dark:border-brand-mist/15">
                    <x-sheet.field :label="__('SQL')" for="edge-db-sql" :help="__('Runs against production data. Separate statements with semicolons.')">
                        <textarea id="edge-db-sql" wire:model="sql" rows="5" class="dply-input mt-0 font-mono" spellcheck="false"></textarea>
                    </x-sheet.field>
                    <div class="flex justify-end">
                        <x-sheet.button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Run') }}</x-sheet.button>
                    </div>
                </form>

                @if ($queryError)
                    <x-sheet.note tone="danger">{{ $queryError }}</x-sheet.note>
                @endif

                @foreach ($results ?? [] as $statement)
                    @php $rows = array_slice((array) ($statement['results'] ?? []), 0, 200); $columns = $rows ? array_keys((array) $rows[0]) : []; @endphp
                    <div class="grid gap-1.5">
                        <p class="text-2xs text-brand-mist">
                            {{ __(':rows rows read · :written written · :ms ms', ['rows' => $statement['meta']['rows_read'] ?? 0, 'written' => $statement['meta']['rows_written'] ?? 0, 'ms' => round((float) ($statement['meta']['duration'] ?? 0), 1)]) }}
                        </p>
                        @if ($columns)
                            <x-sheet.table class="font-mono">
                                <table>
                                    <thead><tr>@foreach ($columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                                    <tbody>
                                        @foreach ($rows as $row)
                                            <tr>@foreach ($columns as $column)<td class="max-w-xs truncate">{{ is_scalar($row[$column] ?? null) ? $row[$column] : json_encode($row[$column] ?? null) }}</td>@endforeach</tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </x-sheet.table>
                        @else
                            <x-sheet.empty :message="__('No rows.')" />
                        @endif
                    </div>
                @endforeach

                <form wire:submit="attach" class="grid gap-3 rounded-xl border border-brand-ink/10 p-3.5 dark:border-brand-mist/15">
                    <x-sheet.section :title="__('Attach to a project')">
                        <div class="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                            <select aria-label="{{ __('Project') }}" wire:model="attachSite" class="dply-input mt-0">
                                <option value="">{{ __('Choose a project…') }}</option>
                                @foreach ($sites as $site)
                                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                                @endforeach
                            </select>
                            <input aria-label="{{ __('Binding name') }}" wire:model="bindingName" type="text" class="dply-input mt-0 font-mono" />
                            <x-sheet.button type="submit">{{ __('Attach') }}</x-sheet.button>
                        </div>
                        <x-input-error :messages="array_merge($errors->get('attachSite'), $errors->get('bindingName'))" />
                        <p class="text-2xs text-brand-mist">{{ __('Available to Worker, SSR and middleware code as env.:binding after the next deploy. Container apps use an external database (DB_URL / DATABASE_URL).', ['binding' => $bindingName ?: 'DB']) }}</p>
                    </x-sheet.section>
                </form>

                <x-sheet.danger :title="__('Delete database')" x-data="{ confirm: '' }">
                    <p class="text-xs text-brand-moss">{{ __('Permanently deletes the database and all its data. Type its name to confirm.') }}</p>
                    <div class="flex gap-2">
                        <input aria-label="{{ __('Type :name to confirm', ['name' => $current->name]) }}" x-model="confirm" type="text" class="dply-input mt-0 min-w-0 flex-1 font-mono" placeholder="{{ $current->name }}" />
                        <x-sheet.button variant="danger" x-bind:disabled="confirm !== {{ \Illuminate\Support\Js::from($current->name) }}" x-on:click="$wire.delete({{ \Illuminate\Support\Js::from($current->id) }}, confirm)">{{ __('Delete') }}</x-sheet.button>
                    </div>
                    <x-input-error :messages="$errors->get('delete')" />
                </x-sheet.danger>
            </div>
        @else
            <x-sheet.empty :message="__('Select a database to query it, or create one.')" class="py-16" />
        @endif
    </div>
</div>
