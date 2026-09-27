<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Beta\BetaProgram;
use Illuminate\Support\Carbon;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 *
 * @property ?Carbon $beta_joined_at
 */
trait ManagesOrganizationBeta
{
    /**
     * True while this org is an active closed-beta participant: it redeemed an
     * invite (`beta_joined_at` set) AND the global beta program is still open.
     * A label only: it grants nothing billing-wise (no fee waiver, caps or
     * seat exemption; ruling r-jnv0r3qf1xk49kmc, "no extra beta perks").
     */
    public function isBeta(): bool
    {
        return data_get($this->getAttributes(), 'beta_joined_at') !== null && BetaProgram::isOpen();
    }
}
