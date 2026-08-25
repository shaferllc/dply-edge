<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('Audit log'), 'icon' => 'clipboard-document-list'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Audit log')"
        :description="__('Platform-wide audit entries with filters and CSV export.')"
        icon="heroicon-o-clipboard-document-list"
    >
        <x-slot:actions>
            <button
                type="button"
                wire:click="downloadCsv"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
            >
                <x-heroicon-o-arrow-down-tray class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                {{ __('Export CSV') }}
            </button>
        </x-slot:actions>

        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-funnel"
                :title="__('Filters')"
                :note="__('Narrow by free text, action, or organization.')"
            />
            <div class="flex flex-wrap items-end gap-3 px-3 py-3 sm:px-4">
                <div class="min-w-[12rem] flex-1">
                    <label for="audit-search" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Search') }}</label>
                    <input id="audit-search" type="search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="{{ __('Action, user, or org…') }}" />
                </div>
                <div class="min-w-[10rem]">
                    <label for="audit-action" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Action') }}</label>
                    <select id="audit-action" wire:model.live="actionFilter" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                        <option value="">{{ __('All actions') }}</option>
                        @foreach ($actions as $action)
                            <option value="{{ $action }}">{{ $action }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-[12rem]">
                    <label for="audit-org" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Organization') }}</label>
                    <select id="audit-org" wire:model.live="organizationFilter" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                        <option value="">{{ __('All organizations') }}</option>
                        @foreach ($organizations as $org)
                            <option value="{{ $org->id }}">{{ $org->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                <thead class="bg-white text-brand-mist">
                    <tr>
                        <th class="px-3 py-2 font-semibold uppercase tracking-wide sm:px-4">{{ __('When') }}</th>
                        <th class="px-3 py-2 font-semibold uppercase tracking-wide">{{ __('User') }}</th>
                        <th class="px-3 py-2 font-semibold uppercase tracking-wide">{{ __('Organization') }}</th>
                        <th class="px-3 py-2 font-semibold uppercase tracking-wide">{{ __('Action') }}</th>
                        <th class="px-3 py-2 font-semibold uppercase tracking-wide">{{ __('Subject') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/5">
                    @forelse ($logs as $log)
                        <tr wire:key="audit-{{ $log->id }}">
                            <td class="whitespace-nowrap px-3 py-2 text-brand-mist sm:px-4">{{ $log->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                            <td class="max-w-[10rem] truncate px-3 py-2 text-brand-ink" title="{{ $log->user?->email }}">{{ $log->user?->name ?? '—' }}</td>
                            <td class="max-w-[10rem] truncate px-3 py-2 text-brand-moss">{{ $log->organization?->name ?? '—' }}</td>
                            <td class="max-w-[12rem] truncate px-3 py-2 font-mono text-brand-ink">{{ $log->action }}</td>
                            <td class="max-w-[14rem] truncate px-3 py-2 text-brand-moss">{{ $log->subject_summary ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-brand-mist sm:px-4">{{ __('No audit entries match your filters.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>
            {{ $logs->links() }}
        </x-slot:footer>
    </x-profile-shell>
</div>
