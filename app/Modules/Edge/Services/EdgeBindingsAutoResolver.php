<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use RuntimeException;

/**
 * Resolves wrangler.toml / dply.yaml `bindings:` values to Cloudflare resource
 * ids, creating the resource on first use.
 *
 * dply's Cloudflare account is shared by every organization (and holds the
 * platform's own buckets and namespaces), so a value is only ever one of:
 *
 *   - an id or name this organization already owns
 *     ({@see EdgeContainerConnections::owns}), used as is;
 *   - a name, read inside the organization's prefix: "cache" is
 *     `{prefix}cache`, found or created by {@see EdgeContainerConnections::ensure}
 *     (plan limits and the card rule apply there).
 *
 * `bindings.auto_create: false` means "must already exist and be ours".
 * Anything else fails the deploy with a message naming the binding.
 */
class EdgeBindingsAutoResolver
{
    /** Repo bucket => Resources connection kind. */
    private const KIND = ['kv' => 'key_value', 'r2' => 'object_storage', 'd1' => 'sql', 'queues' => 'queue'];

    /**
     * Returns a resolved bindings map (same shape as the input, with values
     * replaced by this organization's resource ids).
     *
     * @return array<string, array<string, string>>
     *
     * @throws RuntimeException when a binding points at a resource that is not the organization's
     */
    public function resolve(Site $site, EdgeDeployment $deployment): array
    {
        $config = is_array($deployment->repo_config) ? $deployment->repo_config : [];
        $declared = is_array($config['bindings'] ?? null) ? $config['bindings'] : [];
        if ($declared === []) {
            return [];
        }
        $organization = $site->organization;
        if ($organization === null) {
            throw new RuntimeException('This app has no organization to own its bindings.');
        }

        $autoCreate = ($declared['auto_create'] ?? true) !== false;

        $earlier = $this->earlierValues($site, $deployment);
        $resolved = [];
        foreach (self::KIND as $bucket => $kind) {
            $resolved[$bucket] = $this->resolveBucket($organization, $declared[$bucket] ?? null, $kind, $autoCreate, $earlier[$bucket] ?? []);
        }

        return array_filter($resolved, static fn (array $b): bool => $b !== []);
    }

    /**
     * Binding values this site's earlier deployments declared, by bucket.
     *
     * @return array<string, list<string>>
     */
    private function earlierValues(Site $site, EdgeDeployment $deployment): array
    {
        $out = [];
        $configs = EdgeDeployment::query()->where('site_id', $site->id)->whereKeyNot((string) $deployment->getKey())->whereNotNull('repo_config')->pluck('repo_config');
        foreach ($configs as $config) {
            $bindings = is_array($config) ? ($config['bindings'] ?? []) : [];
            foreach (array_keys(self::KIND) as $bucket) {
                foreach ((array) ($bindings[$bucket] ?? []) as $value) {
                    if (is_string($value)) {
                        $out[$bucket][] = trim($value);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $earlier
     * @return array<string, string>
     */
    private function resolveBucket(Organization $organization, mixed $bucket, string $kind, bool $autoCreate, array $earlier = []): array
    {
        if (! is_array($bucket) || $bucket === []) {
            return [];
        }

        $out = [];
        foreach ($bucket as $name => $value) {
            $value = is_string($value) ? trim($value) : '';
            if ($value === '') {
                continue;
            }
            try {
                $out[$name] = $this->target($kind, $value, $organization, $autoCreate, in_array($value, $earlier, true));
            } catch (\Throwable $e) {
                throw new RuntimeException(sprintf('wrangler.toml binding %s (%s): %s', $name, $value, $e->getMessage()), 0, $e);
            }
        }

        return $out;
    }

    private function target(string $kind, string $value, Organization $organization, bool $autoCreate, bool $deployedBefore = false): string
    {
        if (EdgeContainerConnections::owns($kind, $value, $organization)) {
            return $value;
        }
        if (! $autoCreate) {
            throw new RuntimeException('it is not a resource this organization owns. With auto_create off it must already exist and be yours.');
        }
        // A name typed with our own prefix still means our resource.
        $prefix = EdgeContainerConnections::ownedPrefix($organization);
        $resource = str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;
        if ($this->looksLikeId($kind, $resource)) {
            throw new RuntimeException('it is not a resource this organization owns. Use a name such as "cache" instead of an id, and dply creates it in your organization.');
        }

        [$jurisdiction, $hint] = $this->mapRegion((string) ($organization->edge_data_region ?? 'default'));

        return EdgeContainerConnections::ensure($kind, $resource, $organization, [
            'location_hint' => $hint,
            'jurisdiction' => $kind === 'object_storage' ? $jurisdiction : null,
            // Only a site that already deployed this binding can have used an
            // unprefixed resource of that name; new apps just get their own.
            'refuse_legacy' => $deployedBefore,
        ]);
    }

    /** A pasted id that is not ours is refused, not taken for a name to create. */
    private function looksLikeId(string $kind, string $value): bool
    {
        return match ($kind) {
            'key_value' => preg_match('/^[a-f0-9]{32}$/i', $value) === 1,
            'sql' => preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $value) === 1,
            default => false,
        };
    }

    /** @return array{0: ?string, 1: ?string} */
    private function mapRegion(string $region): array
    {
        return match (strtolower(trim($region))) {
            'eu', 'eu-strict' => ['eu', 'weur'],
            'wnam' => [null, 'wnam'],
            'enam' => [null, 'enam'],
            'weur' => [null, 'weur'],
            'eeur' => [null, 'eeur'],
            'apac' => [null, 'apac'],
            'oc' => [null, 'oc'],
            default => [null, null],
        };
    }
}
