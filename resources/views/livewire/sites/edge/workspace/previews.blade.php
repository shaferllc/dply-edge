@php
    $hasRepoPolicy = $repoPreviews !== [] || isset($repoCommentWidget['enabled']);
@endphp

<div>
    @unless ($edgeIsPreviewChild)
        <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            @include('livewire.sites.edge.workspace.partials.feature-guide', [
                'docSlug' => 'edge-previews',
                'what' => __('Spin up PR and ad-hoc preview URLs for this production site — review, promote to prod, or split traffic without changing main.'),
                'steps' => [
                    __('Create an ad-hoc preview from a commit above, or open a PR so the GitHub webhook builds one.'),
                    __('Open the preview URL when the build finishes (Fake Edge needs a local *.test hostname).'),
                    __('Promote to prod, split traffic, set protection, or tear down when review is done.'),
                ],
                'setupLinks' => [
                    [
                        'label' => __('Deploy triggers / webhook'),
                        'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy-triggers']),
                    ],
                ],
                'tips' => [
                    __('Same commit SHA reuses the preview; a new SHA gets its own URL.'),
                    __('Protection locks preview URLs and the live site. The comment widget stays on preview URLs only.'),
                ],
            ])
        </section>

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/15 px-5 py-3 sm:px-6">
            <div class="min-w-0 text-sm">
                <span class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Auto-deploy') }}</span>
                <p class="mt-0.5 text-brand-ink">
                    @if ($previewPolicy['enabled'])
                        <span class="font-semibold text-emerald-800 dark:text-emerald-300">{{ __('On') }}</span>
                        <span class="text-brand-moss">
                            · {{ $previewPolicy['pr_only'] ? __('PRs only') : __('PRs + branches') }}
                        </span>
                    @else
                        <span class="font-semibold text-rose-800 dark:text-rose-300">{{ __('Off') }}</span>
                    @endif
                    @if (($previewPolicy['exclude_branches'] ?? []) !== [])
                        <span class="text-brand-moss"> · {{ __('excluding :list', ['list' => implode(', ', $previewPolicy['exclude_branches'])]) }}</span>
                    @endif
                </p>
            </div>
            <a
                href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
            >
                {{ __('Edit in :file', ['file' => $sourcePath]) }}
            </a>
        </div>
    @endunless

    @include('livewire.sites.partials.edge.previews', [
        'latestReplays' => $latestReplays ?? collect(),
        'deployReplayEnabled' => $deployReplayEnabled ?? false,
        'deployContractEnabled' => $deployContractEnabled ?? false,
        'deployContracts' => $deployContracts ?? collect(),
    ])

    @unless ($edgeIsPreviewChild)
        @include('livewire.sites.partials.edge.preview-settings')

        <details class="group border-b border-brand-ink/10" @if ($hasRepoPolicy) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
                <span>{{ __('From :file', ['file' => $sourcePath]) }}</span>
                <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
            </summary>

            <div class="space-y-4 border-t border-brand-ink/10 px-5 py-4 sm:px-6">
                @if ($repoPreviews !== [])
                    <dl class="grid grid-cols-1 gap-y-2 text-xs sm:grid-cols-[8rem_1fr]">
                        @if (isset($repoPreviews['enabled']))
                            <dt class="text-brand-mist">{{ __('Enabled') }}</dt>
                            <dd class="text-brand-ink">{{ $repoPreviews['enabled'] ? __('yes') : __('no') }}</dd>
                        @endif
                        @if (isset($repoPreviews['pr_only']))
                            <dt class="text-brand-mist">{{ __('PR-only') }}</dt>
                            <dd class="text-brand-ink">{{ $repoPreviews['pr_only'] ? __('yes') : __('no') }}</dd>
                        @endif
                        @if (! empty($repoPreviews['branches']))
                            <dt class="text-brand-mist">{{ __('Branches') }}</dt>
                            <dd class="font-mono text-brand-ink">{{ implode(', ', $repoPreviews['branches']) }}</dd>
                        @endif
                        @if (! empty($repoPreviews['exclude_branches']))
                            <dt class="text-brand-mist">{{ __('Excluded') }}</dt>
                            <dd class="font-mono text-brand-ink">{{ implode(', ', $repoPreviews['exclude_branches']) }}</dd>
                        @endif
                        @if (! empty($repoPreviews['protection']['mode']))
                            <dt class="text-brand-mist">{{ __('Protection') }}</dt>
                            <dd class="font-mono text-brand-ink">{{ $repoPreviews['protection']['mode'] }}</dd>
                        @endif
                    </dl>
                @else
                    <p class="text-sm text-brand-moss">
                        {{ __('No `previews:` block yet — every PR gets a preview by default.') }}
                    </p>
                @endif

                @if (isset($repoCommentWidget['enabled']))
                    <p class="text-xs text-brand-moss">
                        {{ __('Comment widget') }}:
                        <span class="font-semibold text-brand-ink">{{ $repoCommentWidget['enabled'] ? __('enabled') : __('disabled') }}</span>
                        {{ __('in repo') }}
                    </p>
                @endif
            </div>
        </details>
    @endunless

    @include('livewire.partials.confirm-action-modal')
</div>
