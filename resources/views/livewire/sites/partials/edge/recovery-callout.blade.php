@php
    // Surface common-failure remediations on the Edge workspace when
    // the latest deploy failed. Pattern-matches the failure_reason
    // string against known signatures and lists the likely fixes.
    // Renders nothing when the site is healthy or the reason doesn't
    // match anything we know how to fix.
    $reason = (string) ($edgeMeta['last_error'] ?? ($edgeLatestDeployment?->failure_reason ?? ''));
    if ($site->status !== \App\Models\Site::STATUS_EDGE_FAILED || $reason === '') {
        $suggestions = [];
    } else {
        $r = mb_strtolower($reason);
        $rules = [
            'ERR_PNPM_LOCKFILE_BREAKING_CHANGE' => [
                'title' => __('Outdated pnpm lockfile'),
                'fixes' => [
                    __('We auto-retried with --no-frozen-lockfile, but the original lockfile is from a newer pnpm than the build uses. Easiest fix: pin packageManager in your package.json (e.g. "packageManager": "pnpm@11.4.0") so the build uses the same version that generated the lockfile.'),
                    __('Or delete pnpm-lock.yaml and let the build regenerate it (loses pinned versions).'),
                ],
            ],
            'ERR_PNPM_NO_SCRIPT' => [
                'title' => __('Missing build script'),
                'fixes' => [
                    __('Your package.json doesn\'t declare a "build" script. Either add one (e.g. "build": "vite build"), or change the Build command in this site\'s settings to match what your repo actually runs.'),
                ],
            ],
            'ERR_PNPM_IGNORED_BUILDS' => [
                'title' => __('Build scripts blocked by pnpm'),
                'fixes' => [
                    __('We now pass --config.dangerouslyAllowAllBuilds=true to bypass pnpm 11\'s strict allowlist. If you\'re seeing this, the build runner may be on stale code — restart Horizon and retry.'),
                ],
            ],
            'ERR_PNPM_CONFIG_CONFLICT_BUILT_DEPENDENCIES' => [
                'title' => __('Conflicting pnpm config'),
                'fixes' => [
                    __('A transitive dep declares both onlyBuiltDependencies and neverBuiltDependencies. Pin packageManager in your package.json so corepack uses a pnpm version that handles this gracefully.'),
                ],
            ],
            'astro: not found|vite: not found|next: not found|nuxt: not found' => [
                'title' => __('Build binary not found'),
                'fixes' => [
                    __('Looks like the build script ran via npm but your repo uses pnpm/yarn. We now auto-rewrite "npm run X" → "pnpm run X" when a pnpm-lock.yaml is present — make sure the build runner is on the latest code (restart Horizon).'),
                ],
            ],
            'recv failure|operation timed out|connection reset' => [
                'title' => __('Git clone network blip'),
                'fixes' => [
                    __('GitHub timed out during clone. The repo mirror cache should make subsequent attempts faster. Hit Redeploy.'),
                ],
            ],
            'maximum execution time|enomem|out of memory|killed' => [
                'title' => __('Build ran out of resources'),
                'fixes' => [
                    __('The build container hit its time or memory cap. Try trimming dev dependencies in package.json, or split a workspace monorepo into one Edge site per package.'),
                ],
            ],
            'unable to access|fatal: repository.+not found|authentication failed' => [
                'title' => __('Repository access denied'),
                'fixes' => [
                    __('Either the repo is private and we don\'t have credentials, or the URL is wrong. Reconnect your GitHub account from the org Credentials page so dply can authenticate.'),
                ],
            ],
        ];
        $suggestions = [];
        foreach ($rules as $pattern => $rule) {
            if (preg_match('/'.$pattern.'/i', $r) === 1) {
                $suggestions[] = $rule;
            }
        }
    }
@endphp

@if (! empty($suggestions))
    <div class="rounded-2xl border border-rose-200 bg-rose-50/60 p-5 dark:border-raw-rose-900/40 dark:bg-rose-950/20">
        <div class="flex items-start gap-3">
            <x-heroicon-o-lifebuoy class="mt-0.5 h-5 w-5 shrink-0 text-rose-700" aria-hidden="true" />
            <div class="min-w-0 flex-1 space-y-3">
                <div>
                    <p class="text-sm font-semibold text-rose-900">{{ __('Suggested fixes') }}</p>
                    <p class="mt-0.5 text-xs text-rose-800/80">{{ __('Based on the failure reason, these are the most likely fixes.') }}</p>
                </div>
                @foreach ($suggestions as $s)
                    <div class="rounded-xl border border-rose-200/80 bg-white/80 px-4 py-3">
                        <p class="text-xs font-semibold text-rose-900">{{ $s['title'] }}</p>
                        <ul class="mt-1.5 list-disc space-y-1 pl-5 text-xs leading-5 text-rose-900/90">
                            @foreach ($s['fixes'] as $fix)
                                <li>{{ $fix }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
                @can('update', $site)
                    <div class="flex flex-wrap items-center gap-2 pt-1">
                        <button
                            type="button"
                            wire:click="redeployEdge"
                            wire:loading.attr="disabled"
                            wire:target="redeployEdge"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:opacity-60"
                        >
                            <x-heroicon-o-arrow-path class="h-4 w-4" />
                            {{ __('Retry deploy') }}
                        </button>
                        <a
                            href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-build']) }}"
                            wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-50"
                        >
                            <x-heroicon-o-wrench-screwdriver class="h-4 w-4" />
                            {{ __('Edit build settings') }}
                        </a>
                    </div>
                @endcan
            </div>
        </div>
    </div>
@endif
