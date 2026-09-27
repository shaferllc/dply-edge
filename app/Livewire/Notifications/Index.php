<?php

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\NotificationInboxItem;
use App\Support\NotificationTablesReady;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    /** unread | all | saved */
    public string $filter = 'unread';

    /** Event category to narrow to ('' = all). */
    public string $categoryFilter = '';

    /** Event severity to narrow to ('' = all). */
    public string $severityFilter = '';

    /**
     * Selected item ids for bulk actions.
     *
     * @var list<string>
     */
    public array $selected = [];

    /** Page size for the feed; "Load more" grows it by the same step. */
    private const PER_PAGE = 25;

    /** How many feed items to render — grows via {@see loadMore()}. */
    #[Locked]
    public int $perPage = self::PER_PAGE;

    public function loadMore(): void
    {
        $this->perPage += self::PER_PAGE;
    }

    public function updatedFilter(): void
    {
        $this->selected = [];
        $this->perPage = self::PER_PAGE;
    }

    public function updatedCategoryFilter(): void
    {
        $this->selected = [];
        $this->perPage = self::PER_PAGE;
    }

    public function updatedSeverityFilter(): void
    {
        $this->selected = [];
        $this->perPage = self::PER_PAGE;
    }

    /** Scope every query to the current user's inbox. */
    /**
     * @return Builder<NotificationInboxItem>
     */
    private function base(): Builder
    {
        return NotificationInboxItem::query()->where('user_id', auth()->id());
    }

    public function markAsRead(string $itemId): void
    {
        if (! $this->notificationTablesReady()) {
            return;
        }

        $this->base()->whereKey($itemId)->update(['read_at' => now()]);
    }

    public function markAllAsRead(): void
    {
        if (! $this->notificationTablesReady()) {
            return;
        }

        $this->base()->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** Mark-read-on-click, then navigate to the item's deep link. */
    public function openItem(string $itemId): void
    {
        if (! $this->notificationTablesReady()) {
            return;
        }

        $item = $this->base()->whereKey($itemId)->first();
        if ($item === null) {
            return;
        }

        if ($item->read_at === null) {
            $item->forceFill(['read_at' => now()])->save();
        }

        // Livewire's redirect() returns Redirector, not RedirectResponse — do
        // not type the return as the HTTP class or PHP 8 fatals on click.
        $this->redirect($item->ctaUrl() ?: route('notifications.index'));
    }

    /** Toggle the "save to remember" star — orthogonal to read state. */
    public function toggleSaved(string $itemId): void
    {
        if (! $this->notificationTablesReady()) {
            return;
        }

        $item = $this->base()->whereKey($itemId)->first();
        if ($item === null) {
            return;
        }

        $item->forceFill(['saved_at' => $item->saved_at ? null : now()])->save();
    }

    public function markSelectedRead(): void
    {
        if (! $this->notificationTablesReady() || $this->selected === []) {
            return;
        }

        $this->base()->whereIn('id', $this->selected)->whereNull('read_at')->update(['read_at' => now()]);
        $this->selected = [];
    }

    public function saveSelected(): void
    {
        if (! $this->notificationTablesReady() || $this->selected === []) {
            return;
        }

        $this->base()->whereIn('id', $this->selected)->whereNull('saved_at')->update(['saved_at' => now()]);
        $this->selected = [];
    }

    public function unsaveSelected(): void
    {
        if (! $this->notificationTablesReady() || $this->selected === []) {
            return;
        }

        $this->base()->whereIn('id', $this->selected)->whereNotNull('saved_at')->update(['saved_at' => null]);
        $this->selected = [];
    }

    /**
     * Bulk delete — saved is sacred, so starred items in the selection are kept
     * and the operator is told. Only a single per-row delete removes a saved one.
     */
    public function deleteSelected(): void
    {
        if (! $this->notificationTablesReady() || $this->selected === []) {
            return;
        }

        $requested = count($this->selected);
        $deletableIds = $this->base()->whereIn('id', $this->selected)->whereNull('saved_at')->pluck('id')->all();
        $deleted = $deletableIds === [] ? 0 : $this->base()->whereIn('id', $deletableIds)->delete();
        $kept = $requested - count($deletableIds);
        $this->selected = [];

        if ($kept > 0) {
            $this->toastWarning(trans_choice(
                '{1}Deleted :deleted — kept 1 saved item (delete it individually).|[2,*]Deleted :deleted — kept :kept saved items (delete them individually).',
                $kept,
                ['deleted' => $deleted, 'kept' => $kept],
            ));
        }
    }

    /** Explicit single delete — removes the item even if saved. */
    public function deleteItem(string $itemId): void
    {
        if (! $this->notificationTablesReady()) {
            return;
        }

        $this->base()->whereKey($itemId)->delete();
        $this->selected = array_values(array_diff($this->selected, [$itemId]));
    }

    /** Delete every read, unsaved item for this user (saved + unread untouched). */
    public function deleteAllRead(): void
    {
        if (! $this->notificationTablesReady()) {
            return;
        }

        $deleted = $this->base()->whereNotNull('read_at')->whereNull('saved_at')->delete();
        $this->selected = [];
        $this->toastSuccess(trans_choice('{0}No read notifications to delete.|{1}Deleted 1 read notification.|[2,*]Deleted :count read notifications.', $deleted, ['count' => $deleted]));
    }

    public function render(): View
    {
        if (! $this->notificationTablesReady()) {
            return view('livewire.notifications.index', [
                'items' => collect(),
                'hasMore' => false,
                'unreadCount' => 0,
                'totalCount' => 0,
                'savedCount' => 0,
                'attentionCount' => 0,
                'categories' => collect(),
                'notificationsReady' => false,
            ]);
        }

        $query = $this->base()->latest()->with('event');

        match ($this->filter) {
            'unread' => $query->whereNull('read_at'),
            'saved' => $query->whereNotNull('saved_at'),
            default => null, // 'all'
        };

        if ($this->categoryFilter !== '') {
            $query->whereHas('event', fn ($q) => $q->where('category', $this->categoryFilter));
        }

        if ($this->severityFilter !== '') {
            $query->whereHas('event', fn ($q) => $q->where('severity', $this->severityFilter));
        }

        // Distinct categories the user actually has, for the filter dropdown.
        $categories = $this->base()
            ->join('notification_events', 'notification_events.id', '=', 'notification_inbox_items.notification_event_id')
            ->whereNotNull('notification_events.category')
            ->distinct()
            ->orderBy('notification_events.category')
            ->pluck('notification_events.category');

        // One page plus a sentinel row, so "Load more" shows only when needed.
        $items = $query->limit($this->perPage + 1)->get();
        $hasMore = $items->count() > $this->perPage;

        // All four header counts in one pass instead of four count(*) queries.
        $counts = $this->base()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when read_at is null then 1 else 0 end) as unread')
            ->selectRaw('sum(case when saved_at is not null then 1 else 0 end) as saved')
            ->selectRaw("sum(case when read_at is null and exists (select 1 from notification_events e where e.id = notification_inbox_items.notification_event_id and e.severity in ('warning', 'critical', 'error', 'danger')) then 1 else 0 end) as attention")
            ->toBase()
            ->first();

        return view('livewire.notifications.index', [
            'items' => $hasMore ? $items->take($this->perPage) : $items,
            'hasMore' => $hasMore,
            'unreadCount' => (int) $counts->unread,
            'totalCount' => (int) $counts->total,
            'savedCount' => (int) $counts->saved,
            'attentionCount' => (int) $counts->attention,
            'categories' => $categories,
            'notificationsReady' => true,
        ]);
    }

    protected function notificationTablesReady(): bool
    {
        return NotificationTablesReady::all();
    }
}
