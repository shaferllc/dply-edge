<div class="dply-page-shell space-y-4 pt-6">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Projects'), 'href' => route('dashboard'), 'icon' => 'globe-alt'],
        ['label' => __('Storage'), 'icon' => 'archive-box'],
    ]" />

    <x-profile-shell
        :title="__('Storage')"
        :description="__('Object storage buckets for your apps. One app can use several, each as its own disk, and one bucket can be shared by several apps.')"
        icon="heroicon-o-archive-box"
    >
        @if (session('status'))
            <div class="border-b border-brand-ink/10 bg-brand-sage/10 px-4 py-2 text-sm text-brand-ink">{{ session('status') }}</div>
        @endif
        <x-input-error :messages="$errors->get('bucket')" class="px-4 pt-2" />

        <div class="space-y-4 p-4">
            <form wire:submit="create" class="flex flex-wrap items-start gap-2 rounded-xl border border-brand-ink/10 bg-white p-3 dark:bg-zinc-900">
                <div class="min-w-48 flex-1">
                    <x-text-input wire:model="name" type="text" class="block w-full font-mono text-sm" placeholder="uploads" aria-label="{{ __('Bucket name') }}" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <select wire:model="location" aria-label="{{ __('Location') }}" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900">
                    @foreach ($locations as $hint => $label)
                        <option value="{{ $hint }}">{{ __($label) }}</option>
                    @endforeach
                </select>
                <x-primary-button wire:loading.attr="disabled" wire:target="create">{{ __('Create bucket') }}</x-primary-button>
                <p class="basis-full text-xs text-brand-mist">{{ __('Billed per GB stored and per read and write, from your plan’s included usage credit first. The location cannot change later.') }}</p>
            </form>

            <div class="overflow-x-auto rounded-xl border border-brand-ink/10 bg-white dark:bg-zinc-900">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-brand-sand/40 text-xs uppercase tracking-wide text-brand-moss">
                        <tr>
                            <th class="px-3 py-2">{{ __('Bucket') }}</th>
                            <th class="px-3 py-2">{{ __('Used by') }}</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/10">
                        @forelse ($buckets as $bucket)
                            @php $users = $boundTo[$bucket['id']] ?? []; @endphp
                            <tr wire:key="bucket-{{ $bucket['id'] }}">
                                <td class="px-3 py-2 font-mono text-brand-ink">{{ $bucket['label'] }}</td>
                                <td class="px-3 py-2 text-xs text-brand-moss">
                                    @forelse ($users as $user)
                                        <a href="{{ $user['url'] }}" wire:navigate class="font-semibold text-brand-ink hover:underline">{{ $user['site'] }}</a>
                                        <span class="font-mono">({{ $user['disk'] }})</span>@if (! $loop->last), @endif
                                    @empty
                                        —
                                    @endforelse
                                </td>
                                <td class="px-3 py-2 text-right">
                                    @if ($users === [])
                                        <button type="button" wire:click="delete('{{ $bucket['id'] }}')" wire:confirm="{{ __('Delete :bucket? It must be empty.', ['bucket' => $bucket['label']]) }}" class="text-xs font-semibold text-red-600">{{ __('Delete') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-8 text-center text-brand-moss">{{ __('No buckets yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-brand-mist">{{ __('To use a bucket in an app, open the app’s Resources map, Add resource, Object storage, Attach existing.') }}</p>
        </div>
    </x-profile-shell>
</div>
