<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised inside {@see InstallLogAgentJob} when an operator cancels a
 * running install from the UI.
 *
 * Distinct from a genuine install failure so the job can unwind without
 * overwriting the "Canceled" state the operator just asked for, and without
 * being retried by the queue — a cancel that reappears a minute later would be
 * worse than no cancel button at all.
 */
class LogAgentInstallCanceledException extends RuntimeException {}
