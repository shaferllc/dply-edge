<div>
    {{-- Read-only for anyone who cannot configure this app (org Deployer, app Viewer/Deployer). --}}
    <fieldset @disabled(! auth()->user()?->can('update', $site)) class="min-w-0">
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'what' => __('Tags load analytics, pixels and chat widgets from the Edge — pick a tool, paste its ID, and Edge adds the loader and setup code. No git deploy.'),
            'steps' => [
                __('Choose Add a tool, pick one from the catalog and paste its ID — or add a custom https:// script URL.'),
                __('Optional: set a path so the tool only fires on matching pages (e.g. /checkout/*).'),
                __('Optional: tick “Wait for consent” — tools not marked Necessary wait until your banner calls window.__dplyTags.grant().'),
                __('Save the tool. Tags load on the next page request, no deploy needed.'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Use Snippets for inline HTML'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'snippets']),
                ],
            ],
            'tips' => [
                __('Send custom events with window.__dplyTags.track(\'signup\', {plan: \'pro\'}) — forwarded to every loaded tool.'),
                __('Custom script URLs must be https://.'),
                __('Repo config (dply.yaml) lives under Advanced.'),
            ],
        ])

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

    </section>

    @php
        $count = count($tools);
        $waiting = collect($tools)->where('purpose', '!=', 'necessary')->count();
        $where = fn (array $t): string => in_array(trim((string) ($t['path'] ?? '')), ['', '*', '/*'], true)
            ? __('on every page')
            : __('on :path', ['path' => $t['path']]);
        $editing = $editingTool !== null ? ($tools[$editingTool] ?? null) : null;
        $editingVendor = $editing ? ($vendors[$editing['vendor']] ?? null) : null;
        $field = 'mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage dark:bg-zinc-900';
    @endphp

    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Tags') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if (! $managedDelivery)
                    {{ __('Tags need Dply-hosted Edge delivery.') }}
                @elseif ($count === 0)
                    {{ __('No tags yet. Add a tool to load analytics, pixels or a chat widget from the Edge — no deploy needed.') }}
                @elseif (! $enabled)
                    {{ trans_choice(':count tool is set up, but tags are off, so nothing loads.|:count tools are set up, but tags are off, so nothing loads.', $count) }}
                @else
                    <span class="text-brand-sage">{{ trans_choice(':count tool|:count tools', $count) }}</span>
                    {{ trans_choice('loads on your site from the Edge.|load on your site from the Edge.', $count) }}
                    @if (! $consent_required)
                        {{ __('They all load right away — visitors aren’t asked for consent.') }}
                    @elseif ($waiting === 0)
                        {{ __('All are marked Necessary, so they load without waiting for consent.') }}
                    @else
                        {{ trans_choice(':count waits for visitor consent.|:count wait for visitor consent.', $waiting) }}
                    @endif
                @endif
            </p>
        </div>

        <div>
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 pb-2">
                <p class="text-sm font-semibold text-brand-ink">{{ __('On your site') }}</p>
                <button type="button" wire:click="openPicker" @disabled(! $managedDelivery) class="inline-flex min-h-9 items-center gap-1 text-sm font-medium text-brand-sage hover:underline disabled:opacity-50">
                    <x-heroicon-m-plus class="h-4 w-4" aria-hidden="true" />{{ __('Add a tool') }}
                </button>
            </div>
            @if ($count === 0)
                <p class="border-b border-brand-ink/10 py-4 text-sm text-brand-moss">{{ __('Nothing added yet.') }}</p>
            @else
                <ul>
                    @foreach ($tools as $i => $tool)
                        <li class="border-b border-brand-ink/10" wire:key="tag-row-{{ $i }}">
                            <button type="button" wire:click="editTool({{ $i }})" class="flex min-h-12 w-full items-center gap-3 py-3 text-left hover:bg-brand-sand/20">
                                <span class="flex-1 text-sm text-brand-ink sm:text-base">
                                    {{ $tool['name'] }}@unless (isset($vendors[$tool['vendor']])) <span class="text-brand-mist">({{ __('custom script') }})</span>@endunless
                                    {{ $where($tool) }}
                                </span>
                                <span class="text-xs text-brand-moss">{{ __(ucfirst($tool['purpose'])) }}</span>
                                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Settings') }}</p>
            <label class="flex min-h-12 cursor-pointer items-center gap-3 border-b border-brand-ink/10 py-3">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ __('Load tags on this site') }}</span>
                <input type="checkbox" wire:model.live="enabled" @disabled(! $managedDelivery) class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
            </label>
            <label class="flex min-h-12 cursor-pointer items-center gap-3 border-b border-brand-ink/10 py-3">
                <span class="flex-1">
                    <span class="block text-sm text-brand-ink sm:text-base">{{ __('Wait for consent before loading analytics and marketing tools') }}</span>
                    <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Your banner calls window.__dplyTags.grant() (or grant([\'analytics\'])). Turning this on also turns tags on.') }}</span>
                </span>
                <input type="checkbox" wire:model.live="consent_required" @disabled(! $managedDelivery) class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
            </label>
        </div>
    </section>

    <x-modal name="edge-tag-tool" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        <div class="space-y-6 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <h2 class="text-lg font-semibold text-brand-ink">
                    {{ $pickingTool || ! $editing ? __('Add a tool') : $editing['name'] }}
                </h2>
                <button type="button" wire:click="closeTool" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                    <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            @if ($pickingTool || ! $editing)
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($vendors as $key => $vendor)
                        <button type="button" wire:click="addVendor('{{ $key }}')" class="flex min-h-14 flex-col items-start justify-center rounded-lg border border-brand-ink/10 px-4 py-2 text-left hover:border-brand-sage/50 hover:bg-brand-sage/5">
                            <span class="text-sm font-semibold text-brand-ink">{{ $vendor['name'] }}</span>
                            <span class="text-xs text-brand-mist">{{ __(ucfirst($vendor['purpose'])) }}</span>
                        </button>
                    @endforeach
                    <button type="button" wire:click="addTool" class="flex min-h-14 flex-col items-start justify-center rounded-lg border border-dashed border-brand-ink/20 px-4 py-2 text-left hover:border-brand-sage/50">
                        <span class="text-sm font-semibold text-brand-ink">{{ __('Custom script') }}</span>
                        <span class="text-xs text-brand-mist">{{ __('Any https:// script URL') }}</span>
                    </button>
                </div>
            @else
                @php $i = $editingTool; @endphp
                <div class="space-y-4" wire:key="tag-edit-{{ $i }}">
                    <div>
                        <x-input-label for="tag-name" :value="__('Name')" />
                        <input id="tag-name" type="text" wire:model="tools.{{ $i }}.name" class="{{ $field }}" />
                        <x-input-error :messages="$errors->get('tools.'.$i.'.name')" class="mt-1" />
                    </div>
                    @if ($editingVendor)
                        <div>
                            <x-input-label for="tag-id" :value="__(':vendor ID', ['vendor' => $editingVendor['label']])" />
                            <input id="tag-id" type="text" wire:model="tools.{{ $i }}.id" placeholder="{{ $editingVendor['placeholder'] }}" class="{{ $field }} font-mono" />
                            <p class="mt-1 text-xs text-brand-mist">{{ $editingVendor['hint'] }}</p>
                            <x-input-error :messages="$errors->get('tools.'.$i.'.id')" class="mt-1" />
                        </div>
                    @else
                        <div>
                            <x-input-label for="tag-src" :value="__('Script URL (https)')" />
                            <input id="tag-src" type="url" wire:model="tools.{{ $i }}.src" placeholder="https://…" class="{{ $field }} font-mono" />
                            <x-input-error :messages="$errors->get('tools.'.$i.'.src')" class="mt-1" />
                        </div>
                        <label class="flex items-center gap-2 text-sm text-brand-ink">
                            <input type="checkbox" wire:model="tools.{{ $i }}.async" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                            {{ __('Load async') }}
                        </label>
                    @endif
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="tag-path" :value="__('Fire on path')" />
                            <input id="tag-path" type="text" wire:model="tools.{{ $i }}.path" placeholder="/*" class="{{ $field }} font-mono" />
                            <x-input-error :messages="$errors->get('tools.'.$i.'.path')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="tag-purpose" :value="__('Consent purpose')" />
                            <select id="tag-purpose" wire:model="tools.{{ $i }}.purpose" class="{{ $field }}">
                                <option value="necessary">{{ __('Necessary') }}</option>
                                <option value="analytics">{{ __('Analytics') }}</option>
                                <option value="marketing">{{ __('Marketing') }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-2">
                    <button type="button" wire:click="removeEditingTool" class="text-sm font-semibold text-red-600 hover:underline">{{ __('Remove tool') }}</button>
                    <span class="flex gap-2">
                        <button type="button" wire:click="closeTool" class="rounded-lg border border-brand-ink/15 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                        <x-primary-button type="button" wire:click="saveTool" wire:loading.attr="disabled" wire:target="saveTool">{{ __('Save') }}</x-primary-button>
                    </span>
                </div>
            @endif
        </div>
    </x-modal>

    @php
        $hasRepoTags = $repoTags !== [];
        $repoTagCount = count(is_array($repoTags['tools'] ?? null) ? $repoTags['tools'] : []);
    @endphp
    <x-edge-yaml-advanced
        :site="$site"
        :file="$sourcePath"
        :has-repo="$hasRepoTags"
        :repo-badge="$repoTagCount > 0 ? (string) $repoTagCount : null"
        :hint="__('Commit at the repo root. Dashboard Save overrides this section.')"
    >
        <x-slot:status>
            @if ($hasRepoTags)
                <dl class="grid grid-cols-1 gap-y-1.5 text-xs sm:grid-cols-[8rem_1fr]">
                    <dt class="text-brand-mist">{{ __('Enabled') }}</dt>
                    <dd class="text-brand-moss">{{ ($repoTags['enabled'] ?? false) ? __('Yes') : __('No') }}</dd>
                    @if ($repoTagCount > 0)
                        <dt class="text-brand-mist">{{ __('Tools') }}</dt>
                        <dd class="text-brand-moss">{{ $repoTagCount }}</dd>
                    @endif
                    @if (! empty($repoTags['consent_required']))
                        <dt class="text-brand-mist">{{ __('Consent') }}</dt>
                        <dd class="text-brand-moss">{{ __('Required in repo') }}</dd>
                    @endif
                </dl>
                <p class="mt-2 text-xs text-brand-mist">{{ __('Dashboard values override the repo when both are set.') }}</p>
            @else
                <p class="text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $sourcePath]) }}</p>
            @endif
        </x-slot:status>
tags:
  enabled: true
  consent_required: false
  tools:
    - vendor: ga4
      id: G-XXXXXXXXXX
      purpose: analytics
      path: /*
    - name: Chat widget
      src: "https://widget.example.com/chat.js"
    </x-edge-yaml-advanced>
    </fieldset>
</div>
