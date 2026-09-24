<?php

declare(strict_types=1);

namespace App\Support\Edge;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * View-model for the shared Edge index list UI — built from a local
 * {@see Site} or a Production API row so both surfaces reuse the same Blade.
 */
final readonly class EdgeIndexRow
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $manageHref,
        public bool $manageEnabled,
        public string $status,
        public string $statusLabel,
        public string $statusBadgeClass,
        public ?string $sourceLabel,
        public ?string $sourceRepo,
        public ?string $sourceBranch,
        public string $runtimeLabel,
        public ?string $frameworkLabel,
        public ?string $hostname,
        public ?string $liveUrl,
        public bool $isPreviewChild,
        public ?string $previewBranch,
        public ?int $previewPrNumber,
        public bool $canDelete,
        public bool $canQuickLook,
        public string $requestsLabel,
        public string $bandwidthLabel,
        public string $lastDeployLabel,
    ) {}

    /**
     * @param  array{requests?: int, bytes?: int, published_at?: mixed}  $stats
     */
    public static function fromSite(Site $site, bool $isPreviewChild = false, ?User $user = null, array $stats = []): self
    {
        $edgeMeta = $site->edgeMeta();
        $sourceSpec = is_array($edgeMeta['source'] ?? null) ? $edgeMeta['source'] : null;
        $buildSpec = is_array($edgeMeta['build'] ?? null) ? $edgeMeta['build'] : null;
        $framework = trim((string) ($buildSpec['framework'] ?? ''));
        $runtimeMode = (string) ($edgeMeta['runtime_mode'] ?? 'static');
        $liveUrl = $site->edgeLiveUrl();
        $hostname = is_string($liveUrl) && $liveUrl !== ''
            ? (parse_url($liveUrl, PHP_URL_HOST) ?: null)
            : null;
        $repo = is_array($sourceSpec) ? ($sourceSpec['repo'] ?? null) : null;
        $branch = is_array($sourceSpec) ? ($sourceSpec['branch'] ?? null) : null;
        $repo = is_string($repo) && $repo !== '' ? $repo : null;
        $branch = is_string($branch) && $branch !== '' ? $branch : null;
        $previewPr = $edgeMeta['preview_pr_number'] ?? null;
        $manageHref = $site->server
            ? route('sites.show', ['server' => $site->server, 'site' => $site])
            : null;

        return new self(
            id: (string) $site->id,
            name: (string) $site->name,
            manageHref: $manageHref,
            manageEnabled: $manageHref !== null,
            status: (string) $site->status,
            statusLabel: self::statusLabel((string) $site->status),
            statusBadgeClass: self::statusBadgeClass((string) $site->status),
            sourceLabel: $repo !== null ? $repo.'@'.($branch ?? 'main') : null,
            sourceRepo: $repo,
            sourceBranch: $branch,
            runtimeLabel: $runtimeMode === 'hybrid' ? __('Hybrid') : __('Static'),
            frameworkLabel: ($framework !== '' && strtolower($framework) !== 'unknown')
                ? (string) str($framework)->replace(['_', '-'], ' ')->title()
                : null,
            hostname: is_string($hostname) ? $hostname : null,
            liveUrl: is_string($liveUrl) && $liveUrl !== '' ? $liveUrl : null,
            isPreviewChild: $isPreviewChild,
            previewBranch: isset($edgeMeta['preview_branch']) && is_string($edgeMeta['preview_branch'])
                ? $edgeMeta['preview_branch']
                : null,
            previewPrNumber: is_numeric($previewPr) ? (int) $previewPr : null,
            canDelete: $user !== null && $user->can('delete', $site),
            canQuickLook: true,
            requestsLabel: Number::abbreviate((int) ($stats['requests'] ?? 0), maxPrecision: 1),
            bandwidthLabel: Number::fileSize((int) ($stats['bytes'] ?? 0), precision: 1),
            lastDeployLabel: filled($stats['published_at'] ?? null)
                ? Carbon::parse($stats['published_at'])->diffForHumans()
                : '—',
        );
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            Site::STATUS_EDGE_ACTIVE => __('Active'),
            Site::STATUS_EDGE_PROVISIONING => __('Provisioning'),
            Site::STATUS_EDGE_FAILED => __('Failed'),
            Site::STATUS_EDGE_DELETING => __('Deleting'),
            default => str_replace('_', ' ', $status !== '' ? $status : '—'),
        };
    }

    private static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            Site::STATUS_EDGE_ACTIVE => 'bg-emerald-100 text-emerald-800 dark:bg-raw-emerald-950/40 dark:text-emerald-300',
            Site::STATUS_EDGE_PROVISIONING => 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300',
            Site::STATUS_EDGE_FAILED => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
            Site::STATUS_EDGE_DELETING => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
            default => 'bg-brand-sand/60 text-brand-moss',
        };
    }
}
