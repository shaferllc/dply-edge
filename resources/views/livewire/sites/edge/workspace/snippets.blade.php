<div>
    {{-- Read-only for anyone who cannot configure this app (org Deployer, app Viewer/Deployer). --}}
    <fieldset @disabled(! auth()->user()?->can('update', $site)) class="min-w-0">
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'snippets',
            'what' => __('Snippets inject small HTML into matching pages at the Edge — banners, pixels, or support widgets — without rebuilding or redeploying your app.'),
            'steps' => [
                __('Add a snippet into the head or body slot, and set a path pattern (/* for all pages).'),
                __('Paste the HTML (script tags, meta, markup). Keep it small and trusted.'),
                __('Save the snippet. Delivery republishes; visitors see it on the next request.'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Prefer Tags for remote scripts'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'tags']),
                ],
            ],
            'tips' => [
                __('Prefer Tags for third-party https:// script URLs; use Snippets for inline markup or one-off HTML.'),
                __('Path /* matches everything. Narrow paths (e.g. /blog/*) keep marketing scripts off app routes.'),
                __('Repo config (dply.yaml) lives under Advanced.'),
            ],
        ])

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

    </section>

    @php
        $where = fn (array $i): string => in_array(trim((string) $i['path']), ['', '*', '/*'], true) ? __('every page') : $i['path'];
        $slots = [
            'head' => ['before' => __('… your app’s head …'), 'add' => __('Add to head'), 'tone' => 'border-brand-sage/50', 'text' => 'text-brand-sage'],
            'body' => ['before' => __('… your app’s page …'), 'add' => __('Add to end of body'), 'tone' => 'border-sky-500/50', 'text' => 'text-sky-600 dark:text-sky-400'],
        ];
        $editing = $editingItem !== null ? ($items[$editingItem] ?? null) : null;
        $isNew = $editing !== null && trim((string) $editing['html']) === '' && trim((string) $editing['name']) === '';
        $field = 'mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage dark:bg-zinc-900';
    @endphp

    <section class="space-y-5 border-b border-brand-ink/10 px-5 py-6 sm:px-6">
        <p class="text-sm text-brand-moss">{{ __('Where each snippet lands in your pages. Click one to edit it, or add one into a slot.') }}</p>

        <div class="overflow-x-auto rounded-2xl border border-brand-ink/10 bg-brand-sand/10 p-5 font-mono text-sm sm:p-6">
            <p class="text-brand-mist">&lt;html&gt;</p>
            <div class="ml-4 mt-2 space-y-2 sm:ml-6">
                @foreach ($slots as $phase => $slot)
                    <p class="text-brand-mist">&lt;{{ $phase }}&gt; <span class="font-sans">{{ $slot['before'] }}</span></p>
                    <div class="ml-4 space-y-2 rounded-xl border border-dashed {{ $slot['tone'] }} p-3 sm:ml-6" wire:key="snippet-slot-{{ $phase }}">
                        @foreach ($items as $i => $item)
                            @continue($item['phase'] !== $phase)
                            <button type="button" wire:click="editItem({{ $i }})" wire:key="snippet-{{ $i }}" class="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg bg-white px-3 text-left font-sans hover:bg-brand-sand/40 dark:bg-zinc-900">
                                <span class="text-sm font-semibold text-brand-ink">{{ $item['name'] }}</span>
                                <span class="font-mono text-xs text-brand-mist">{{ $where($item) }}</span>
                            </button>
                        @endforeach
                        <button type="button" wire:click="newItem('{{ $phase }}')" @disabled(! $managedDelivery) class="inline-flex min-h-9 items-center gap-1 font-sans text-sm font-medium {{ $slot['text'] }} hover:underline disabled:opacity-50">
                            <x-heroicon-m-plus class="h-4 w-4" aria-hidden="true" />{{ $slot['add'] }}
                        </button>
                    </div>
                    <p class="text-brand-mist">&lt;/{{ $phase }}&gt;</p>
                @endforeach
            </div>
            <p class="mt-2 text-brand-mist">&lt;/html&gt;</p>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <label class="flex cursor-pointer items-center gap-3 text-sm text-brand-ink">
                <input type="checkbox" wire:model.live="enabled" @disabled(! $managedDelivery) class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                @if ($enabled)
                    {{ __('Snippets on · :n added at the Edge, no redeploy', ['n' => count($items)]) }}
                @else
                    {{ __('Snippets off — nothing is added to your pages') }}
                @endif
            </label>
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'tags']) }}" wire:navigate class="text-sm text-brand-moss hover:text-brand-ink hover:underline">{{ __('For remote https:// scripts, use Tags instead.') }}</a>
        </div>
    </section>

    <x-modal name="edge-snippet" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        @if ($editing)
            @php $i = $editingItem; @endphp
            <div class="space-y-5 p-6 sm:p-7" wire:key="snippet-edit-{{ $i }}">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ $isNew ? __('New snippet') : $editing['name'] }}</h2>
                    <button type="button" wire:click="closeItem" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                @if ($isNew)
                    <div>
                        <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Start from an example') }}</p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($examples as $example)
                                <button type="button" wire:click="useExample('{{ $example['key'] }}')" class="flex min-h-12 flex-col items-start justify-center rounded-lg border border-brand-ink/10 px-3 py-2 text-left hover:border-brand-sage/50 hover:bg-brand-sage/5">
                                    <span class="text-sm font-semibold text-brand-ink">{{ $example['name'] }}</span>
                                    <span class="text-xs text-brand-mist">{{ $example['hint'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <x-input-label for="snippet-name" :value="__('Name')" />
                        <input id="snippet-name" type="text" wire:model="items.{{ $i }}.name" class="{{ $field }}" />
                        <x-input-error :messages="$errors->get('items.'.$i.'.name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="snippet-phase" :value="__('Add it')" />
                        <select id="snippet-phase" wire:model="items.{{ $i }}.phase" class="{{ $field }}">
                            <option value="head">{{ __('In the head') }}</option>
                            <option value="body">{{ __('At the end of the body') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="snippet-path" :value="__('On pages matching')" />
                        <input id="snippet-path" type="text" wire:model="items.{{ $i }}.path" placeholder="/*" class="{{ $field }} font-mono" />
                        <x-input-error :messages="$errors->get('items.'.$i.'.path')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <x-input-label for="snippet-html" :value="__('HTML')" />
                    <textarea id="snippet-html" wire:model="items.{{ $i }}.html" rows="8" class="{{ $field }} font-mono text-xs leading-relaxed"></textarea>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Keep it small and trusted. Up to 8,000 characters.') }}</p>
                    <x-input-error :messages="$errors->get('items.'.$i.'.html')" class="mt-1" />
                </div>

                <div class="flex items-center justify-between gap-2">
                    @if ($isNew)
                        <span></span>
                    @else
                        <button type="button" wire:click="removeEditingItem" class="text-sm font-semibold text-red-600 hover:underline">{{ __('Remove snippet') }}</button>
                    @endif
                    <span class="flex gap-2">
                        <button type="button" wire:click="closeItem" class="rounded-lg border border-brand-ink/15 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                        <x-primary-button type="button" wire:click="saveItem" wire:loading.attr="disabled" wire:target="saveItem">{{ __('Save') }}</x-primary-button>
                    </span>
                </div>
            </div>
        @endif
    </x-modal>

    @php
        $hasRepoSnippets = $repoSnippets !== [];
        $repoSnippetCount = count(is_array($repoSnippets['items'] ?? null) ? $repoSnippets['items'] : []);
    @endphp
    <x-edge-yaml-advanced
        :site="$site"
        :file="$sourcePath"
        :has-repo="$hasRepoSnippets"
        :repo-badge="$repoSnippetCount > 0 ? (string) $repoSnippetCount : null"
        :hint="__('Commit at the repo root. Dashboard Save overrides this section.')"
    >
        <x-slot:status>
            @if ($hasRepoSnippets)
                <dl class="grid grid-cols-1 gap-y-1.5 text-xs sm:grid-cols-[8rem_1fr]">
                    <dt class="text-brand-mist">{{ __('Enabled') }}</dt>
                    <dd class="text-brand-moss">{{ ($repoSnippets['enabled'] ?? false) ? __('Yes') : __('No') }}</dd>
                    @if ($repoSnippetCount > 0)
                        <dt class="text-brand-mist">{{ __('Items') }}</dt>
                        <dd class="text-brand-moss">{{ $repoSnippetCount }}</dd>
                    @endif
                </dl>
                <p class="mt-2 text-xs text-brand-mist">{{ __('Dashboard values override the repo when both are set.') }}</p>
            @else
                <p class="text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $sourcePath]) }}</p>
            @endif
        </x-slot:status>
snippets:
  enabled: true
  items:
    - name: Meta
      phase: head
      path: /*
      html: '<meta name="description" content="Acme — ship faster.">'
    - name: Banner
      phase: body
      path: /*
      html: '<div style="background:#111;color:#fff;padding:.5rem;text-align:center">We shipped.</div>'
    </x-edge-yaml-advanced>
    </fieldset>
</div>
