<?php

declare(strict_types=1);

namespace App\Services\DeployContract;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads `dply-contract.yaml` / `dply-contract.yml` / nested `contract:` from dply.yaml.
 */
final class DeployContractPolicyLoader
{
    private const CONTRACT_FILES = [
        'dply-contract.yaml',
        'dply-contract.yml',
    ];

    private const MAX_BYTES = 16 * 1024;

    /**
     * @return array<string, mixed>|null Normalized contract block for repo_config storage.
     */
    public function loadFromDirectory(string $checkoutPath): ?array
    {
        $base = rtrim($checkoutPath, '/');
        if ($base === '' || ! is_dir($base)) {
            return null;
        }

        foreach (self::CONTRACT_FILES as $file) {
            $path = $base.'/'.$file;
            if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
                continue;
            }

            $parsed = $this->decodeFile($file, (string) file_get_contents($path));
            if ($parsed !== null) {
                return $this->normalizeRoot($parsed);
            }
        }

        foreach (['dply.yaml', 'dply.yml'] as $file) {
            $path = $base.'/'.$file;
            if (! is_file($path) || filesize($path) > 64 * 1024) {
                continue;
            }

            $parsed = $this->decodeFile($file, (string) file_get_contents($path));
            if (is_array($parsed) && is_array($parsed['contract'] ?? null)) {
                return $this->normalizeRoot($parsed['contract']);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeFile(string $sourcePath, string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        try {
            if (str_ends_with(strtolower($sourcePath), '.json')) {
                $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $decoded = Yaml::parse($trimmed);
            }
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function normalizeRoot(array $parsed): array
    {
        if (isset($parsed['promote']) && is_array($parsed['promote'])) {
            return $parsed;
        }

        if (isset($parsed['requires']) || isset($parsed['min_replay_pass_rate']) || isset($parsed['require_replay'])) {
            return ['promote' => $parsed];
        }

        return $parsed;
    }
}
