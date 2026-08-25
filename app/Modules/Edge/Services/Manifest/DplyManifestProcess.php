<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Manifest;

/**
 * Single process entry within a `dply.yaml` manifest.
 *
 * The manifest's `processes:` map keys (e.g. "web", "worker", "scheduler",
 * or any user-named custom type) become process names. Each value is either
 * a bare string (treated as the command, scale=1) or a map with explicit
 * `command:` and optional scale / role / oneshot fields.
 */
final readonly class DplyManifestProcess
{
    /**
     * @param  list<string>  $roles  e.g. worker, worker:primary, web
     * @param  array<string, string>  $env
     */
    public function __construct(
        public string $name,
        public string $command,
        public int $scale = 1,
        public array $roles = [],
        public bool $oneshot = false,
        public ?int $loopSeconds = null,
        public ?int $stopwaitsecs = null,
        public ?string $type = null,
        public array $env = [],
    ) {}

    /**
     * Meta payload persisted on SiteProcess for WorkerDaemonBackend.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $meta = [];
        if ($this->roles !== []) {
            $meta['roles'] = $this->roles;
        }
        if ($this->oneshot) {
            $meta['oneshot'] = true;
        }
        if ($this->loopSeconds !== null) {
            $meta['loop_seconds'] = $this->loopSeconds;
        }
        if ($this->stopwaitsecs !== null) {
            $meta['stopwaitsecs'] = $this->stopwaitsecs;
        }

        return $meta;
    }
}
