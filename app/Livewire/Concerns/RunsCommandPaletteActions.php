<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Session;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait RunsCommandPaletteActions
{
    /**
     * Run an action row. Actions *do* something (switch org) rather than
     * navigate; the view routes here via wire:click. Every action re-resolves
     * and re-authorizes its target server-side — the rendered row is a
     * convenience, never the authority.
     */
    public function run(string $key, ?string $id = null): mixed
    {
        return match ($key) {
            'org.switch' => $this->runOrgSwitch($id),
            default => null,
        };
    }

    /** Switch the active organization (membership-checked) and reload. */
    private function runOrgSwitch(?string $id): mixed
    {
        $user = auth()->user();
        if ($id === null || $user === null) {
            return null;
        }
        if (! $user->organizations()->where('organizations.id', $id)->exists()) {
            return null; // not a member — render is stale or tampered
        }

        Session::put('current_organization_id', $id);
        Session::forget('current_team_id');
        Session::flash('success', __('Organization switched.'));

        return $this->redirect(route('dashboard'), navigate: true);
    }
}
