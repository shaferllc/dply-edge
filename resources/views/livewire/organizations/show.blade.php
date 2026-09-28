{{--
  Org overview — "needs attention first" (redesign 2026-09-27, option B).
  $attention / $clear come from Organizations\Show::checks(): each is a real
  query, so an empty attention list means every check passed.
--}}
@php
    $members = $organization->users->count();
    $teams = $organization->teams->count();
    $invites = $organization->invitations->count();
    $tokens = $organization->apiTokens->whereNull('revoked_at')->count();
    $firstName = \Illuminate\Support\Str::of(auth()->user()?->name ?? '')->before(' ')->toString();
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="overview"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'icon' => 'building-office-2'],
            ]"
        >
            <div class="space-y-6">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-brand-ink">
                        {{ $firstName !== '' ? __('Hi, :name', ['name' => $firstName]) : $organization->name }}
                    </h1>
                    <p class="mt-1 text-sm text-brand-moss">
                        @if ($attention === [])
                            {{ __('Nothing in :org needs you right now.', ['org' => $organization->name]) }}
                        @else
                            {{ trans_choice(':n thing in :org needs you.|:n things in :org need you.', count($attention), ['n' => count($attention), 'org' => $organization->name]) }}
                        @endif
                    </p>
                </div>

                @if ($attention !== [])
                    <section class="dply-card overflow-hidden p-0" aria-labelledby="org-attention-heading">
                        <div class="flex items-center justify-between border-b border-brand-ink/10 px-5 py-3.5 sm:px-6">
                            <h2 id="org-attention-heading" class="text-sm font-semibold text-brand-ink">{{ __('Needs attention') }}</h2>
                            <span class="rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-300">{{ __(':n open', ['n' => count($attention)]) }}</span>
                        </div>
                        <ul class="divide-y divide-brand-ink/8">
                            @foreach ($attention as $item)
                                <li class="flex flex-wrap items-center gap-4 px-5 py-4 sm:px-6">
                                    <span @class([
                                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                                        'bg-amber-500/15 text-amber-700 dark:text-amber-300' => $item['tone'] === 'warn',
                                        'bg-brand-sand/50 text-brand-moss' => $item['tone'] !== 'warn',
                                    ])>
                                        <x-heroicon-o-exclamation-circle class="h-5 w-5" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-brand-ink">{{ $item['title'] }}</p>
                                        <p class="mt-0.5 text-sm text-brand-moss">{{ $item['detail'] }}</p>
                                    </div>
                                    <a href="{{ $item['href'] }}" wire:navigate class="inline-flex h-9 items-center rounded-lg bg-brand-ink px-3.5 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest">
                                        {{ $item['action'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <dl class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <a href="{{ route('dashboard') }}" wire:navigate class="dply-card block p-4 transition hover:border-brand-sage/40">
                        <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Apps') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $appCount }}</dd>
                        <dd class="mt-0.5 text-xs text-brand-moss">{{ __('live') }}</dd>
                    </a>
                    @if ($usage !== null)
                        <a href="{{ route('billing.show', $organization) }}" wire:navigate class="dply-card block p-4 transition hover:border-brand-sage/40">
                            <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Usage this period') }}</dt>
                            <dd class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">${{ number_format($usage['cents'] / 100, 2) }}</dd>
                            <dd class="mt-0.5 text-xs text-brand-moss">
                                @if ($usage['credit_cents'] > 0)
                                    {{ __('of $:credit included credit', ['credit' => number_format($usage['credit_cents'] / 100, 2)]) }}
                                @else
                                    {{ $organization->planTierLabel() }}
                                @endif
                            </dd>
                        </a>
                    @endif
                    <a href="{{ route('organizations.members', $organization) }}" wire:navigate class="dply-card block p-4 transition hover:border-brand-sage/40">
                        <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('People') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $members }}</dd>
                        <dd class="mt-0.5 text-xs text-brand-moss">
                            {{ trans_choice(':n team|:n teams', $teams, ['n' => $teams]) }}@if ($invites > 0) · {{ trans_choice(':n invite|:n invites', $invites, ['n' => $invites]) }}@endif
                        </dd>
                    </a>
                    @if ($isAdmin)
                        <a href="{{ route('organizations.settings', $organization) }}#api-tokens" wire:navigate class="dply-card block p-4 transition hover:border-brand-sage/40">
                            <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('API tokens') }}</dt>
                            <dd class="mt-1 text-2xl font-semibold tabular-nums text-brand-ink">{{ $tokens }}</dd>
                            <dd class="mt-0.5 text-xs text-brand-moss">{{ __('active') }}</dd>
                        </a>
                    @endif
                </dl>

                @if ($clear !== [])
                    <section class="dply-card p-5 sm:p-6" aria-labelledby="org-clear-heading">
                        <h2 id="org-clear-heading" class="text-sm font-semibold text-brand-ink">{{ __('All clear') }}</h2>
                        <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($clear as $line)
                                <li class="flex items-center gap-2.5 text-sm">
                                    <x-heroicon-o-check class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                    <span class="text-brand-ink">{{ $line['label'] }}</span>
                                    <span class="ml-auto text-brand-moss">{{ $line['sub'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>
        </x-organization-shell>
    </div>
</div>
