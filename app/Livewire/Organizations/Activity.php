<?php

namespace App\Livewire\Organizations;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Support\AuditActionMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Activity extends Component
{
    use WithPagination;

    public Organization $organization;

    /** Active family filter id from {@see AuditActionMeta::FAMILIES} (`''` = no filter). */
    #[Url(as: 'family', except: '')]
    public string $family = '';

    /** Optional free-text search against the action, subject type and recorded values. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Rows per page. URL-synced so deep-links round-trip the picker too. */
    #[Url(as: 'per', except: 25)]
    public int $perPage = 25;

    /**
     * Audit log row IDs the operator has expanded inline to view the
     * old/new value diff. Kept in the component (not the URL) — short-
     * lived UI state.
     *
     * @var list<string>
     */
    public array $expandedIds = [];

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        abort_unless($organization->hasAdminAccess(auth()->user()), 403);
        $this->organization = $organization;
    }

    public function setFamily(string $family): void
    {
        $valid = array_column(AuditActionMeta::FAMILIES, 'id');
        $this->family = in_array($family, $valid, true) ? $family : '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->family = '';
        $this->search = '';
        $this->resetPage();
    }

    /** Reset pagination when filters change so we don't land on an empty page. */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function toggleRow(string $id): void
    {
        if (in_array($id, $this->expandedIds, true)) {
            $this->expandedIds = array_values(array_diff($this->expandedIds, [$id]));

            return;
        }

        $this->expandedIds[] = $id;
    }

    /**
     * Paginated audit rows for this org, filtered by family + free-text
     * search. The family filter applies a set of action-prefix predicates
     * via {@see AuditActionMeta::family} so the resolver and the query
     * stay in sync (one source of truth for "what counts as `server`").
     */
    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function getAuditLogsProperty(): LengthAwarePaginator
    {
        // Eager-load the morphTo subject so the subject_summary accessor
        // doesn't lazy-load one server/credential/site per row (N+1).
        // Laravel batches morphTo loads by subject_type, so repeated
        // subjects collapse to one whereIn per type.
        $query = $this->organization->auditLogs()
            ->with(['user', 'subject'])
            ->latest();

        $this->applyFamilyFilter($query, $this->family);

        if ($this->search !== '') {
            $needle = '%'.trim($this->search).'%';
            $query->where(function (Builder $q) use ($needle): void {
                // subject_summary is a PHP accessor, not a column; the names it
                // shows are in the recorded values, so search those instead.
                $q->where('action', 'ilike', $needle)
                    ->orWhere('subject_type', 'ilike', $needle)
                    ->orWhereRaw('CAST(old_values AS text) ILIKE ?', [$needle])
                    ->orWhereRaw('CAST(new_values AS text) ILIKE ?', [$needle]);
            });
        }

        $perPage = max(10, min(100, $this->perPage));

        // Unfiltered, the paginator's COUNT(*) is exactly familyTotals['']
        // (same org scope), so hand it over instead of counting twice.
        $total = $this->family === '' && $this->search === '' ? $this->familyTotals[''] : null;

        return $query->paginate($perPage, total: $total);
    }

    /**
     * Per-family totals scoped to this org. Drives the count chips on
     * each filter pill — they show "Apps · 42" so an admin can spot
     * spikes at a glance. Excludes the search box so the totals don't
     * jump around as you type.
     *
     * Computed in a single conditional-aggregation query rather than one
     * COUNT per family (previously 14 round-trips, one of which exactly
     * duplicated the paginator's count for the unfiltered view).
     *
     * @return array<string, int>
     */
    public function getFamilyTotalsProperty(): array
    {
        $conditions = self::familyConditions();

        $selects = ['COUNT(*) as total_all'];
        foreach ($conditions as $id => $sql) {
            $selects[] = "SUM(CASE WHEN {$sql} THEN 1 ELSE 0 END) as total_{$id}";
        }

        $row = AuditLog::query()
            ->where('organization_id', $this->organization->id)
            ->selectRaw(implode(', ', $selects))
            ->toBase()
            ->first();

        $totals = ['' => (int) ($row->total_all ?? 0)];
        foreach (array_keys($conditions) as $id) {
            $totals[$id] = (int) ($row->{"total_{$id}"} ?? 0);
        }

        return $totals;
    }

    /**
     * Apply the family filter to the listing query. Shares its predicate
     * definitions with {@see familyConditions} so the chip counts and the
     * filtered list can never drift apart.
     *
     * Accepts either a {@see Builder} or a {@see HasMany} relation; chains
     * on Eloquent relations stay on the relation object rather than
     * promoting to Builder, so this method has to handle both.
     */
    /**
     * @param  Builder<AuditLog>|HasMany<AuditLog, Organization>  $query
     */
    private function applyFamilyFilter(Builder|HasMany $query, string $family): void
    {
        $conditions = self::familyConditions();
        if (isset($conditions[$family])) {
            $query->whereRaw('('.$conditions[$family].')');
        }
    }

    /**
     * Family id → raw SQL predicate over the `action` column. The strings
     * are fully static (no user input), so whereRaw / selectRaw use them
     * safely. Keep buckets in lock-step with {@see AuditActionMeta::family},
     * which derives the same families for per-row icons.
     *
     * @return array<string, string>
     */
    private static function familyConditions(): array
    {
        $families = [
            'site' => "action LIKE 'site.%' AND action NOT LIKE 'site.edge.%'",
            'edge' => "action LIKE 'site.edge.%'",
            'resources' => "(action LIKE 'database.%' OR action LIKE 'queue.%')",
            'team' => "action LIKE 'team.%'",
            'billing' => "action LIKE 'billing.%'",
            'security' => "(action LIKE 'api_token.%' OR action LIKE 'invitation.%' OR action LIKE 'notification_channel.%' "
                ."OR action LIKE 'user.%' OR action LIKE 'credential.%' OR action LIKE 'impersonation.%')",
            'org' => "action LIKE 'organization.%'",
        ];

        // Everything else, including rows from the removed VM products.
        $families['other'] = 'NOT ('.implode(' OR ', array_map(fn (string $sql): string => "({$sql})", $families)).')';

        return $families;
    }

    public function render(): View
    {
        // Audit log viewing is a Team feature; events are recorded on every plan.
        if (! ($this->organization->tierAllowances()['audit_log'] ?? false)) {
            return view('livewire.organizations.activity-locked');
        }

        return view('livewire.organizations.activity', [
            'families' => AuditActionMeta::FAMILIES,
        ]);
    }
}
