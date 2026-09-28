{{-- Invoices tab: Stripe invoices, loaded after first paint (Show::loadInvoices via wire:init). --}}
<section id="invoices" class="dply-card overflow-hidden p-0" wire:init="loadInvoices">
    <x-workspace-panel-head dense icon="heroicon-o-document" :title="__('Invoices')" :note="__('Recent invoices from Stripe.')" />
    @if (! $invoicesLoaded)
        <p class="px-3 py-8 text-center text-xs text-brand-mist sm:px-4">{{ __('Loading invoices…') }}</p>
    @elseif ($this->invoices === [])
        <x-empty-state
            borderless
            compact
            icon="heroicon-o-document-text"
            :title="__('No invoices yet')"
            :description="__('Invoices appear here once your plan is billed.')"
        />
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($this->invoices as $invoice)
                <li class="flex items-center justify-between gap-4 px-3 py-2.5 transition-colors hover:bg-brand-sand/15 sm:px-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-brand-ink">{{ \Illuminate\Support\Carbon::createFromTimestamp($invoice['date'])->toFormattedDateString() }}</p>
                        <p class="mt-0.5 font-mono text-xs text-brand-moss tabular-nums">{{ $invoice['total'] }}</p>
                    </div>
                    @if ($invoice['url'])
                        <a href="{{ $invoice['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-brand-sage hover:text-brand-ink">
                            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Open in Stripe') }}
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
