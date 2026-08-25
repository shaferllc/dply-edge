<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services\Sources;

use App\Models\Site;
use App\Modules\Secrets\Services\Contracts\SecretSource;
use App\Modules\Secrets\Services\Scope;
use RuntimeException;

/**
 * A portable, versioned backup of ONE site's editable environment secrets — the
 * same `.env` set the site workspace surfaces.
 *
 * The wedge (docs/SECRETS_UI.md §1): "I clobbered my `.env` / rotated a key
 * wrong — restore yesterday's version." Escrowed under the site's org scope and
 * keyed per site via {@see name()}, so {@see \App\Modules\Secrets\Services\SecretVault::listVersions()}
 * for this source returns just this site's history.
 *
 * v1 bundles exactly the loose editable env (open question #2 in the design doc:
 * binding-owned credentials — DB/mail/object-storage — are intentionally NOT
 * included; the binding stays their source of truth). Encryption + storage is
 * handled by {@see SecretVault}; this only produces the plaintext bundle.
 */
final class SiteEnvBundleSource implements SecretSource
{
    public function __construct(private readonly Site $site) {}

    public function name(): string
    {
        return 'site-env-bundle-'.$this->site->id;
    }

    /**
     * The org scope this site's bundles belong to. The caller passes it to
     * {@see SecretVault::escrow()}; {@see gather()} asserts it matches so a
     * site's secrets can never be filed under another org's scope.
     */
    public function scope(): Scope
    {
        $orgId = $this->site->organization_id;
        if ($orgId === null || $orgId === '') {
            throw new RuntimeException("site {$this->site->id} has no organization — cannot scope its secret bundle.");
        }

        return Scope::org($orgId);
    }

    public function gather(Scope $scope): string
    {
        $expected = $this->scope();
        if ($scope->key !== $expected->key) {
            throw new RuntimeException("scope {$scope->key} does not match site's org scope {$expected->key}.");
        }

        // The site's OWN editable env (not effectiveEnvFileContent(), which for a
        // derived worker merges in the parent's env — a worker's backup should be
        // the worker's overrides, restored into the worker's own editor).
        $env = (string) $this->site->env_file_content;
        if (trim($env) === '') {
            throw new RuntimeException("site {$this->site->id} has no environment to back up.");
        }

        return json_encode([
            'version' => 1,
            'kind' => 'site-env-bundle',
            'site_id' => $this->site->id,
            'site_name' => $this->site->name,
            'env' => $env,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
