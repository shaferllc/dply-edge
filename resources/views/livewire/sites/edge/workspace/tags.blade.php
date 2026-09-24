<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-tags',
            'what' => __('Tags load analytics, pixels and chat widgets from the Edge — pick a tool, paste its ID, and Edge adds the loader and setup code. No git deploy.'),
            'steps' => [
                __('Add a tool from the catalog and paste its ID, or add a custom script URL.'),
                __('Optional: set a path so the tool only fires on matching pages (e.g. /checkout/*).'),
                __('Optional: turn on Consent — tools not marked Necessary wait until your banner calls window.__dplyTags.grant().'),
                __('Enable and Save. Tags inject on subsequent page loads.'),
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

        <div class="mt-4 space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span class="text-sm font-medium text-brand-ink">{{ __('Enable tag manager') }}</span>
            </label>

            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5">
                <span class="min-w-0 flex-1 basis-56">
                    <span class="block text-sm font-medium text-brand-ink">{{ __('Require consent') }}</span>
                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Tools not marked Necessary are held until your consent banner calls `window.__dplyTags.grant()` (or `grant([\'analytics\'])`). The choice is remembered in localStorage `dply_tag_consent`. Saving with this on also enables the tag manager.') }}</span>
                </span>
                <x-toggle-switch
                    :enabled="(bool) $consent_required"
                    wire:model.live="consent_required" @disabled(! $managedDelivery)
                    :on-label="__('On')"
                    :off-label="__('Off')"
                />
            </div>

            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-3 dark:bg-brand-sand/10 sm:px-4">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Add a tool') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($vendors as $key => $vendor)
                        <button
                            type="button"
                            wire:click="addVendor('{{ $key }}')"
                            @disabled(! $managedDelivery)
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:border-brand-sage/40 hover:bg-brand-sage/5 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-900"
                            title="{{ $vendor['name'] }}"
                        >
                            {{ $vendor['label'] }}
                            <span class="font-normal text-brand-mist">+</span>
                        </button>
                    @endforeach
                    <button type="button" wire:click="addTool" @disabled(! $managedDelivery) class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-brand-ink/20 px-2.5 py-1.5 text-xs font-semibold text-brand-moss transition hover:border-brand-sage/40 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ __('Custom script') }}
                        <span class="font-normal text-brand-mist">+</span>
                    </button>
                </div>
            </div>

            @foreach ($tools as $i => $tool)
                @php $vendor = $vendors[$tool['vendor']] ?? null; @endphp
                <div class="grid gap-3 rounded-xl border border-brand-ink/10 p-3 sm:grid-cols-6" wire:key="tag-{{ $i }}">
                    <div class="sm:col-span-2">
                        <x-input-label :value="__('Name')" />
                        <x-text-input wire:model="tools.{{ $i }}.name" type="text" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                        <x-input-error :messages="$errors->get('tools.'.$i.'.name')" class="mt-1" />
                    </div>
                    @if ($vendor)
                        <div class="sm:col-span-4">
                            <x-input-label :value="__(':vendor ID', ['vendor' => $vendor['label']])" />
                            <x-text-input wire:model="tools.{{ $i }}.id" type="text" class="mt-1 block w-full font-mono text-sm" :placeholder="$vendor['placeholder']" @disabled(! $managedDelivery) />
                            <p class="mt-1 text-xs text-brand-mist">{{ $vendor['hint'] }}</p>
                            <x-input-error :messages="$errors->get('tools.'.$i.'.id')" class="mt-1" />
                        </div>
                    @else
                        <div class="sm:col-span-4">
                            <x-input-label :value="__('Script URL (https)')" />
                            <x-text-input wire:model="tools.{{ $i }}.src" type="url" class="mt-1 block w-full font-mono text-sm" placeholder="https://…" @disabled(! $managedDelivery) />
                            <x-input-error :messages="$errors->get('tools.'.$i.'.src')" class="mt-1" />
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <x-input-label :value="__('Fire on path')" />
                        <x-text-input wire:model="tools.{{ $i }}.path" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="/*" @disabled(! $managedDelivery) />
                        <x-input-error :messages="$errors->get('tools.'.$i.'.path')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label :value="__('Consent purpose')" />
                        <select wire:model="tools.{{ $i }}.purpose" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900" @disabled(! $managedDelivery)>
                            <option value="necessary">{{ __('Necessary') }}</option>
                            <option value="analytics">{{ __('Analytics') }}</option>
                            <option value="marketing">{{ __('Marketing') }}</option>
                        </select>
                    </div>
                    <div class="flex items-end justify-between gap-3 sm:col-span-2">
                        @unless ($vendor)
                            <label class="flex items-center gap-2 pb-2 text-sm text-brand-ink">
                                <input type="checkbox" wire:model="tools.{{ $i }}.async" class="rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                                {{ __('Async') }}
                            </label>
                        @endunless
                        <button type="button" wire:click="removeTool({{ $i }})" class="ml-auto pb-2 text-xs font-semibold text-red-600" @disabled(! $managedDelivery)>{{ __('Remove') }}</button>
                    </div>
                </div>
            @endforeach

            <div class="flex justify-end">
                <x-primary-button type="button" wire:click="save" @disabled(! $managedDelivery)>{{ __('Save') }}</x-primary-button>
            </div>
        </div>
    </section>

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
</div>
