<?php

declare(strict_types=1);

namespace App\Support\Deployment;

final readonly class DeploymentContract
{
    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $config
     * @param  list<DeploymentSecret>  $secrets
     * @param  array<string, mixed>  $artifacts
     * @param  array<string, mixed>  $status
     * @param  list<SiteResourceBinding>  $resourceBindings
     */
    public function __construct(
        public array $target,
        public array $config,
        public array $secrets,
        public array $artifacts,
        public array $status,
        public array $resourceBindings,
    ) {}

    /**
     * @return array<string, string>
     */
    public function environmentMap(): array
    {
        $environment = $this->config['environment'] ?? [];

        return is_array($environment) ? array_filter(
            array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $environment),
            static fn (string $value): bool => true
        ) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function secretArrays(): array
    {
        return array_map(
            static fn (DeploymentSecret $secret): array => $secret->toArray(),
            $this->secrets,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resourceBindingArrays(): array
    {
        return array_map(
            static fn (SiteResourceBinding $binding): array => $binding->toArray(),
            $this->resourceBindings,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'config' => $this->config,
            'secrets' => $this->secretArrays(),
            'artifacts' => $this->artifacts,
            'status' => $this->status,
            'resource_bindings' => $this->resourceBindingArrays(),
        ];
    }

    public function revision(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
}
