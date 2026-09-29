<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Events\Edge\EdgeDeploymentProgressed;
use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * What the deploy pill shows: the step a deploy is on, who started it, and
 * the broadcast that moves every open pill in the organization.
 *
 * The step is read from the build log the runner already writes (the
 * `[dply:step]` markers plus a few container lines), so no build code has to
 * report progress separately. Only the step label and status go over the
 * org channel — never log text.
 */
final class EdgeDeployProgress
{
    /** In flight: the pill shows these. */
    public const IN_FLIGHT = [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING];

    /**
     * The pill's step after this chunk of build log, or null when nothing in
     * it moves the step.
     */
    public static function labelFromLog(string $chunk): ?string
    {
        $label = null;
        foreach (preg_split('/\R/', $chunk) ?: [] as $line) {
            $line = trim($line);
            $label = match (true) {
                $line === '[dply:step] clone' => 'Cloning',
                $line === '[dply:step] build' => 'Building',
                $line === '[dply:step] publish' => 'Publishing',
                preg_match('/^#\d+ pushing layers/', $line) === 1 => 'Pushing image',
                str_starts_with($line, 'Image pushed.') => 'Rolling out',
                preg_match('/^Rollout in progress — (\d+)%/u', $line, $m) === 1 => 'Rolling out '.$m[1].'%',
                str_starts_with($line, 'Checking ') && str_ends_with($line, ' answers.') => 'Checking the app answers',
                default => $label,
            };
        }

        return $label;
    }

    /**
     * Move the deployment's step when this log chunk changes it, and tell the
     * organization. An atomic JSON update: the runner holds its own copy of
     * the row and saves `meta` itself, so a model save here could undo its
     * writes (or it ours).
     */
    public static function record(string $deploymentId, string $chunk): void
    {
        $label = self::labelFromLog($chunk);
        if ($label === null) {
            return;
        }

        try {
            if (self::setMeta($deploymentId, 'progress', $label) && ($deployment = EdgeDeployment::query()->find($deploymentId)) !== null) {
                self::broadcast($deployment);
            }
        } catch (Throwable $e) {
            report($e); // Progress is a nicety; it must never fail a build.
        }
    }

    /**
     * Set one string key in a deployment's meta without a model save, and
     * say whether it changed.
     */
    public static function setMeta(string $deploymentId, string $key, string $value): bool
    {
        return DB::table('edge_deployments')
            ->where('id', $deploymentId)
            ->whereRaw('coalesce(meta->>?, \'\') <> ?', [$key, $value])
            ->update(['meta' => DB::raw('jsonb_set(coalesce(meta::jsonb, \'{}\'::jsonb), '.DB::getPdo()->quote('{'.$key.'}').', to_jsonb('.DB::getPdo()->quote($value).'::text))::json')]) > 0;
    }

    /** Tell the organization's open pages where this deploy is. */
    public static function broadcast(EdgeDeployment $deployment): void
    {
        if (! is_string($deployment->organization_id) || $deployment->organization_id === '') {
            return;
        }

        rescue(
            fn () => broadcast(new EdgeDeploymentProgressed($deployment->organization_id, self::payload($deployment))),
            report: false, // Reverb down: the pill's slow poll catches up.
        );
    }

    /**
     * The pill's view of one deployment.
     *
     * @return array<string, mixed>
     */
    public static function payload(EdgeDeployment $deployment): array
    {
        $site = $deployment->site;
        $edge = $site?->edgeMeta() ?? [];
        $preview = $site?->isEdgePreview() ?? false;
        $appId = $preview ? (string) ($edge['preview_parent_site_id'] ?? '') : (string) $deployment->site_id;
        $app = $preview ? Site::query()->find($appId, ['id', 'name']) : $site;
        $meta = is_array($deployment->meta) ? $deployment->meta : [];
        $prNumber = $edge['preview_pr_number'] ?? null;

        return [
            'id' => (string) $deployment->id,
            'site_id' => (string) $deployment->site_id,
            'app_id' => $appId,
            'app_name' => (string) ($app?->name ?? ''),
            'preview' => $preview ? (is_numeric($prNumber) ? '#'.$prNumber : (string) ($edge['preview_branch'] ?? $deployment->git_branch ?? '')) : null,
            'status' => $deployment->wasCancelledByOperator() ? 'cancelled' : (string) $deployment->status,
            'step' => self::step($deployment),
            'failure' => $deployment->status === EdgeDeployment::STATUS_FAILED ? mb_substr((string) $deployment->failure_reason, 0, 200) : null,
            'failed_step' => $deployment->status === EdgeDeployment::STATUS_FAILED ? ($meta['progress'] ?? null) : null,
            'started_at' => ($deployment->build_started_at ?? $deployment->created_at)?->toIso8601String(),
            'triggered_by' => isset($meta['triggered_by']) ? (string) $meta['triggered_by'] : null,
            'app_url' => $appId !== '' ? route('sites.show', ['site' => $appId, 'section' => 'general']) : null,
            'log_url' => route('sites.show', ['site' => $deployment->site_id, 'section' => 'logs']).'?deployment='.$deployment->id,
            'live_url' => $deployment->status === EdgeDeployment::STATUS_LIVE ? $site?->edgeLiveUrl() : null,
        ];
    }

    private static function step(EdgeDeployment $deployment): string
    {
        return match (true) {
            $deployment->wasCancelledByOperator(), $deployment->status === EdgeDeployment::STATUS_SUPERSEDED => 'Cancelled',
            $deployment->status === EdgeDeployment::STATUS_LIVE => 'Live',
            $deployment->status === EdgeDeployment::STATUS_FAILED => 'Failed',
            // Only the container deployer marks its own publish steps; static
            // and SSR builds show Building until their publish job starts.
            $deployment->status === EdgeDeployment::STATUS_PUBLISHING && in_array($deployment->meta['progress'] ?? null, [null, 'Cloning', 'Building'], true) => 'Publishing',
            default => (string) ($deployment->meta['progress'] ?? 'Queued'),
        };
    }


    /**
     * The organization's in-flight deploys this user may see, oldest first.
     * The source of truth for who sees what: the org channel reaches every
     * member, so a pill only trusts pushes for deploys listed here.
     *
     * @return list<array<string, mixed>>
     */
    public static function inFlightFor(User $user): array
    {
        $organization = $user->currentOrganization();
        if ($organization === null) {
            return [];
        }

        return EdgeDeployment::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', self::IN_FLIGHT)
            // A deploy the worker never finished must not pin a pill forever.
            ->where('created_at', '>=', now()->subHours(3))
            ->with('site')
            ->oldest()
            ->limit(50)
            ->get()
            ->filter(fn (EdgeDeployment $d): bool => $d->site !== null && Gate::forUser($user)->allows('view', $d->site))
            ->map(fn (EdgeDeployment $d): array => self::payload($d))
            ->values()
            ->all();
    }

    /**
     * The dply user behind a GitHub push: the one whose linked GitHub
     * account has the pusher's id.
     *
     * @param  array<string, mixed>  $payload  GitHub push webhook body
     */
    public static function githubPusher(array $payload): ?string
    {
        $id = $payload['sender']['id'] ?? null;
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        $userId = SocialAccount::query()
            ->where('provider', 'github')
            ->where('provider_id', (string) $id)
            ->value('user_id');

        return $userId !== null ? (string) $userId : null;
    }
}
