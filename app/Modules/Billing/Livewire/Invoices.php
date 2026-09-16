<?php

namespace App\Modules\Billing\Livewire;

use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Laravel\Cashier\Invoice;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Livewire exposes #[Computed] methods and the older get<Name>Property()
 * methods as $this-><name> in PHP and Blade. PHPStan cannot see that
 * magic, so the contract is stated here.
 *
 * @property-read LengthAwarePaginator $rowsPaginator
 */
#[Layout('layouts.app')]
class Invoices extends Component
{
    use WithPagination;

    public Organization $organization;

    public string $search = '';

    public int $perPage = 15;

    public string $sortColumn = 'date';

    public string $sortDirection = 'desc';

    /** @var array<string, bool> */
    public array $columns = [
        'number' => true,
        'description' => true,
        'status' => true,
        'total' => true,
        'date' => true,
        'actions' => true,
    ];

    public function mount(Organization $organization): void
    {
        $this->authorize('update', $organization);
        $this->organization = $organization;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, ['number', 'description', 'status', 'date', 'total'], true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = $column === 'date' ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    /**
     * Cached Stripe invoice rows for the current request.
     *
     * @var Collection<int, array<string, mixed>>|null
     */
    protected ?Collection $invoiceRowsCache = null;

    protected function invoiceRows(): Collection
    {
        if ($this->invoiceRowsCache !== null) {
            return $this->invoiceRowsCache;
        }

        if (! $this->organization->hasStripeId()) {
            return $this->invoiceRowsCache = collect();
        }

        try {
            $invoices = $this->organization->invoicesIncludingPending(['limit' => 100]);
        } catch (Throwable) {
            return $this->invoiceRowsCache = collect();
        }

        $tz = auth()->user()->timezone ?? config('app.timezone', 'UTC');

        // The closure's array<string, mixed> return keeps the mapped Collection
        // assignable to $invoiceRowsCache — Collection's TValue is invariant, so
        // an inferred array{...} shape would not fit Collection<int, array<string, mixed>>.
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $invoices->map(function (Invoice $invoice) use ($tz): array {
            $stripe = $invoice->asStripeInvoice();
            $pdfUrl = $stripe->invoice_pdf ?? $stripe->hosted_invoice_url;

            return [
                'id' => $stripe->id,
                'number' => $stripe->number ?? $stripe->id,
                'description' => $this->describeInvoice($invoice),
                'status' => (string) ($stripe->status ?? 'unknown'),
                'status_label' => $this->formatStatus((string) ($stripe->status ?? '')),
                'total' => $invoice->total(),
                'date' => $invoice->date($tz),
                'pdf_url' => $pdfUrl,
                'is_pdf' => ! empty($stripe->invoice_pdf),
            ];
        });

        return $this->invoiceRowsCache = $rows;
    }

    protected function describeInvoice(Invoice $invoice): string
    {
        $stripe = $invoice->asStripeInvoice();
        if (! empty($stripe->description)) {
            return $stripe->description;
        }

        $lines = $stripe->lines->data ?? [];
        if ($lines === []) {
            return '—';
        }

        $first = $lines[0];
        if (! empty($first->description)) {
            return $first->description;
        }

        if (isset($first->plan) && is_object($first->plan) && ! empty($first->plan->nickname)) {
            return (string) $first->plan->nickname;
        }

        if (isset($first->price) && is_object($first->price) && ! empty($first->price->nickname)) {
            return (string) $first->price->nickname;
        }

        return __('Subscription invoice');
    }

    protected function formatStatus(string $status): string
    {
        return $status === ''
            ? '—'
            : ucfirst(str_replace('_', ' ', $status));
    }

    public function getRowsPaginatorProperty(): LengthAwarePaginator
    {
        $rows = $this->invoiceRows();

        if ($this->search !== '') {
            $needle = mb_strtolower($this->search);
            $rows = $rows->filter(function (array $row) use ($needle) {
                $hay = mb_strtolower(
                    ($row['number'] ?? '').' '.($row['description'] ?? '').' '.($row['status'] ?? '').' '.($row['total'] ?? '')
                );

                return str_contains($hay, $needle);
            });
        }

        $rows = $rows->values();
        $sort = $this->sortColumn;
        $dir = $this->sortDirection;

        $sorted = $rows->sort(function (array $a, array $b) use ($sort, $dir) {
            $va = $a[$sort] ?? '';
            $vb = $b[$sort] ?? '';

            if ($sort === 'date' && $va instanceof CarbonInterface && $vb instanceof CarbonInterface) {
                $cmp = $va->timestamp <=> $vb->timestamp;
            } else {
                $cmp = strnatcasecmp((string) $va, (string) $vb);
            }

            return $dir === 'asc' ? $cmp : -$cmp;
        })->values();

        $currentPage = Paginator::resolveCurrentPage();
        $perPage = max(5, min(100, $this->perPage));
        $items = $sorted->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return (new LengthAwarePaginator(
            $items,
            $sorted->count(),
            $perPage,
            $currentPage,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        ))->withQueryString();
    }

    public function render(): View
    {
        return view('livewire.billing.invoices', [
            'rows' => $this->rowsPaginator,
            'hasStripeCustomer' => $this->organization->hasStripeId(),
        ]);
    }
}
