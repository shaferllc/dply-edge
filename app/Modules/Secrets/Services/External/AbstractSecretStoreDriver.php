<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services\External;

abstract class AbstractSecretStoreDriver implements SecretStoreDriver
{
    /**
     * @return array{0: string, 1: string|null}
     */
    public static function splitReference(string $reference): array
    {
        $reference = trim($reference);
        $hash = strpos($reference, '#');
        if ($hash === false) {
            return [$reference, null];
        }

        return [substr($reference, 0, $hash), substr($reference, $hash + 1)];
    }

    /**
     * Extract a field from a decoded structured secret, or return the raw value
     * when no field is requested.
     *
     * @param  array<string, mixed>  $data
     */
    protected function pickField(array $data, ?string $field, string $what): string
    {
        if ($field !== null) {
            if (! array_key_exists($field, $data)) {
                throw new \RuntimeException("{$what}: field '{$field}' not found in the secret.");
            }

            return (string) $data[$field];
        }

        // No field requested: only unambiguous when the secret has exactly one key.
        if (count($data) === 1) {
            return (string) array_values($data)[0];
        }

        throw new \RuntimeException("{$what}: secret has multiple fields — add '#<field>' to the reference.");
    }
}
