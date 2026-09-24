{{-- Overview is status, the live URL, and the last few deploys. Section pages live in the sidebar. --}}
@php
    $recentDeployments = $edgeDeployments->take(3);
@endphp

<div @if ($isInProgress ?? false) wire:poll.2s @endif>
    @if (! empty($edgeDeliveryBanner))
        <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
            @include('livewire.sites.partials.edge.delivery-banner')
        </div>
    @endif

    @include('livewire.sites.partials.edge.hero')

    @if (($deploymentJourney ?? null) !== null && ($inProgressDeployment ?? null) !== null)
        <div class="border-b border-brand-ink/10">
            @include('livewire.sites.partials.edge.deployment-journey-card', [
                'journey' => $deploymentJourney,
                'deployment' => $inProgressDeployment,
            ])
        </div>
    @endif

    @if ($recentDeployments->isNotEmpty())
        <section>
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Recent deploys') }}</p>
                <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploys']) }}" wire:navigate class="text-xs font-semibold text-brand-sage hover:underline">
                    {{ __('View all') }}
                </a>
            </div>
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($recentDeployments as $deployment)
                    @php
                        $sha = $deployment->commit_sha ? substr((string) $deployment->commit_sha, 0, 7) : null;
                        $when = optional($deployment->published_at ?? $deployment->created_at)->diffForHumans();
                    @endphp
                    <li>
                        <a
                            href="{{ route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment]) }}"
                            wire:navigate
                            class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm hover:bg-brand-sand/20 sm:px-6"
                        >
                            <span class="inline-flex min-w-0 items-center gap-2">
                                <span class="rounded-full bg-brand-sand/50 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                                    {{ str_replace('_', ' ', (string) $deployment->status) }}
                                </span>
                                @if ($sha)
                                    <span class="font-mono text-xs text-brand-ink">{{ $sha }}</span>
                                @endif
                                @if (filled($deployment->branch))
                                    <span class="truncate text-xs text-brand-moss">{{ $deployment->branch }}</span>
                                @endif
                            </span>
                            <span class="text-xs text-brand-mist">{{ $when }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
