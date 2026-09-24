@php
    use App\Models\EdgeDeployment;

    $edgeBuildRepoConfig = null;
    if ($site->relationLoaded('edgeDeployments') && $site->edgeDeployments !== null) {
        $deploymentsWithConfig = $site->edgeDeployments->filter(
            fn (EdgeDeployment $deployment): bool => is_array($deployment->repo_config) && $deployment->repo_config !== [],
        );
        $edgeBuildRepoConfig = $deploymentsWithConfig
            ->first(fn (EdgeDeployment $deployment): bool => $deployment->status === EdgeDeployment::STATUS_LIVE)
            ?->repo_config
            ?? $deploymentsWithConfig->first()?->repo_config;
    }

    $latestRepoConfig = $edgeBuildRepoConfig;
    $sourceLine = trim(($edgeRepo ?: '—').($edgeBranch !== '' ? '@'.$edgeBranch : ''));
    // Keep Advanced collapsed by default — primary path is command + output.
    $showAdvancedOpen = ! empty($latestRepoConfig['warnings'] ?? null);
@endphp

{{-- Primary: command + output. Everything else under Advanced. --}}
<section class="border-b border-brand-ink/10">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-sand/15 px-5 py-3 sm:px-6">
        <div class="min-w-0">
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Source') }}</p>
            <p class="mt-0.5 truncate font-mono text-xs text-brand-ink">
                @if ($edgeGithubRepoUrl)
                    <a href="{{ $edgeGithubRepoUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ $sourceLine }}</a>
                @else
                    {{ $sourceLine }}
                @endif
            </p>
        </div>
        <p class="text-xs text-brand-moss">{{ __('Repo & branch are fixed in v1.') }}</p>
    </div>

    @can('update', $site)
        <form wire:submit.prevent="saveEdgeBuildSettings" class="space-y-4 px-5 py-5 sm:px-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block sm:col-span-2">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Build command') }}</span>
                    <input
                        type="text"
                        wire:model="buildForm.edge_build_command"
                        autocomplete="off"
                        spellcheck="false"
                        class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                    @error('buildForm.edge_build_command')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Output directory') }}</span>
                    <input
                        type="text"
                        wire:model="buildForm.edge_output_dir"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="dist"
                        class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                    @error('buildForm.edge_output_dir')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </label>
                <label class="flex items-center gap-3 self-end pb-2 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="buildForm.edge_deploy_on_push" class="rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
                    <span>
                        <span class="font-medium">{{ __('Deploy on push') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ __('to :branch', ['branch' => $edgeBranch]) }}</span>
                    </span>
                </label>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdgeBuildSettings"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-spinner variant="white" size="sm" wire:loading wire:target="saveEdgeBuildSettings" />
                    <span wire:loading.remove wire:target="saveEdgeBuildSettings">{{ __('Save') }}</span>
                    <span wire:loading wire:target="saveEdgeBuildSettings">{{ __('Saving…') }}</span>
                </button>
                <p class="text-xs text-brand-moss">{{ __('Redeploy after saving to apply.') }}</p>
            </div>
        </form>
    @else
        <dl class="divide-y divide-brand-ink/8 px-5 py-2 text-sm sm:px-6">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Build command') }}</dt>
                <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink break-all">{{ $edgeBuildCommand }}</dd>
            </div>
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Output directory') }}</dt>
                <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">{{ $edgeOutputDir }}</dd>
            </div>
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Deploy on push') }}</dt>
                <dd class="min-w-0 flex-1 text-brand-ink">{{ $edgeDeployOnPush ? __('Yes') : __('No') }}</dd>
            </div>
        </dl>
    @endcan
</section>

<details
    id="edge-build-advanced"
    class="group border-b border-brand-ink/10"
    @if ($showAdvancedOpen) open @endif
>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
        <span>{{ __('Advanced') }}</span>
        <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
    </summary>

    <div class="border-t border-brand-ink/10">
        @can('update', $site)
            <form wire:submit.prevent="saveEdgeBuildSettings" class="space-y-4 px-5 py-5 sm:px-6">
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Repository root') }}</span>
                    <input
                        type="text"
                        wire:model="buildForm.edge_repo_root"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="apps/web"
                        class="mt-1.5 w-full max-w-md rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Optional monorepo subdirectory for builds and path-scoped auto-deploy.') }}</p>
                    @error('buildForm.edge_repo_root')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </label>
                <label class="flex items-start gap-3 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="buildForm.edge_spa_fallback" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
                    <span>
                        <span class="font-medium">{{ __('SPA fallback') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Unknown paths serve index.html after a 404.') }}</span>
                    </span>
                </label>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdgeBuildSettings"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-spinner variant="ink" size="sm" wire:loading wire:target="saveEdgeBuildSettings" />
                    <span wire:loading.remove wire:target="saveEdgeBuildSettings">{{ __('Save advanced') }}</span>
                    <span wire:loading wire:target="saveEdgeBuildSettings">{{ __('Saving…') }}</span>
                </button>
            </form>
        @else
            <dl class="divide-y divide-brand-ink/8 px-5 py-2 text-sm sm:px-6">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                    <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('Repository root') }}</dt>
                    <dd class="min-w-0 flex-1 font-mono text-xs text-brand-ink">{{ ($edgeRepoRoot ?? $site->edgeRepoRoot()) ?: '—' }}</dd>
                </div>
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-3">
                    <dt class="w-36 shrink-0 text-xs uppercase tracking-wide text-brand-mist">{{ __('SPA fallback') }}</dt>
                    <dd class="min-w-0 flex-1 text-brand-ink">{{ $edgeSpaFallback ? __('Enabled') : __('Disabled') }}</dd>
                </div>
            </dl>
        @endcan

        @can('update', $site)
            <form wire:submit.prevent="saveEdgeDeployFooter" class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 px-5 py-4 sm:px-6">
                <label class="flex min-w-0 items-start gap-3 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="buildForm.edge_deploy_footer_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
                    <span>
                        <span class="font-medium">{{ __('Show deploy id in the site footer') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Prints the live deployment id at the bottom of HTML pages.') }}</span>
                    </span>
                </label>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdgeDeployFooter"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="saveEdgeDeployFooter">{{ __('Save') }}</span>
                    <span wire:loading wire:target="saveEdgeDeployFooter">{{ __('Saving…') }}</span>
                </button>
            </form>
        @endcan

        <div class="border-t border-brand-ink/10 px-5 py-5 sm:px-6">
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Retention') }}</p>
            <p class="mt-1 text-xs text-brand-moss">{{ __('Older deploy artifacts beyond this count are deleted from storage.') }}</p>
            @can('update', $site)
                <form wire:submit.prevent="saveEdgeReleasesToKeep" class="mt-3 flex flex-wrap items-end gap-3">
                    <label class="block">
                        <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Releases to keep') }}</span>
                        <input
                            type="number"
                            min="1"
                            max="50"
                            wire:model="buildForm.edge_releases_to_keep"
                            class="mt-1.5 w-24 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900"
                        />
                    </label>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="saveEdgeReleasesToKeep"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                    >
                        <x-spinner variant="ink" size="sm" wire:loading wire:target="saveEdgeReleasesToKeep" />
                        <span wire:loading.remove wire:target="saveEdgeReleasesToKeep">{{ __('Save') }}</span>
                        <span wire:loading wire:target="saveEdgeReleasesToKeep">{{ __('Saving…') }}</span>
                    </button>
                </form>
            @else
                <p class="mt-2 text-sm text-brand-ink">{{ __('Releases to keep: :count', ['count' => $buildForm->edge_releases_to_keep]) }}</p>
            @endcan
        </div>

        @if ($latestRepoConfig !== null)
            <div id="edge-build-repo-config" class="scroll-mt-24 border-t border-brand-ink/10 px-5 py-5 sm:px-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Repo config') }}</p>
                        <p class="mt-1 text-sm text-brand-ink">
                            {{ __('Managed by :file', ['file' => $latestRepoConfig['source_path'] ?? 'dply.yaml']) }}
                        </p>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            {{ __('Overrides dashboard build settings on each deploy. Routing rules live under Routing.') }}
                        </p>
                    </div>
                    <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                        {{ empty($latestRepoConfig['build']) ? __('No build keys') : count($latestRepoConfig['build']).' '.__('build keys') }}
                    </span>
                </div>
                @if (! empty($latestRepoConfig['warnings']))
                    <div class="mt-3 rounded-lg border border-amber-300/60 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-raw-amber-950/40 dark:text-raw-amber-200">
                        <p class="font-semibold uppercase tracking-wide">{{ __('Parse warnings') }}</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-4">
                            @foreach ((array) $latestRepoConfig['warnings'] as $warning)
                                <li class="font-mono">{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </div>
</details>
