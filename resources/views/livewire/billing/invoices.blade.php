@php
    $total = $rows->total();
    $currentPage = $rows->currentPage();
    $lastPage = max(1, $rows->lastPage());
    $perPage = (int) ($perPage ?? $rows->perPage());

    $columnLabels = [
        'number' => __('Number'),
        'description' => __('Description'),
        'status' => __('Status'),
        'total' => __('Total'),
        'date' => __('Date'),
        'actions' => __('Actions'),
    ];
    $visibleColumns = count(array_filter($columns));

    // Sortable headers previously all showed a static ⇅, so the table never said
    // which column it was actually sorted by. Cheap to fix while tightening.
    $sortIcon = fn (string $col): ?string => $sortColumn !== $col
        ? null
        : ($sortDirection === 'asc' ? 'heroicon-m-bars-arrow-up' : 'heroicon-m-bars-arrow-down');
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="invoices"
            :title="__('Invoices')"
            :description="__('Stripe invoices for :org.', ['org' => $organization->name])"
            icon="heroicon-o-document-text"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Invoices'), 'icon' => 'document-text'],
            ]"
        >
            <x-slot:actions>
                <a
                    href="{{ route('billing.show', $organization) }}"
                    wire:navigate
                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-credit-card class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Billing') }}
                </a>
                <a
                    href="{{ route('billing.analytics', $organization) }}"
                    wire:navigate
                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-chart-bar class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Analytics') }}
                </a>
            </x-slot:actions>

            {{-- Hairline strip rather than three fleet-stat cards: the same three
                 numbers in roughly a fifth of the vertical space, matching the
                 notification-channels glance row. --}}
            <x-slot:stats>
                <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('Invoices at a glance') }}">
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Stripe') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="inline-block h-1.5 w-1.5 shrink-0 self-center rounded-full {{ $hasStripeCustomer ? 'bg-brand-sage' : 'bg-brand-ink/20' }}" aria-hidden="true"></span>
                            <span class="truncate text-sm font-semibold text-brand-ink">{{ $hasStripeCustomer ? __('Linked') : __('No customer') }}</span>
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Invoices') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $total }}</span>
                            <span class="truncate text-xs text-brand-moss">{{ $search !== '' ? __('filtered') : __('from Stripe') }}</span>
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Page') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $currentPage }}</span>
                            <span class="truncate text-xs text-brand-moss">{{ __('of :n · :p per page', ['n' => $lastPage, 'p' => $perPage]) }}</span>
                        </dd>
                    </div>
                </dl>
            </x-slot:stats>

            @if ($errors->isNotEmpty())
                <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                    <x-livewire-validation-errors />
                </div>
            @endif

            @unless ($hasStripeCustomer)
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-amber-50 px-3 py-2 text-xs text-amber-900 sm:px-4">
                    <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span>{{ __('No Stripe customer is linked to this organization yet.') }}</span>
                    <a href="{{ route('billing.show', $organization) }}" wire:navigate class="font-semibold underline underline-offset-2 hover:no-underline">
                        {{ __('Go to billing') }} →
                    </a>
                </div>
            @endunless

            @if ($hasStripeCustomer)
                <section class="border-b border-brand-ink/10 last:border-b-0" x-data="{ showColumns: false }">
                    {{-- Toolbar only. The old section header repeated what the
                         shell title and description already say, and cost ~90px
                         of chrome to do it. --}}
                    <div class="flex flex-col gap-2 border-b border-brand-ink/10 bg-brand-sand/25 px-3 py-2 sm:flex-row sm:items-center sm:justify-between sm:px-4">
                        <div class="relative shrink-0">
                            <button
                                type="button"
                                @click="showColumns = !showColumns"
                                @click.outside="showColumns = false"
                                class="inline-flex h-7 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-view-columns class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                                {{ __('Columns') }}
                                <span class="font-mono tabular-nums text-brand-mist">{{ $visibleColumns }}/{{ count($columnLabels) }}</span>
                                <x-heroicon-m-chevron-down class="h-3 w-3 shrink-0 opacity-70" aria-hidden="true" />
                            </button>
                            <div
                                x-show="showColumns"
                                x-cloak
                                x-transition
                                class="absolute left-0 z-20 mt-1 w-48 rounded-lg border border-brand-ink/10 bg-white py-1.5 shadow-lg"
                            >
                                <p class="px-2.5 pb-1 text-2xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Visible columns') }}</p>
                                @foreach ($columnLabels as $key => $label)
                                    <label class="flex cursor-pointer items-center gap-2 px-2.5 py-1 text-xs text-brand-ink hover:bg-brand-sand/40">
                                        <input type="checkbox" wire:model.live="columns.{{ $key }}" class="h-3.5 w-3.5 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="w-full sm:max-w-xs sm:ml-auto">
                            <label for="invoice-search" class="sr-only">{{ __('Search') }}</label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist">
                                    <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" aria-hidden="true" />
                                </span>
                                <input
                                    id="invoice-search"
                                    type="search"
                                    wire:model.live.debounce.300ms="search"
                                    placeholder="{{ __('Search number or description…') }}"
                                    autocomplete="off"
                                    class="h-7 w-full rounded-md border-brand-ink/15 bg-white py-0 ps-8 pe-2.5 text-xs text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                />
                            </div>
                        </div>
                    </div>

                    @if ($rows->isEmpty())
                        <div class="flex flex-col items-center justify-center px-3 py-10 text-center sm:px-4">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-inbox class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <p class="mt-2.5 text-sm font-semibold text-brand-ink">
                                {{ $search !== '' ? __('No invoices match this search.') : __('No invoices found.') }}
                            </p>
                            @if ($search !== '')
                                <button type="button" wire:click="$set('search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
                            @endif
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-brand-ink/5 text-left text-sm">
                                <thead class="bg-brand-sand/35 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                                    <tr>
                                        @foreach (['number' => 'px-3 py-1.5 sm:px-4', 'description' => 'px-3 py-1.5', 'status' => 'px-3 py-1.5', 'total' => 'px-3 py-1.5 text-right', 'date' => 'px-3 py-1.5'] as $key => $thClass)
                                            @if ($columns[$key])
                                                <th scope="col" class="{{ $thClass }}">
                                                    @if ($key === 'status')
                                                        {{ $columnLabels[$key] }}
                                                    @else
                                                        <button
                                                            type="button"
                                                            wire:click="sortBy('{{ $key }}')"
                                                            @class([
                                                                'inline-flex items-center gap-1 font-semibold transition-colors hover:text-brand-ink',
                                                                'text-brand-ink' => $sortColumn === $key,
                                                                'text-brand-moss' => $sortColumn !== $key,
                                                            ])
                                                            aria-label="{{ __('Sort by :column', ['column' => $columnLabels[$key]]) }}"
                                                        >
                                                            {{ $columnLabels[$key] }}
                                                            @if ($icon = $sortIcon($key))
                                                                <x-dynamic-component :component="$icon" class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                            @else
                                                                <span aria-hidden="true" class="opacity-40">⇅</span>
                                                            @endif
                                                        </button>
                                                    @endif
                                                </th>
                                            @endif
                                        @endforeach
                                        @if ($columns['actions'])
                                            <th scope="col" class="px-3 py-1.5 text-end sm:px-4">{{ $columnLabels['actions'] }}</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5 bg-white">
                                    @foreach ($rows as $row)
                                        @php
                                            $status = strtolower((string) ($row['status'] ?? ''));
                                            $statusClasses = match (true) {
                                                in_array($status, ['paid', 'succeeded'], true) => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                                in_array($status, ['open', 'draft'], true) => 'border-sky-200 bg-sky-50 text-sky-700',
                                                in_array($status, ['uncollectible', 'void', 'failed'], true) => 'border-red-200 bg-red-50 text-red-700',
                                                default => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
                                            };
                                        @endphp
                                        <tr class="transition-colors hover:bg-brand-sand/15">
                                            @if ($columns['number'])
                                                <td class="whitespace-nowrap px-3 py-1.5 font-mono text-xs text-brand-ink sm:px-4">{{ $row['number'] }}</td>
                                            @endif
                                            @if ($columns['description'])
                                                <td class="max-w-md truncate px-3 py-1.5 text-xs text-brand-ink" title="{{ $row['description'] }}">{{ $row['description'] }}</td>
                                            @endif
                                            @if ($columns['status'])
                                                <td class="whitespace-nowrap px-3 py-1.5">
                                                    <span class="inline-flex items-center rounded border px-1.5 py-px text-2xs font-semibold uppercase tracking-wide {{ $statusClasses }}">
                                                        {{ $row['status_label'] }}
                                                    </span>
                                                </td>
                                            @endif
                                            @if ($columns['total'])
                                                <td class="whitespace-nowrap px-3 py-1.5 text-right font-mono text-xs font-semibold tabular-nums text-brand-ink">{{ $row['total'] }}</td>
                                            @endif
                                            @if ($columns['date'])
                                                <td class="whitespace-nowrap px-3 py-1.5 font-mono text-xs tabular-nums text-brand-moss">{{ $row['date']->format('Y-m-d H:i') }}</td>
                                            @endif
                                            @if ($columns['actions'])
                                                <td class="whitespace-nowrap px-3 py-1.5 text-end sm:px-4">
                                                    @if (! empty($row['pdf_url']))
                                                        <a
                                                            href="{{ $row['pdf_url'] }}"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            class="inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:text-brand-ink"
                                                        >
                                                            @if (! empty($row['is_pdf']))
                                                                <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                                {{ __('PDF') }}
                                                            @else
                                                                <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                                {{ __('View') }}
                                                            @endif
                                                        </a>
                                                    @else
                                                        <span class="text-xs text-brand-mist">—</span>
                                                    @endif
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="flex flex-col gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2 sm:flex-row sm:items-center sm:justify-between sm:px-4">
                            <label class="inline-flex shrink-0 items-center gap-1.5 text-xs text-brand-moss" for="per-page">
                                <span class="whitespace-nowrap">{{ __('Rows') }}</span>
                                <select
                                    id="per-page"
                                    wire:model.live="perPage"
                                    class="h-7 rounded-md border-brand-ink/15 bg-white py-0 ps-2 pe-7 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                >
                                    @foreach ([10, 15, 25, 50] as $n)
                                        <option value="{{ $n }}">{{ $n }}</option>
                                    @endforeach
                                </select>
                            </label>
                            @if ($rows->hasPages())
                                <div class="min-w-0 flex-1">
                                    {{ $rows->links() }}
                                </div>
                            @else
                                <span class="text-end text-xs tabular-nums text-brand-moss">{{ trans_choice(':n result|:n results', $total, ['n' => $total]) }}</span>
                            @endif
                        </div>
                    @endif
                </section>
            @endif
        </x-organization-shell>
    </div>
</div>
