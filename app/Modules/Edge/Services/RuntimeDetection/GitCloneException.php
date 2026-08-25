<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\RuntimeDetection;

/**
 * Thrown by {@see GitCloner} implementations when a clone attempt fails.
 *
 * The message is intended to be safe to render directly in the UI — the
 * cloner sanitizes git's stderr to avoid leaking credentials when the user
 * has accidentally embedded a token in the URL.
 */
class GitCloneException extends \RuntimeException {}
