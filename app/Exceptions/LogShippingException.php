<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Actions\Servers\ManageServerLogShipping;
use RuntimeException;

/**
 * Thrown by {@see ManageServerLogShipping} when an
 * enable/resync/disable request can't proceed (kill-switch off, non-VM server,
 * agent already busy, …). Each surface — Livewire (toast), MCP (structured
 * error), REST (422 JSON) — catches this and renders the message in its own
 * idiom, so the guard logic lives in exactly one place.
 */
class LogShippingException extends RuntimeException {}
