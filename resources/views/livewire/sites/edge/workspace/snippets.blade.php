<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-snippets',
            'what' => __('Snippets inject small HTML into matching pages at the Edge — banners, pixels, or support widgets — without rebuilding or redeploying your app.'),
            'steps' => [
                __('Add a snippet: name it, choose head or body injection, and set a path pattern (/* for all pages).'),
                __('Paste the HTML (script tags, meta, markup). Keep it small and trusted.'),
                __('Enable and Save. Delivery republishes; visitors see the inject on the next request.'),
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

        <div class="mt-4 space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span class="text-sm font-medium text-brand-ink">{{ __('Enable snippets') }}</span>
            </label>

            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-3 dark:bg-brand-sand/10 sm:px-4">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Examples') }}</p>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Click to add starter HTML, then replace placeholders before Save. Prefer Tags for plain remote script URLs.') }}</p>
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

            @foreach ($items as $i => $item)
                <div class="space-y-3 rounded-xl border border-brand-ink/10 p-3" wire:key="snip-{{ $i }}">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <x-input-label :value="__('Name')" />
                            <x-text-input wire:model="items.{{ $i }}.name" type="text" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                        </div>
                        <div>
                            <x-input-label :value="__('Inject')" />
                            <select wire:model="items.{{ $i }}.phase" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900" @disabled(! $managedDelivery)>
                                <option value="head">{{ __('Before </head>') }}</option>
                                <option value="body">{{ __('Before </body>') }}</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label :value="__('Path')" />
                            <x-text-input wire:model="items.{{ $i }}.path" type="text" class="mt-1 block w-full font-mono text-sm" @disabled(! $managedDelivery) />
                        </div>
                    </div>
                    <div>
                        <x-input-label :value="__('HTML')" />
                        <textarea wire:model="items.{{ $i }}.html" rows="4" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs dark:bg-zinc-900" @disabled(! $managedDelivery)></textarea>
                    </div>
                    @if (count($items) > 1)
                        <button type="button" wire:click="removeItem({{ $i }})" class="text-xs font-semibold text-red-600">{{ __('Remove') }}</button>
                    @endif
                </div>
            @endforeach

            <div class="flex flex-wrap items-center justify-between gap-3">
                <button type="button" wire:click="addItem" class="text-sm font-semibold text-brand-sage" @disabled(! $managedDelivery)>{{ __('Add snippet') }}</button>
                <x-primary-button type="button" wire:click="save" @disabled(! $managedDelivery)>{{ __('Save') }}</x-primary-button>
            </div>
        </div>
    </section>

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
</div>
