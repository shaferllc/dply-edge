<div class="dply-page-shell space-y-4 pt-6">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Projects'), 'href' => route('dashboard'), 'icon' => 'globe-alt'],
        ['label' => __('Queues'), 'icon' => 'queue-list'],
    ]" />

    <x-profile-shell
        :title="__('Queues')"
        :description="__('Background jobs on Dply Edge. Bind a queue to a project; container apps process it with dply/laravel or dply-rails.')"
        icon="heroicon-o-queue-list"
    >
        @if (session('status'))
            <div class="border-b border-brand-ink/10 bg-brand-sage/10 px-4 py-2 text-sm text-brand-ink">{{ session('status') }}</div>
        @endif
        <x-input-error :messages="$errors->get('queue')" class="px-4 pt-2" />

        <div class="space-y-4 p-4">
            <form wire:submit="create" class="flex flex-wrap items-start gap-2 rounded-xl border border-brand-ink/10 bg-white p-3 dark:bg-zinc-900">
                <div class="min-w-48 flex-1">
                    <x-text-input wire:model="name" type="text" class="block w-full font-mono text-sm" placeholder="emails" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <x-primary-button wire:loading.attr="disabled">{{ __('Create queue') }}</x-primary-button>
                <p class="basis-full text-xs text-brand-mist">
                    {{ $limit === null ? __(':count queues', ['count' => $queues->count()]) : __(':count of :limit on your plan', ['count' => $queues->count(), 'limit' => $limit]) }}
                </p>
            </form>

            <div class="overflow-x-auto rounded-xl border border-brand-ink/10 bg-white dark:bg-zinc-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-brand-sand/40 text-xs uppercase tracking-wide text-brand-moss">
                        <tr>
                            <th class="px-3 py-2">{{ __('Queue') }}</th>
                            <th class="px-3 py-2">{{ __('Waiting') }}</th>
                            <th class="px-3 py-2">{{ __('Bound to') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/10">
                        @forelse ($queues as $queue)
                            <tr>
                                <td class="px-3 py-2 font-mono text-brand-ink">{{ $queue->name }}</td>
                                <td class="px-3 py-2 font-mono">{{ number_format($backlogs[$queue->cloudflare_id] ?? 0) }}</td>
                                <td class="px-3 py-2 text-xs text-brand-moss">{{ implode(', ', $boundTo[$queue->cloudflare_name] ?? []) ?: '—' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <button type="button" wire:click="sendTest('{{ $queue->id }}')" class="text-xs font-semibold text-brand-sage">{{ __('Send test') }}</button>
                                    <button type="button" wire:click="delete('{{ $queue->id }}')" wire:confirm="{{ __('Delete :queue and any messages in it?', ['queue' => $queue->name]) }}" class="ml-3 text-xs font-semibold text-red-600">{{ __('Delete') }}</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-8 text-center text-brand-moss">{{ __('No queues yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($queues->isNotEmpty())
                <form wire:submit="attach" class="grid gap-2 rounded-xl border border-brand-ink/10 bg-white p-3 sm:grid-cols-4 dark:bg-zinc-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss sm:col-span-4">{{ __('Attach to a project') }}</p>
                    <select wire:model="attachQueue" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900">
                        <option value="">{{ __('Queue…') }}</option>
                        @foreach ($queues as $queue)<option value="{{ $queue->id }}">{{ $queue->name }}</option>@endforeach
                    </select>
                    <select wire:model="attachSite" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900">
                        <option value="">{{ __('Project…') }}</option>
                        @foreach ($sites as $site)<option value="{{ $site->id }}">{{ $site->name }}</option>@endforeach
                    </select>
                    <x-text-input wire:model="bindingName" type="text" class="font-mono text-sm" />
                    <x-secondary-button type="submit" class="justify-center">{{ __('Attach') }}</x-secondary-button>
                    <x-input-error :messages="array_merge($errors->get('attachQueue'), $errors->get('attachSite'), $errors->get('bindingName'))" class="sm:col-span-4" />
                    <p class="text-xs text-brand-mist sm:col-span-4">{{ __('Container apps: bind as JOBS (the default for dply/laravel and dply-rails) and redeploy — dply wires the consumer for you.') }}</p>
                </form>
            @endif
        </div>
    </x-profile-shell>
</div>
