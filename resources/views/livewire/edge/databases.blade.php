<div class="dply-page-shell space-y-4 pt-6">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Projects'), 'href' => route('dashboard'), 'icon' => 'globe-alt'],
        ['label' => __('Databases'), 'icon' => 'circle-stack'],
    ]" />

    <x-profile-shell
        :title="__('Databases')"
        :description="__('Serverless SQLite databases on Dply Edge. Bind one to a project and query it from your app as env.DB.')"
        icon="heroicon-o-circle-stack"
    >
        @if (session('status'))
            <div class="border-b border-brand-ink/10 bg-brand-sage/10 px-4 py-2 text-sm text-brand-ink">{{ session('status') }}</div>
        @endif

        <div class="grid gap-4 p-4 lg:grid-cols-12">
            <div class="space-y-3 lg:col-span-4">
                <form wire:submit="create" class="rounded-xl border border-brand-ink/10 bg-white p-3 dark:bg-zinc-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('New database') }}</p>
                    <x-text-input wire:model="name" type="text" class="mt-2 block w-full font-mono text-sm" placeholder="app-db" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    <select wire:model="location" class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900">
                        @foreach ($locations as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </select>
                    <x-primary-button class="mt-2 w-full justify-center" wire:loading.attr="disabled">{{ __('Create') }}</x-primary-button>
                    <p class="mt-2 text-xs text-brand-mist">
                        {{ $limit === null ? __(':count databases', ['count' => $databases->count()]) : __(':count of :limit on your plan', ['count' => $databases->count(), 'limit' => $limit]) }}
                    </p>
                </form>

                <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white dark:bg-zinc-900">
                    @forelse ($databases as $database)
                        <li>
                            <button type="button" wire:click="select('{{ $database->id }}')" @class([
                                'flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm',
                                'bg-brand-sand/50' => $current?->id === $database->id,
                            ])>
                                <span class="font-mono text-brand-ink">{{ $database->name }}</span>
                                <span class="text-xs text-brand-mist">{{ $database->created_at?->diffForHumans() }}</span>
                            </button>
                        </li>
                    @empty
                        <li class="px-3 py-6 text-center text-sm text-brand-moss">{{ __('No databases yet.') }}</li>
                    @endforelse
                </ul>
            </div>

            <div class="lg:col-span-8">
                @if ($current)
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 text-sm dark:bg-zinc-900">
                            <span class="font-mono font-semibold text-brand-ink">{{ $current->name }}</span>
                            @if ($info)
                                <span class="text-brand-moss">{{ Illuminate\Support\Number::fileSize((int) ($info['file_size'] ?? 0)) }}</span>
                                <span class="text-brand-moss">{{ trans_choice(':count table|:count tables', (int) ($info['num_tables'] ?? 0)) }}</span>
                                @if (! empty($info['running_in_region']))
                                    <span class="text-brand-moss">{{ strtoupper((string) $info['running_in_region']) }}</span>
                                @endif
                            @endif
                            <span class="font-mono text-xs text-brand-mist">{{ $current->cloudflare_id }}</span>
                        </div>

                        <form wire:submit="run" class="rounded-xl border border-brand-ink/10 bg-white p-3 dark:bg-zinc-900">
                            <x-input-label :value="__('SQL')" />
                            <textarea wire:model="sql" rows="5" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white font-mono text-sm dark:bg-zinc-950" spellcheck="false"></textarea>
                            <div class="mt-2 flex items-center justify-between">
                                <p class="text-xs text-brand-mist">{{ __('Runs against production data. Separate statements with semicolons.') }}</p>
                                <x-primary-button wire:loading.attr="disabled">{{ __('Run') }}</x-primary-button>
                            </div>
                        </form>

                        @if ($queryError)
                            <div class="rounded-xl border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $queryError }}</div>
                        @endif

                        @foreach ($results ?? [] as $statement)
                            @php $rows = array_slice((array) ($statement['results'] ?? []), 0, 200); $columns = $rows ? array_keys((array) $rows[0]) : []; @endphp
                            <div class="overflow-x-auto rounded-xl border border-brand-ink/10 bg-white dark:bg-zinc-900">
                                <p class="border-b border-brand-ink/10 px-3 py-1.5 text-xs text-brand-moss">
                                    {{ __(':rows rows read · :written written · :ms ms', ['rows' => $statement['meta']['rows_read'] ?? 0, 'written' => $statement['meta']['rows_written'] ?? 0, 'ms' => round((float) ($statement['meta']['duration'] ?? 0), 1)]) }}
                                </p>
                                @if ($columns)
                                    <table class="min-w-full text-left font-mono text-xs">
                                        <thead class="bg-brand-sand/40"><tr>@foreach ($columns as $column)<th class="px-3 py-1.5 font-semibold">{{ $column }}</th>@endforeach</tr></thead>
                                        <tbody class="divide-y divide-brand-ink/5">
                                            @foreach ($rows as $row)
                                                <tr>@foreach ($columns as $column)<td class="max-w-xs truncate px-3 py-1">{{ is_scalar($row[$column] ?? null) ? $row[$column] : json_encode($row[$column] ?? null) }}</td>@endforeach</tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @else
                                    <p class="px-3 py-2 text-xs text-brand-moss">{{ __('No rows.') }}</p>
                                @endif
                            </div>
                        @endforeach

                        <form wire:submit="attach" class="grid gap-2 rounded-xl border border-brand-ink/10 bg-white p-3 sm:grid-cols-3 dark:bg-zinc-900">
                            <div class="sm:col-span-3"><p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Attach to a project') }}</p></div>
                            <select wire:model="attachSite" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900">
                                <option value="">{{ __('Choose a project…') }}</option>
                                @foreach ($sites as $site)
                                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                                @endforeach
                            </select>
                            <x-text-input wire:model="bindingName" type="text" class="font-mono text-sm" />
                            <x-secondary-button type="submit" class="justify-center">{{ __('Attach') }}</x-secondary-button>
                            <x-input-error :messages="array_merge($errors->get('attachSite'), $errors->get('bindingName'))" class="sm:col-span-3" />
                            <p class="text-xs text-brand-mist sm:col-span-3">{{ __('Available to Worker, SSR and middleware code as env.:binding after the next deploy. Container apps use an external database (DB_URL / DATABASE_URL).', ['binding' => $bindingName ?: 'DB']) }}</p>
                        </form>

                        <div x-data="{ confirm: '' }" class="rounded-xl border border-red-200 bg-red-50/60 p-3 dark:bg-red-950/20">
                            <p class="text-sm font-semibold text-red-800">{{ __('Delete database') }}</p>
                            <p class="text-xs text-red-800/80">{{ __('Permanently deletes the database and all its data. Type its name to confirm.') }}</p>
                            <div class="mt-2 flex gap-2">
                                <input x-model="confirm" type="text" class="rounded-lg border border-red-300 px-2 py-1 font-mono text-sm" placeholder="{{ $current->name }}" />
                                <button type="button" x-bind:disabled="confirm !== @js($current->name)" x-on:click="$wire.delete(@js($current->id), confirm)" class="rounded-lg bg-red-700 px-3 py-1 text-sm font-semibold text-white disabled:opacity-40">{{ __('Delete') }}</button>
                            </div>
                            <x-input-error :messages="$errors->get('delete')" class="mt-1" />
                        </div>
                    </div>
                @else
                    <div class="rounded-xl border border-dashed border-brand-ink/15 px-4 py-16 text-center text-sm text-brand-moss">
                        {{ __('Select a database to query it, or create one.') }}
                    </div>
                @endif
            </div>
        </div>
    </x-profile-shell>
</div>
