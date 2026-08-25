<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-tags',
            'what' => __('Tags load third-party scripts (analytics, ads, chat) from the Edge so you can add or remove them without a git deploy.'),
            'steps' => [
                __('Add each tool with a name and https:// script URL from the vendor.'),
                __('Optional: turn on Consent helper so your CMP can gate scripts via window.__dplyTags.consent / localStorage dply_tag_consent.'),
                __('Enable and Save. Scripts inject on subsequent page loads.'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Use Snippets for inline HTML'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-snippets']),
                ],
            ],
            'tips' => [
                __('Only https:// sources are allowed. Prefer async for non-critical pixels.'),
                __('For one-off HTML (not a remote script URL), use Snippets instead.'),
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
                    <span class="block text-sm font-medium text-brand-ink">{{ __('Consent helper') }}</span>
                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Injects `window.__dplyTags.consent` on every HTML page (localStorage `dply_tag_consent`). Works without script URLs — Save republishes delivery. Saving with this on also enables the tag manager.') }}</span>
                </span>
                <x-toggle-switch
                    :enabled="(bool) $consent_required"
                    wire:model.live="consent_required" @disabled(! $managedDelivery)
                    :on-label="__('On')"
                    :off-label="__('Off')"
                />
            </div>

            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-3 dark:bg-brand-sand/10 sm:px-4">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Examples') }}</p>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Click to add a starter script URL, then replace the placeholder ID with yours before Save.') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($examples as $example)
                        <button
                            type="button"
                            wire:click="addExample('{{ $example['key'] }}')"
                            @disabled(! $managedDelivery)
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:border-brand-sage/40 hover:bg-brand-sage/5 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-900"
                            title="{{ $example['hint'] }}"
                        >
                            {{ $example['label'] }}
                            <span class="font-normal text-brand-mist">+</span>
                        </button>
                    @endforeach
                </div>
            </div>

            @foreach ($tools as $i => $tool)
                <div class="grid gap-3 rounded-xl border border-brand-ink/10 p-3 sm:grid-cols-3" wire:key="tag-{{ $i }}">
                    <div>
                        <x-input-label :value="__('Name')" />
                        <x-text-input wire:model="tools.{{ $i }}.name" type="text" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label :value="__('Script URL (https)')" />
                        <x-text-input wire:model="tools.{{ $i }}.src" type="url" class="mt-1 block w-full font-mono text-sm" placeholder="https://…" @disabled(! $managedDelivery) />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-brand-ink sm:col-span-2">
                        <input type="checkbox" wire:model="tools.{{ $i }}.async" class="rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                        {{ __('Async') }}
                    </label>
                    @if (count($tools) > 1)
                        <button type="button" wire:click="removeTool({{ $i }})" class="text-xs font-semibold text-red-600">{{ __('Remove') }}</button>
                    @endif
                </div>
            @endforeach

            <div class="flex flex-wrap items-center justify-between gap-3">
                <button type="button" wire:click="addTool" class="text-sm font-semibold text-brand-sage" @disabled(! $managedDelivery)>{{ __('Add tag') }}</button>
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
    - name: Google Analytics
      src: "https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX"
      async: true
    </x-edge-yaml-advanced>
</div>
