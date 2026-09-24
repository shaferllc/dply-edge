<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\Site;
use RuntimeException;

/**
 * Shared Cloudflare publish/unpublish/domain logic for platform and BYO backends.
 */
class CloudflareEdgeDelivery
{
    public function __construct(
        private readonly EdgeArtifactPublisher $artifactPublisher,
        private readonly EdgeHostMapPublisher $hostMapPublisher,
        private readonly EdgeDeliveryContextResolver $contextResolver,
    ) {}

    /**
     * @return array{live_url: ?string, cf_kv_version: int}
     */
    public function publishDeployment(EdgeDeployment $deployment, Site $site, string $localArtifactDir): array
    {
        $context = $this->contextResolver->forSite($site);

        // A container site serves from its image, not from R2 — there is no
        // artifact directory to upload. Running the static path against one
        // failed it *after* wrangler had already deployed the container, so a
        // live deployment was recorded as failed.
        if (($site->edgeMeta()['runtime_mode'] ?? 'static') !== 'container') {
            $uploaded = $this->artifactPublisher->uploadDirectory(
                $localArtifactDir,
                $deployment->storage_prefix,
                $context->diskName,
            );
            if ($uploaded < 1) {
                throw new RuntimeException('Refusing to publish: no artifacts uploaded to R2.');
            }

            $artifactBytes = $this->artifactPublisher->directoryBytes($localArtifactDir);
            if ($artifactBytes > 0) {
                $meta = is_array($deployment->meta) ? $deployment->meta : [];
                $deployment->update([
                    'meta' => array_merge($meta, ['artifact_bytes' => $artifactBytes]),
                ]);
            }
        }

        $version = $this->hostMapPublisher->publish($site, $deployment, $context);

        return [
            'live_url' => 'https://'.$site->edgeHostname(),
            'cf_kv_version' => $version,
        ];
    }

    /**
     * @return array{live_url: ?string, cf_kv_version: int}
     */
    public function republishDeployment(EdgeDeployment $deployment, Site $site): array
    {
        $context = $this->contextResolver->forSite($site);
        $version = $this->hostMapPublisher->publish($site, $deployment, $context);

        return [
            'live_url' => 'https://'.$site->edgeHostname(),
            'cf_kv_version' => $version,
        ];
    }

    public function unpublish(Site $site): void
    {
        $context = $this->contextResolver->forSite($site);

        foreach ($site->edgeDeployments as $deployment) {
            $this->artifactPublisher->deletePrefix($deployment->storage_prefix, $context->diskName);
        }

        $this->hostMapPublisher->unpublish($site, $context);
    }

    /**
     * @return list<array{name: string, type: string, value: string, status: string}>
     */
    public function attachDomain(Site $site, string $hostname): array
    {
        $entry = app(EdgeCustomDomainProvisioner::class)->provision($site, $hostname);
        if ($entry === null) {
            throw new RuntimeException('Could not attach custom domain.');
        }

        return [
            [
                'name' => strtolower(trim($hostname)),
                'type' => 'CNAME',
                'value' => (string) ($entry['cname_target'] ?? $site->edgeHostname()),
                'status' => (string) ($entry['dns_status'] ?? 'pending'),
            ],
        ];
    }

    public function detachDomain(Site $site, string $hostname): void
    {
        app(EdgeCustomDomainProvisioner::class)->remove($site, $hostname);
    }

    public function inspect(Site $site): array
    {
        $meta = $site->edgeMeta();
        $phase = match ($site->status) {
            Site::STATUS_EDGE_ACTIVE => 'ACTIVE',
            Site::STATUS_EDGE_PROVISIONING => 'BUILDING',
            Site::STATUS_EDGE_FAILED => 'FAILED',
            default => 'UNKNOWN',
        };

        return [
            'phase' => $phase,
            'live_url' => $site->edgeLiveUrl(),
            'active_deployment_id' => is_string($meta['active_deployment_id'] ?? null) ? $meta['active_deployment_id'] : null,
        ];
    }
}
