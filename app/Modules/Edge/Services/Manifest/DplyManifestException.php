<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Manifest;

use RuntimeException;
use Throwable;

/**
 * Thrown when a `dply.yaml` manifest cannot be parsed or fails validation.
 *
 * The {@see fieldPath} is a dot-separated path into the manifest structure
 * (e.g. "processes.worker.command", "build", "runtime"), surfaced in the
 * dply UI as "this field is the problem" so users can fix the right line.
 */
class DplyManifestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $fieldPath = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidYaml(string $detail, ?Throwable $previous = null): self
    {
        return new self(
            message: "Invalid YAML in dply.yaml: {$detail}",
            fieldPath: null,
            previous: $previous,
        );
    }

    public static function invalidField(string $fieldPath, string $detail): self
    {
        return new self(
            message: "Invalid value at `{$fieldPath}`: {$detail}",
            fieldPath: $fieldPath,
        );
    }
}
