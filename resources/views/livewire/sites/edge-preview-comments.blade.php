<div class="max-w-5xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :site="$site" :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Edge'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
        ['label' => $site->name, 'href' => route('sites.show', ['server' => $server, 'site' => $site]), 'icon' => 'globe-alt'],
        ['label' => __('Review hub'), 'icon' => 'chat-bubble-left-right'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Preview review hub')"
        :description="__('PR-linked design review — threaded comments, approvals, and promote when ready.')"
        icon="heroicon-o-chat-bubble-left-right"
    >
        <x-slot:actions>
            @if (! empty($review['pr_url']))
                <a href="{{ $review['pr_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                    <x-heroicon-o-code-bracket-square class="h-4 w-4" aria-hidden="true" />
                    {{ __('PR #:n', ['n' => $review['pr_number']]) }}
                </a>
            @endif
            @if ($site->edgeLiveUrl())
                <a href="{{ $site->edgeLiveUrl() }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-forest shadow-sm transition-colors hover:bg-brand-sand/40">
                    <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" aria-hidden="true" />
                    {{ __('Open preview') }}
                </a>
            @endif
        </x-slot:actions>
    </x-profile-shell>

    @php
        $reviewReady = ! empty($review['ready_to_promote']);
        $contractReady = empty($contract['enabled']) || ! empty($contract['ready_to_promote']);
        $ready = $reviewReady && $contractReady;
        $statusTone = $ready ? 'border-brand-sage/30 bg-brand-sage/8' : 'border-amber-200 bg-amber-50/60';
    @endphp

    <section class="mt-6 rounded-xl border px-4 py-4 sm:px-5 {{ $statusTone }}">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Review status') }}</p>
                <p class="mt-1 text-sm font-semibold text-brand-ink">
                    {{ $ready ? __('Ready to promote') : __('Review in progress') }}
                </p>
                <dl class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-brand-moss">
                    @if (! empty($review['branch']))
                        <div><span class="font-semibold text-brand-ink">{{ __('Branch') }}:</span> <span class="font-mono">{{ $review['branch'] }}</span></div>
                    @endif
                    @if (! empty($review['head_sha_short']))
                        <div><span class="font-semibold text-brand-ink">{{ __('Commit') }}:</span> <span class="font-mono">{{ $review['head_sha_short'] }}</span></div>
                    @endif
                    <div><span class="font-semibold text-brand-ink">{{ __('Open') }}:</span> {{ (int) ($review['open_count'] ?? 0) }}</div>
                    <div><span class="font-semibold text-brand-ink">{{ __('Resolved') }}:</span> {{ (int) ($review['resolved_count'] ?? 0) }}</div>
                    <div><span class="font-semibold text-brand-ink">{{ __('Approvals') }}:</span> {{ (int) ($review['approval_count'] ?? 0) }}/{{ (int) ($review['min_approvals'] ?? 1) }}</div>
                </dl>
            </div>
            @can('update', $site)
                @if ($ready && $parentSite)
                    <button
                        type="button"
                        wire:click="confirmPromoteToProduction"
                        wire:loading.attr="disabled"
                        wire:target="confirmPromoteToProduction,promoteToProduction"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-forest/90 disabled:opacity-60"
                    >
                        <x-heroicon-o-arrow-up-tray class="h-4 w-4" aria-hidden="true" />
                        {{ __('Promote to production') }}
                    </button>
                @endif
            @endcan
        </div>
    </section>

    @if ($deployContractEnabled && $parentSite)
        <section class="mt-6">
            @include('livewire.sites.partials.edge.deploy-contract-panel', [
                'preview' => $site,
                'previewIsLive' => true,
                'deployContractEnabled' => true,
                'deployContract' => $contract,
                'runContractMethod' => 'runDeployContractFromReviewHub',
                'waiveContractMethod' => 'confirmWaiveDeployContractFromReviewHub',
            ])
        </section>
    @endif

    @can('update', $site)
        <section class="dply-card mt-6 overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Approvals') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Approvals') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Sign off when the preview looks good. Promote requires resolved threads when that gate is enabled.') }}</p>
                </div>
            </div>
            <div class="space-y-4 px-6 py-5">
                @if ($review['approvals'] === [])
                    <x-empty-state
                        borderless
                        compact
                        icon="heroicon-o-check-badge"
                        :title="__('No approvals yet')"
                        :description="__('Reviewers who approve this preview will be listed here.')"
                    />
                @else
                    <ul class="space-y-2 text-sm">
                        @foreach ($review['approvals'] as $approval)
                            <li class="flex items-start justify-between gap-3 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2">
                                <div>
                                    <span class="font-semibold text-brand-ink">{{ $approval['user_name'] }}</span>
                                    @if (! empty($approval['note']))
                                        <p class="mt-0.5 text-brand-moss">{{ $approval['note'] }}</p>
                                    @endif
                                </div>
                                <span class="shrink-0 text-xs text-brand-mist">{{ \Illuminate\Support\Carbon::parse($approval['created_at'])->diffForHumans() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($userHasApproved)
                    <button
                        type="button"
                        wire:click="revokeApproval"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-moss hover:bg-brand-sand/40"
                    >
                        {{ __('Revoke my approval') }}
                    </button>
                @else
                    <form wire:submit.prevent="approveReview" class="flex flex-wrap items-end gap-3">
                        <label class="min-w-[12rem] flex-1">
                            <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Optional note') }}</span>
                            <input type="text" wire:model="approvalNote" maxlength="500" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage" placeholder="{{ __('Looks good to ship') }}" />
                        </label>
                        <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-sage px-4 py-2 text-xs font-semibold text-brand-forest shadow-sm hover:bg-brand-sage/90">
                            <x-heroicon-o-hand-thumb-up class="h-4 w-4" aria-hidden="true" />
                            {{ __('Approve preview') }}
                        </button>
                    </form>
                @endif
            </div>
        </section>

        <section class="dply-card mt-6 overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-chat-bubble-left-right class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('New thread') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Start a thread') }}</h3>
                </div>
            </div>
            <form wire:submit.prevent="addComment" class="space-y-4 px-6 py-5">
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Path') }}</span>
                    <input type="text" wire:model="newCommentUrlPath" autocomplete="off" spellcheck="false" placeholder="/" class="mt-1.5 w-full max-w-sm rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage" />
                    @error('newCommentUrlPath')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Feedback') }}</span>
                    <textarea wire:model="newCommentBody" rows="3" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage"></textarea>
                    @error('newCommentBody')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                </label>
                <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90">
                    {{ __('Add thread') }}
                </button>
            </form>
        </section>
    @endcan

    <section class="dply-card mt-6 overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-chat-bubble-left-right class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Threads') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Review threads') }} <span class="ml-2 text-xs font-normal text-brand-moss">{{ $threads->count() }}</span></h3>
            </div>
        </div>
        @if ($threads->isEmpty())
            <div class="px-6 py-12 text-center">
                <x-heroicon-o-chat-bubble-bottom-center-text class="mx-auto h-8 w-8 text-brand-moss/50" />
                <x-empty-state
                    borderless
                    icon="heroicon-o-chat-bubble-left-right"
                    :title="__('No review threads yet')"
                    :description="__('Comments left on the preview URL show up here, grouped by thread.')"
                />
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($threads as $thread)
                    @include('livewire.sites.partials.preview-review-thread', ['comment' => $thread, 'depth' => 0])
                    @foreach ($thread->replies as $reply)
                        @include('livewire.sites.partials.preview-review-thread', ['comment' => $reply, 'depth' => 1])
                    @endforeach
                    @if ($replyToCommentId === (string) $thread->id)
                        <li class="bg-brand-sand/10 px-6 py-4 pl-12">
                            <form wire:submit.prevent="submitReply" class="space-y-3">
                                <textarea wire:model="replyBody" rows="2" class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage" placeholder="{{ __('Write a reply…') }}"></textarea>
                                @error('replyBody')<p class="text-xs text-rose-700">{{ $message }}</p>@enderror
                                <div class="flex gap-2">
                                    <button type="submit" class="rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white">{{ __('Reply') }}</button>
                                    <button type="button" wire:click="cancelReply" class="rounded-lg border border-brand-ink/15 px-3 py-1.5 text-xs font-medium text-brand-moss">{{ __('Cancel') }}</button>
                                </div>
                            </form>
                        </li>
                    @endif
                @endforeach
            </ul>
        @endif
    </section>

    @include('livewire.partials.confirm-action-modal')
</div>
