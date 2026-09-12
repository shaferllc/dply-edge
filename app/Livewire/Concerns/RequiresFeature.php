<?php

namespace App\Livewire\Concerns;

use Livewire\Component;

/**
 * Defense-in-depth flag check on every Livewire request (mount + hydrate).
 *
 * @phpstan-require-extends Component
 *
 * @property string $requiredFeature
 */
trait RequiresFeature
{
    public function bootedRequiresFeature(): void
    {
        // Product flags are retired — surfaces are always on.
    }
}
