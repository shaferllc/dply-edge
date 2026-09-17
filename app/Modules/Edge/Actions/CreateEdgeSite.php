<?php

declare(strict_types=1);

namespace App\Modules\Edge\Actions;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Modules\Edge\Support\EdgeOrgCredentialConfig;
use App\Modules\Edge\Support\EdgeRepoRoot;
use App\Modules\Edge\Support\EdgeSsrAvailability;
use App\Modules\Edge\Support\EdgeTestingDomains;
use Illuminate\Support\Str;
use RuntimeException;

class CreateEdgeSite
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(User $user, Organization $organization, array $payload): Site
    {
        $name = (string) ($payload['name'] ?? '');
        $slug = Str::slug($name) ?: 'edge-'.Str::random(6);
        $repo = $this->normalizeRepo((string) ($payload['repo'] ?? ''));
        if ($repo === '') {
            throw new \InvalidArgumentException('A Git repository (owner/name) is required.');
        }

        $branch = (string) ($payload['branch'] ?? 'main') ?: 'main';
        $repoRoot = EdgeRepoRoot::normalize(is_string($payload['repo_root'] ?? null) ? $payload['repo_root'] : null);
        $deployOnPush = ! array_key_exists('deploy_on_push', $payload) || (bool) $payload['deploy_on_push'];
        $buildCommand = (string) ($payload['build_command'] ?? 'npm ci && npm run build');
        $outputDir = (string) ($payload['output_dir'] ?? 'dist');
        $framework = (string) ($payload['framework'] ?? '');
        $spaFallback = ! array_key_exists('spa_fallback', $payload) || (bool) $payload['spa_fallback'];
        $runtimeMode = (string) ($payload['runtime_mode'] ?? 'static');
        if (! in_array($runtimeMode, ['static', 'hybrid', 'ssr', 'container'], true)) {
            $runtimeMode = 'static';
        }

        if (in_array($runtimeMode, ['ssr', 'container'], true)) {
            // Block before any of the per-site infra is created — once
            // the server + site rows exist the user has to tear them
            // down to recover. Failing here keeps the screen clean.
            // Availability is credential-based; the dispatch namespace id
            // is ensured via the Cloudflare API when missing from .env.
            EdgeSsrAvailability::assertAvailable();
            EdgeSsrAvailability::ensureDispatchNamespaceId();
        }

        $originConfig = null;
        if ($runtimeMode === 'hybrid') {
            $originUrl = trim((string) ($payload['origin_url'] ?? ''));
            if ($originUrl === '') {
                throw new RuntimeException('Hybrid Edge sites require an SSR origin URL.');
            }
            $cloudSiteId = $payload['cloud_site_id'] ?? null;
            if ($cloudSiteId !== null && $cloudSiteId !== '') {
                $this->assertCloudOriginSite($organization, (string) $cloudSiteId);
            }
            $originRoutes = is_array($payload['origin_routes'] ?? null) ? $payload['origin_routes'] : ['/_next/*', '/api/*'];
            $originConfig = [
                'url' => $originUrl,
                'cloud_site_id' => $cloudSiteId !== null && $cloudSiteId !== '' ? $cloudSiteId : null,
                'managed' => ! empty($payload['origin_managed']),
                'routes' => array_values(array_filter(array_map(
                    fn ($route) => is_string($route) ? $route : null,
                    $originRoutes,
                ))),
            ];
        }

        $edgeBackend = (string) ($payload['edge_backend'] ?? config('edge.default_backend', 'dply_edge'));
        $edgeCredential = $this->resolveEdgeCredential($organization, $edgeBackend, $payload);
        $hostname = $this->resolveHostname($slug, $edgeBackend, $edgeCredential);

        $server = Server::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => 'edge-'.$slug,
            'status' => Server::STATUS_READY,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DPLY_EDGE,
            ],
        ]);

        $site = Site::query()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => $slug,
            'type' => SiteType::Static,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'edge_backend' => $edgeBackend,
            'edge_provider_credential_id' => $edgeCredential?->id,
            'status' => Site::STATUS_EDGE_PROVISIONING,
            'webhook_secret' => Str::random(48),
            'meta' => [
                'runtime_profile' => 'edge_web',
                'edge' => [
                    'runtime_mode' => $runtimeMode,
                    'origin' => $originConfig,
                    'source' => array_filter([
                        'repo' => $repo,
                        'branch' => $branch,
                        'repo_root' => $repoRoot !== '' ? $repoRoot : null,
                        'deploy_on_push' => $deployOnPush,
                    ], static fn ($value) => $value !== null),
                    'import' => self::importMetaFromPayload($payload),
                    'build' => [
                        'command' => $buildCommand,
                        'output_dir' => $outputDir,
                        'framework' => $framework,
                    ],
                    'routing' => [
                        'spa_fallback' => $spaFallback,
                        'headers' => [],
                        'hostname' => $hostname,
                    ],
                    'live_url' => 'https://'.$hostname,
                ],
            ],
        ]);

        $prefix = trim((string) config('edge.r2.key_prefix', 'edge/'), '/')
            .'/'.$organization->id.'/'.$site->id.'/'.Str::ulid();

        // Optional commit pin from the create form — when ref_kind=commit
        // the form moves the SHA into git_commit + sets branch back to the
        // default, so the deployment row records the actual ref we'll
        // check out and the build runner does a full-history clone +
        // checkout instead of a shallow branch clone.
        $gitCommit = is_string($payload['git_commit'] ?? null) && $payload['git_commit'] !== ''
            ? trim($payload['git_commit'])
            : null;

        $deployment = EdgeDeployment::query()->create([
            'site_id' => $site->id,
            'organization_id' => $organization->id,
            'status' => EdgeDeployment::STATUS_BUILDING,
            'git_branch' => $branch,
            'git_commit' => $gitCommit,
            'storage_prefix' => $prefix,
        ]);

        BuildEdgeSiteJob::dispatch($deployment->id, $gitCommit);

        return $site;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>|null
     */
    private static function importMetaFromPayload(array $payload): ?array
    {
        $source = trim((string) ($payload['imported_from'] ?? ''));
        if ($source === '') {
            return null;
        }

        return array_filter([
            'source' => $source,
            'source_project_id' => trim((string) ($payload['imported_id'] ?? '')) ?: null,
            'source_dashboard_url' => trim((string) ($payload['imported_dashboard_url'] ?? '')) ?: null,
            'imported_at' => now()->toIso8601String(),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveEdgeCredential(
        Organization $organization,
        string $edgeBackend,
        array $payload,
    ): ?ProviderCredential {
        if ($edgeBackend !== 'org_cloudflare') {
            return null;
        }

        $credentialId = trim((string) ($payload['edge_provider_credential_id'] ?? ''));
        if ($credentialId === '') {
            throw new RuntimeException('Select a Cloudflare account for BYO Edge delivery.');
        }

        $credential = ProviderCredential::query()
            ->where('organization_id', $organization->id)
            ->where('provider', 'cloudflare')
            ->find($credentialId);

        if ($credential === null) {
            throw new RuntimeException('Selected Cloudflare credential was not found for this organization.');
        }

        if (! EdgeOrgCredentialConfig::isBootstrapped($credential)) {
            throw new RuntimeException(
                'Cloudflare credential is not bootstrapped for Edge. Run: php artisan dply:edge:bootstrap-org '.$credential->id,
            );
        }

        return $credential;
    }

    private function resolveHostname(string $slug, string $edgeBackend, ?ProviderCredential $credential): string
    {
        $suffix = strtolower(Str::random(6));

        if ($edgeBackend === 'org_cloudflare' && $credential instanceof ProviderCredential) {
            $edge = EdgeOrgCredentialConfig::read($credential);
            $zone = strtolower(trim((string) ($edge['worker_zone_name'] ?? '')));
            if ($zone !== '') {
                return strtolower($slug.'-'.$suffix.'.'.$zone);
            }
        }

        $testingDomain = EdgeTestingDomains::defaultApex();

        return strtolower($slug.'-'.$suffix.'.'.$testingDomain);
    }

    private function assertCloudOriginSite(Organization $organization, string $cloudSiteId): void
    {
        $originSite = Site::query()
            ->where('organization_id', $organization->id)
            ->whereIn('container_backend', ['digitalocean_app_platform', 'aws_app_runner', 'dply_cloud'])
            ->find($cloudSiteId);

        if ($originSite === null || ! $originSite->usesContainerRuntime()) {
            throw new RuntimeException('The selected Cloud origin app was not found for this organization.');
        }
    }

    private function normalizeRepo(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#i', $value, $m) === 1) {
            return $m[1];
        }

        return trim($value, '/');
    }
}
