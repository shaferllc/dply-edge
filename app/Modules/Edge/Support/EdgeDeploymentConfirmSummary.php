<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Structured rows for the shared confirm-action modal when rolling back
 * or promoting Edge deployments. Edge static v1 does not snapshot
 * build-time env vars yet — we surface deployment id, git refs, live
 * pointer, site build settings, and any repo-config overrides instead.
 */
final class EdgeDeploymentConfirmSummary
{
    /**
     * @return list<array{label: string, value: string, mono?: bool}>
     */
    public static function rollbackRows(Site $site, EdgeDeployment $target): array
    {
        $edge = $site->edgeMeta();
        $build = is_array($edge['build'] ?? null) ? $edge['build'] : [];
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];

        $rows = [
            [
                'label' => (string) __('Target deployment'),
                'value' => (string) $target->id,
                'mono' => true,
            ],
            [
                'label' => (string) __('Commit'),
                'value' => is_string($target->git_commit) && $target->git_commit !== ''
                    ? $target->git_commit
                    : '—',
                'mono' => true,
            ],
            [
                'label' => (string) __('Branch'),
                'value' => (string) ($target->git_branch ?? $source['branch'] ?? 'main'),
            ],
        ];

        $live = self::liveDeployment($site);
        if ($live !== null) {
            $rows[] = [
                'label' => (string) __('Current live deployment'),
                'value' => (string) $live->id,
                'mono' => true,
            ];
            $rows[] = [
                'label' => (string) __('Current live commit'),
                'value' => is_string($live->git_commit) && $live->git_commit !== ''
                    ? $live->git_commit
                    : '—',
                'mono' => true,
            ];
        }

        $envName = trim((string) ($site->deployment_environment ?? ''));
        $rows[] = [
            'label' => (string) __('Deployment environment'),
            'value' => $envName !== '' ? $envName : 'production',
        ];

        self::appendBuildConfigRows($rows, $build, $target);

        return $rows;
    }

    /**
     * @return list<array{label: string, value: string, mono?: bool}>
     */
    public static function promoteRows(Site $parent, Site $preview, EdgeDeployment $previewDeployment): array
    {
        $edge = $parent->edgeMeta();
        $build = is_array($edge['build'] ?? null) ? $edge['build'] : [];
        $previewMeta = $preview->edgeMeta();

        $rows = [
            [
                'label' => (string) __('Preview site'),
                'value' => (string) $preview->id,
                'mono' => true,
            ],
            [
                'label' => (string) __('Preview deployment'),
                'value' => (string) $previewDeployment->id,
                'mono' => true,
            ],
            [
                'label' => (string) __('Commit'),
                'value' => is_string($previewDeployment->git_commit) && $previewDeployment->git_commit !== ''
                    ? $previewDeployment->git_commit
                    : (string) ($previewMeta['preview_head_sha'] ?? '—'),
                'mono' => true,
            ],
            [
                'label' => (string) __('Branch'),
                'value' => (string) ($previewDeployment->git_branch ?? $previewMeta['preview_branch'] ?? 'main'),
            ],
        ];

        $live = self::liveDeployment($parent);
        if ($live !== null) {
            $rows[] = [
                'label' => (string) __('Production deployment (will be superseded)'),
                'value' => (string) $live->id,
                'mono' => true,
            ];
            $rows[] = [
                'label' => (string) __('Production commit'),
                'value' => is_string($live->git_commit) && $live->git_commit !== ''
                    ? $live->git_commit
                    : '—',
                'mono' => true,
            ];
        }

        $envName = trim((string) ($parent->deployment_environment ?? ''));
        $rows[] = [
            'label' => (string) __('Deployment environment'),
            'value' => $envName !== '' ? $envName : 'production',
        ];

        self::appendBuildConfigRows($rows, $build, $previewDeployment);

        return $rows;
    }

    private static function liveDeployment(Site $site): ?EdgeDeployment
    {
        $liveId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($liveId) || $liveId === '') {
            return EdgeDeployment::query()
                ->where('site_id', $site->id)
                ->where('status', EdgeDeployment::STATUS_LIVE)
                ->latest('published_at')
                ->first();
        }

        return EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->find($liveId);
    }

    /**
     * @param  list<array{label: string, value: string, mono?: bool}>  $rows
     *
     * @param-out list<array{label: string, value: string, mono?: bool}>  $rows
     *
     * @param  array<string, mixed>  $build
     */
    private static function appendBuildConfigRows(array &$rows, array $build, EdgeDeployment $deployment): void
    {
        $command = trim((string) ($build['command'] ?? ''));
        if ($command !== '') {
            $rows[] = [
                'label' => (string) __('Build command'),
                'value' => $command,
                'mono' => true,
            ];
        }

        $outputDir = trim((string) ($build['output_dir'] ?? ''));
        if ($outputDir !== '') {
            $rows[] = [
                'label' => (string) __('Output directory'),
                'value' => $outputDir,
                'mono' => true,
            ];
        }

        $repoConfig = is_array($deployment->repo_config) ? $deployment->repo_config : [];
        $repoBuild = is_array($repoConfig['build'] ?? null) ? $repoConfig['build'] : [];
        foreach ($repoBuild as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            $rows[] = [
                'label' => (string) __('Repo build :key', ['key' => (string) $key]),
                'value' => $value,
                'mono' => true,
            ];
        }

        $buildEnv = is_array($deployment->meta['build_env'] ?? null) ? $deployment->meta['build_env'] : [];
        if ($buildEnv === []) {
            $rows[] = [
                'label' => (string) __('Build env vars'),
                'value' => (string) __('None captured for this deployment'),
            ];

            return;
        }

        $keys = array_keys(array_filter($buildEnv, static fn ($v): bool => is_string($v) && $v !== ''));
        sort($keys);
        $rows[] = [
            'label' => (string) __('Build env vars'),
            'value' => implode(', ', $keys),
            'mono' => true,
        ];
    }
}
