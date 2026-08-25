<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail
        doc-route="docs.index"
        :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Status pages'), 'href' => route('status-pages.index'), 'icon' => 'signal'],
            ['label' => $statusPage->name, 'icon' => 'megaphone'],
        ]"
    />

    <x-profile-shell
        class="mt-4"
        :title="$statusPage->name"
        :description="__('Configure monitors, incidents, and visibility for this status page.')"
        icon="heroicon-o-signal"
    >
        <x-slot:actions>
            <x-outline-link href="{{ route('status-pages.index') }}" wire:navigate size="sm">
                <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Status pages') }}
            </x-outline-link>
            <x-outline-link href="{{ route('status.public', $statusPage) }}" target="_blank" rel="noopener" size="sm">
                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('View public page') }}
            </x-outline-link>
            @can('delete', $statusPage)
                <button
                    type="button"
                    wire:click="openConfirmActionModal('destroyPage', [], @js(__('Delete status page')), @js(__('Delete this status page? Monitors and incidents are removed.')), @js(__('Delete')), true)"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 shadow-sm transition-colors hover:bg-rose-50"
                >
                    <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Delete') }}
                </button>
            @endcan
        </x-slot:actions>

        <x-slot:stats>
            <dl class="grid grid-cols-3 gap-2">
                <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Monitors') }}</dt>
                    <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $statusPage->monitors->count() }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Incidents') }}</dt>
                    <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $statusPage->incidents->count() }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Visibility') }}</dt>
                    <dd class="mt-0.5 text-sm font-semibold text-brand-ink">{{ $statusPage->is_public ? __('Public') : __('Private') }}</dd>
                </div>
            </dl>
        </x-slot:stats>

        @if (session('success'))
            <div class="border-b border-brand-ink/10 bg-emerald-50/60 px-3 py-2.5 text-sm text-emerald-900 sm:px-4" role="status">{{ session('success') }}</div>
        @endif

        {{-- Page details --}}
        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-identification"
                :title="__('Page details')"
                :note="__('Name, description, and who can reach the public URL.')"
            />
            <div class="px-3 py-3 sm:px-4">
                <form wire:submit="saveDetails" class="max-w-xl space-y-4">
                    <div>
                        <x-input-label for="edit-name" :value="__('Name')" />
                        <x-text-input id="edit-name" wire:model="editName" type="text" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('editName')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="edit-desc" :value="__('Description')" />
                        <textarea id="edit-desc" wire:model="editDescription" rows="2" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"></textarea>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-brand-moss">
                        <input type="checkbox" wire:model="is_public" class="rounded border-brand-ink/30 text-brand-sage focus:ring-brand-sage" />
                        {{ __('Public status page (anyone with the link can view)') }}
                    </label>
                    <div>
                        <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                    </div>
                </form>
                <p class="mt-4 text-xs text-brand-mist">
                    {{ __('Public URL:') }}
                    <a href="{{ route('status.public', $statusPage) }}" target="_blank" rel="noopener" class="font-mono text-brand-moss underline underline-offset-2 hover:text-brand-ink">{{ url('/status/'.$statusPage->slug) }}</a>
                </p>
            </div>
        </section>

        {{-- Monitors --}}
        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-heart"
                :title="__('Monitors')"
                :count="$statusPage->monitors->count()"
                :note="__('Servers and sites follow their health checks; site uptime monitors use the scheduled HTTP checks configured per site.')"
            />

            @if ($statusPage->monitors->isEmpty())
                <p class="px-3 py-5 text-sm text-brand-mist sm:px-4">{{ __('No monitors yet.') }}</p>
            @else
                <ul class="divide-y divide-brand-ink/5">
                    @foreach ($statusPage->monitors as $mon)
                        <li class="flex items-center justify-between gap-3 px-3 py-2.5 sm:px-4">
                            <div class="min-w-0">
                                <span class="font-medium text-brand-ink">{{ $mon->displayLabel() }}</span>
                                <span class="ml-2 text-xs text-brand-mist">{{ class_basename($mon->monitorable_type) }}</span>
                            </div>
                            <button type="button" wire:click="removeMonitor('{{ $mon->id }}')" class="shrink-0 text-xs font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="flex flex-wrap items-end gap-3 border-t border-brand-ink/5 px-3 py-3 sm:px-4">
                <div>
                    <x-input-label for="mk" :value="__('Type')" />
                    <select id="mk" wire:model.live="monitorKind" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                        <option value="server">{{ __('Server') }}</option>
                        <option value="site">{{ __('Site') }}</option>
                        <option value="site_uptime">{{ __('Site uptime check') }}</option>
                    </select>
                </div>
                @if ($monitorKind === 'site_uptime')
                    <div class="min-w-[12rem]">
                        <x-input-label for="msite" :value="__('Site')" />
                        <select id="msite" wire:model.live="monitorSiteId" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach ($sites as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('monitorSiteId')" class="mt-1" />
                    </div>
                    <div class="min-w-[12rem]">
                        <x-input-label for="muptime" :value="__('Uptime monitor')" />
                        <select id="muptime" wire:model="monitorId" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" @disabled(! $monitorSiteId || $uptimeMonitorsForPicker->isEmpty())>
                            <option value="">{{ __('Choose…') }}</option>
                            @foreach ($uptimeMonitorsForPicker as $um)
                                <option value="{{ $um->id }}">{{ $um->label }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('monitorId')" class="mt-1" />
                    </div>
                @else
                    <div class="min-w-[12rem]">
                        <x-input-label for="mid" :value="__('Resource')" />
                        <select id="mid" wire:model="monitorId" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                            <option value="">{{ __('Choose…') }}</option>
                            @if ($monitorKind === 'server')
                                @foreach ($servers as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            @else
                                @foreach ($sites as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            @endif
                        </select>
                        <x-input-error :messages="$errors->get('monitorId')" class="mt-1" />
                    </div>
                @endif
                <div class="min-w-[10rem]">
                    <x-input-label for="ml" :value="__('Label (optional)')" />
                    <x-text-input id="ml" wire:model="monitorLabel" type="text" class="mt-1 block w-full text-sm" placeholder="{{ __('Override display name') }}" />
                </div>
                <x-secondary-button type="button" wire:click="addMonitor">{{ __('Add monitor') }}</x-secondary-button>
            </div>
        </section>

        {{-- Incidents --}}
        <section>
            <x-workspace-panel-head
                dense
                icon="heroicon-o-megaphone"
                :title="__('Incidents')"
                :count="$statusPage->incidents->count()"
                :note="__('Open an incident, then post updates as it moves toward resolved.')"
            />

            <form wire:submit="createIncident" class="max-w-2xl space-y-3 border-b border-brand-ink/5 px-3 py-3 sm:px-4">
                <div>
                    <x-input-label for="it" :value="__('Title')" />
                    <x-text-input id="it" wire:model="incidentTitle" type="text" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('incidentTitle')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="ii" :value="__('Impact')" />
                    <select id="ii" wire:model="incidentImpact" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                        <option value="none">{{ __('None') }}</option>
                        <option value="minor">{{ __('Minor') }}</option>
                        <option value="major">{{ __('Major') }}</option>
                        <option value="critical">{{ __('Critical') }}</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="im" :value="__('First update')" />
                    <textarea id="im" wire:model="incidentMessage" rows="4" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"></textarea>
                    <x-input-error :messages="$errors->get('incidentMessage')" class="mt-1" />
                </div>
                <x-primary-button type="submit">{{ __('Open incident') }}</x-primary-button>
            </form>

            @forelse ($statusPage->incidents->sortByDesc('started_at') as $incident)
                <div class="border-b border-brand-ink/5 px-3 py-4 last:border-0 sm:px-4">
                    <div class="mb-2 flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h4 class="font-semibold text-brand-ink">{{ $incident->title }}</h4>
                            <p class="mt-1 text-xs text-brand-mist">
                                {{ $incident->started_at->toDayDateTimeString() }}
                                · {{ ucfirst($incident->impact) }} impact
                                · {{ str_replace('_', ' ', $incident->state) }}
                                @if ($incident->resolved_at)
                                    · {{ __('Resolved :time', ['time' => $incident->resolved_at->diffForHumans()]) }}
                                @endif
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            <button type="button" wire:click="setIncidentState('{{ $incident->id }}', '{{ App\Models\Incident::STATE_INVESTIGATING }}')" class="rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Investigating') }}</button>
                            <button type="button" wire:click="setIncidentState('{{ $incident->id }}', '{{ App\Models\Incident::STATE_IDENTIFIED }}')" class="rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Identified') }}</button>
                            <button type="button" wire:click="setIncidentState('{{ $incident->id }}', '{{ App\Models\Incident::STATE_MONITORING }}')" class="rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Monitoring') }}</button>
                            <button type="button" wire:click="setIncidentState('{{ $incident->id }}', '{{ App\Models\Incident::STATE_RESOLVED }}')" class="rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">{{ __('Resolved') }}</button>
                        </div>
                    </div>
                    <ul class="mb-4 space-y-3 text-sm text-brand-moss">
                        @foreach ($incident->incidentUpdates as $u)
                            <li class="border-l-2 border-brand-ink/10 pl-3">
                                <span class="text-xs text-brand-mist">{{ $u->created_at->toDayDateTimeString() }}</span>
                                <p class="whitespace-pre-wrap">{{ $u->body }}</p>
                            </li>
                        @endforeach
                    </ul>
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="min-w-[12rem] flex-1">
                            <x-input-label :value="__('Add update')" />
                            <textarea wire:model="updateBodies.{{ $incident->id }}" rows="2" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="{{ __('Status update…') }}"></textarea>
                        </div>
                        <x-secondary-button type="button" wire:click="addIncidentUpdate('{{ $incident->id }}')">{{ __('Post') }}</x-secondary-button>
                    </div>
                </div>
            @empty
                <p class="px-3 py-6 text-sm text-brand-mist sm:px-4">{{ __('No incidents yet.') }}</p>
            @endforelse
        </section>
    </x-profile-shell>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
