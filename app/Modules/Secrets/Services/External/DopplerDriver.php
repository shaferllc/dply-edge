<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services\External;

use App\Models\ExternalSecretStore;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Doppler. config: {token, project?, config?}. Reference is the secret NAME
 * (e.g. "STRIPE_SECRET"), or "PROJECT/CONFIG/NAME" to override the store's
 * default project/config. The "#field" suffix is unused (Doppler secrets are
 * scalar) and ignored if present.
 */
class DopplerDriver extends AbstractSecretStoreDriver
{
    public function fetch(ExternalSecretStore $store, string $reference): string
    {
        $cfg = (array) $store->config;
        $token = (string) ($cfg['token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Doppler store is missing a token.');
        }

        [$path] = self::splitReference($reference);
        [$project, $config, $name] = $this->locate($path, $cfg);

        $response = Http::withToken($token)->acceptJson()->timeout(15)
            ->get('https://api.doppler.com/v3/configs/config/secret', [
                'project' => $project,
                'config' => $config,
                'name' => $name,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Doppler returned HTTP {$response->status()} for '{$name}'.");
        }

        $raw = $response->json('value.raw');
        if ($raw === null) {
            throw new RuntimeException("Doppler secret '{$name}' not found.");
        }

        return (string) $raw;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array{0: string, 1: string, 2: string} [project, config, name]
     */
    private function locate(string $path, array $cfg): array
    {
        $parts = explode('/', $path);
        if (count($parts) === 3) {
            return [$parts[0], $parts[1], $parts[2]];
        }

        $project = (string) ($cfg['project'] ?? '');
        $config = (string) ($cfg['config'] ?? '');
        if ($project === '' || $config === '') {
            throw new RuntimeException('Doppler reference needs PROJECT/CONFIG/NAME, or set project+config on the store.');
        }

        return [$project, $config, $path];
    }
}
