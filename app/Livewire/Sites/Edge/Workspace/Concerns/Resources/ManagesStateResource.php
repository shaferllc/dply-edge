<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace\Concerns\Resources;

use App\Livewire\Sites\Edge\Workspace\Resources;

/**
 * Resources sheet: State (Durable Object key/value). Mixed into
 * {@see Resources}; the open connection
 * is $this->openResourceConnection() (set by openResource()).
 */
trait ManagesStateResource {}
