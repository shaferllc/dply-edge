<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\RecentResource;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCommandPaletteStack
{
    /**
     * Drill into a context. Category labels come from the static map; a single
     * site/server resolves its label (and is org-scoped) from the record.
     */
    public function push(string $type, ?string $id = null): void
    {
        $org = auth()->user()?->currentOrganization();
        $label = $this->categoryLabels()[$type] ?? null;

        if ($type === 'site' && $id !== null) {
            $label = $this->scopedSite($org, $id)?->name;
        } elseif ($type === 'server' && $id !== null) {
            $label = $this->scopedServer($org, $id)?->name;
        }

        if ($label === null) {
            return; // unknown context or a record outside the current org
        }

        // Record the drill-in so the empty-query root can offer "Recently
        // visited". Only the per-record contexts (site/server) are worth it.
        if ($id !== null && in_array($type, ['site', 'server'], true)) {
            RecentResource::record(auth()->id(), $type, $id);
        }

        $this->stack[] = ['type' => $type, 'id' => $id, 'label' => $label];
        $this->query = '';
        $this->dispatch('cmdk-changed');
    }

    /** Pop one level back up the stack. */
    public function pop(): void
    {
        array_pop($this->stack);
        $this->query = '';
        $this->dispatch('cmdk-changed');
    }

    /** Trim the stack to a given depth (breadcrumb click). */
    public function popTo(int $depth): void
    {
        $this->stack = array_slice($this->stack, 0, max(0, $depth));
        $this->query = '';
        $this->dispatch('cmdk-changed');
    }

    /** Return to true root — used by the breadcrumb "Home" button. */
    public function resetStack(): void
    {
        $this->stack = [];
        $this->query = '';
    }

    /**
     * Reset to the page's context (or true root if none) — used on close, so the
     * next open starts where the current page expects rather than at bare root.
     */
    public function resetToContext(): void
    {
        $this->stack = $this->contextSeed !== null ? [$this->contextSeed] : [];
        $this->query = '';
    }
}
