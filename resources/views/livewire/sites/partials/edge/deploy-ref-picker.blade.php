<div
    class="relative mt-3 rounded-xl border border-brand-ink/10 bg-white shadow-lg dark:bg-brand-ink/90"
    wire:click.outside="closeEdgeDeployRefPicker"
>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-4 py-3">
        <div class="inline-flex rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-0.5 text-xs font-semibold">
            @foreach (['commits' => __('Commits'), 'branches' => __('Branches'), 'tags' => __('Tags')] as $tab => $label)
                <button
                    type="button"
                    wire:click="setEdgeDeployRefTab('{{ $tab }}')"
                    @class([
                        'rounded-md px-3 py-1.5 transition-colors',
                        'bg-brand-ink text-brand-cream' => $edge_deploy_ref_tab === $tab,
                        'text-brand-moss hover:text-brand-ink' => $edge_deploy_ref_tab !== $tab,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
        <button type="button" wire:click="closeEdgeDeployRefPicker" class="text-brand-moss hover:text-brand-ink">
            <x-heroicon-o-x-mark class="h-4 w-4" />
            <span class="sr-only">{{ __('Close') }}</span>
        </button>
    </div>

    <div class="border-b border-brand-ink/10 px-4 py-3">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            @if ($edge_deploy_ref_tab === 'commits')
                <div class="sm:w-40">
                    <label for="edge_deploy_ref_branch" class="sr-only">{{ __('Branch') }}</label>
                    <input
                        id="edge_deploy_ref_branch"
                        type="text"
                        wire:model.live.debounce.400ms="edge_deploy_ref_branch"
                        placeholder="{{ __('Branch') }}"
                        class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-1 focus:ring-brand-sage"
                    />
                </div>
            @endif
            <div class="relative flex-1">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-moss" aria-hidden="true" />
                <input
                    type="text"
                    wire:model.live.debounce.300ms="edge_deploy_ref_search"
                    placeholder="{{ __('Search…') }}"
                    class="block w-full rounded-lg border border-brand-ink/15 bg-white py-2 pl-9 pr-3 text-sm leading-5 text-brand-ink placeholder:text-brand-mist focus:border-brand-sage focus:ring-1 focus:ring-brand-sage"
                />
            </div>
        </div>
    </div>

    <div class="max-h-72 overflow-y-auto">
        @if ($edge_deploy_ref_needs_provider)
            @php
                $providerLabel = match ($edge_deploy_ref_needs_provider) {
                    'github' => 'GitHub',
                    'gitlab' => 'GitLab',
                    'bitbucket' => 'Bitbucket',
                    default => ucfirst($edge_deploy_ref_needs_provider),
                };
            @endphp
            <div class="flex flex-col items-start gap-2 px-4 py-5 text-sm text-brand-moss">
                <p>{{ __('You haven\'t linked a :provider account yet — connect one to browse this repo.', ['provider' => $providerLabel]) }}</p>
                <x-connect-provider-link class="!text-sm">
                    {{ __('Connect :provider', ['provider' => $providerLabel]) }} &rarr;
                </x-connect-provider-link>
            </div>
        @elseif ($edge_deploy_ref_error)
            <p class="px-4 py-6 text-sm text-rose-700 dark:text-rose-300">{{ $edge_deploy_ref_error }}</p>
        @elseif ($edge_deploy_ref_results === [])
            <p class="px-4 py-6 text-sm text-brand-moss">{{ __('No matching refs found.') }}</p>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($edge_deploy_ref_results as $ref)
                    <li wire:key="edge-ref-{{ $edge_deploy_ref_tab }}-{{ $ref['sha'] }}-{{ $ref['label'] }}">
                        <button
                            type="button"
                            wire:click="selectEdgeDeployRef('{{ $ref['sha'] }}')"
                            class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-brand-sand/30 dark:hover:bg-brand-ink/50"
                        >
                            <span @class([
                                'mt-0.5 inline-flex shrink-0 rounded px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide',
                                'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300' => ($ref['kind'] ?? '') === 'commit',
                                'bg-violet-100 text-violet-800 dark:bg-violet-950/40 dark:text-violet-300' => ($ref['kind'] ?? '') === 'branch',
                                'bg-amber-100 text-amber-900 dark:bg-raw-amber-950/40 dark:text-amber-300' => ($ref['kind'] ?? '') === 'tag',
                            ])>
                                {{ match ($ref['kind'] ?? '') {
                                    'branch' => __('Branch'),
                                    'tag' => __('Tag'),
                                    default => __('Commit'),
                                } }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-mono text-sm text-brand-ink">{{ $ref['label'] }}</span>
                                @if (! empty($ref['meta']))
                                    <span class="mt-0.5 block text-xs text-brand-moss">{{ $ref['meta'] }}</span>
                                @endif
                                <span class="mt-1 block font-mono text-xs text-brand-mist">{{ \Illuminate\Support\Str::limit((string) ($ref['sha'] ?? ''), 12, '') }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
